<?php

declare(strict_types=1);

namespace Migrator\Engine\Import;

use Migrator\Engine\Archive\Manifest;
use Migrator\Engine\Archive\Reader;
use Migrator\Engine\Db\Dumper;
use Migrator\Engine\Db\SearchReplace;
use Migrator\Engine\Db\SqlExecutor;
use Migrator\Engine\Export\Exporter;
use Migrator\Engine\Transform\SerializedReplacer;
use Migrator\Support\Workspace;

defined('ABSPATH') || exit;

// Migrator streams large backup archives (often gigabytes) in chunks. WP_Filesystem
// reads and writes whole files into memory, which would exhaust it, so this file
// uses direct stream functions by necessity.
// phpcs:disable WordPress.WP.AlternativeFunctions

/**
 * Restores an archive onto the current site.
 *
 * The order matters: read the manifest, capture *this* site's URLs and paths
 * BEFORE the import (the import overwrites wp_options with the source's values),
 * import the SQL, then rewrite the source's URLs/paths to this site's with a
 * serialization-safe pass. Files are extracted last.
 *
 * This is the straight-through (WP-CLI) importer. It never extracts over its own
 * plugin directory or the backups folder, so it cannot clobber the code that is
 * currently running.
 */
final class Importer
{
    private const PROTECTED_PREFIXES = [
        'wp-content/plugins/migrator/',
        'wp-content/plugins/migrator-pro/',
        'wp-content/migrator-backups/',
    ];

    public function __construct(
        private Workspace $workspace,
        private \wpdb $db,
    ) {
    }

    /**
     * @param callable(string):void|null $log
     *
     * @return array{tables: int, statements: int, replaced: int, files: int}
     */
    public function import(string $archivePath, bool $importFiles = true, ?callable $log = null): array
    {
        $log ??= static function (string $m): void {};

        $reader = new Reader($archivePath);

        $first = $reader->nextEntry();
        if (null === $first || ! $first->isManifest()) {
            throw new \RuntimeException('Migrator: archive has no manifest (is this a Migrator archive?).');
        }
        $manifest = Manifest::fromJson($reader->readContents());
        if (! $manifest->isSupported()) {
            throw new \RuntimeException('Migrator: this archive was made by a newer version of Migrator.');
        }

        // The dump uses the source's literal table names. If this site's prefix
        // differs, the imported tables would not be the ones WordPress reads,
        // leaving a silently broken site — so refuse rather than corrupt.
        $sourcePrefix = (string) $manifest->get('tablePrefix');
        if ('' !== $sourcePrefix && $sourcePrefix !== $this->db->prefix) {
            throw new \RuntimeException(esc_html(sprintf(
                'Migrator: table prefix mismatch. This archive uses "%1$s" but this site uses "%2$s". Set this site\'s $table_prefix to "%1$s" in wp-config.php and try again.',
                $sourcePrefix,
                $this->db->prefix
            )));
        }

        // Multisite has its own table layout and URL handling; importing across a
        // single-site/multisite boundary silently corrupts. Refuse for now.
        if ((bool) $manifest->get('multisite') || is_multisite()) {
            throw new \RuntimeException('Migrator: multisite backups are not supported yet. This archive or this site is a multisite network.');
        }

        // Capture the target's identity BEFORE the DB import overwrites it.
        $target = [
            'home'    => (string) get_option('home'),
            'siteurl' => (string) get_option('siteurl'),
            'content' => untrailingslashit((string) WP_CONTENT_DIR),
            'abspath' => untrailingslashit((string) ABSPATH),
        ];
        $source = [
            'home'    => (string) $manifest->get('homeUrl'),
            'siteurl' => (string) $manifest->get('siteUrl'),
            'content' => (string) $manifest->get('contentDir'),
            'abspath' => (string) $manifest->get('abspath'),
        ];

        $statements = 0;
        $replaced   = 0;
        $tablesRepl = 0;
        $files      = 0;

        while (($entry = $reader->nextEntry()) !== null) {
            if (Exporter::DB_ENTRY === $entry->path) {
                // Safety net: snapshot the current database so a failed import
                // (DDL auto-commits, so DROP/CREATE cannot be transaction-rolled
                // back) can be reverted instead of leaving a dead site.
                $rollback = $this->backupDatabase($log);

                try {
                    $statements = $this->importDatabase($reader, $log);

                    [$from, $to] = $this->replacements($source, $target);
                    if ([] !== $from) {
                        /** @var string[] $tables */
                        $tables     = array_map('strval', (array) $manifest->get('tables'));
                        $search     = new SearchReplace($this->db, new SerializedReplacer($from, $to));
                        $result     = $search->run($tables);
                        $replaced   = $result['changes'];
                        $tablesRepl = $result['tables'];
                        $log(sprintf('Rewrote URLs/paths in %d rows across %d tables.', $replaced, $tablesRepl));
                    }
                } catch (\Throwable $e) {
                    $log('Import failed — restoring the previous database…');
                    $this->restoreDatabase($rollback);
                    $reader->close();
                    throw new \RuntimeException(esc_html(
                        'Migrator: import failed and the database was rolled back to its previous state. ' . $e->getMessage()
                    ));
                }

                wp_delete_file($rollback);
            } elseif (Exporter::ROUTINES_ENTRY === $entry->path) {
                $this->importRoutines($reader->readContents(), $log);
            } elseif (str_starts_with($entry->path, 'wp-content/')) {
                if ($importFiles && $this->extract($entry->path, $reader)) {
                    $files++;
                } else {
                    $reader->skip();
                }
            } else {
                $reader->skip();
            }
        }

        $reader->close();
        wp_cache_flush();

        return [
            'tables'     => $tablesRepl,
            'statements' => $statements,
            'replaced'   => $replaced,
            'files'      => $files,
        ];
    }

    /**
     * Dump the current database to a rollback file before the import touches it.
     */
    private function backupDatabase(callable $log): string
    {
        $path   = $this->workspace->path('rollback-' . gmdate('Ymd-His') . '-' . wp_generate_password(6, false) . '.sql');
        $handle = fopen($path, 'wb');
        if (false === $handle) {
            throw new \RuntimeException('Migrator: cannot create the pre-import safety backup.');
        }
        $dumper = new Dumper($this->db);
        $dumper->dumpAll($dumper->tables(), $handle);
        fclose($handle);

        $log('Safety backup of the current database created.');

        return $path;
    }

    /**
     * Best-effort restore of the rollback dump after a failed import.
     */
    private function restoreDatabase(string $path): void
    {
        if (! is_readable($path)) {
            return;
        }
        try {
            (new SqlExecutor($this->db))->runFile($path);
        } catch (\Throwable $e) {
            // Nothing more we can safely do; the rollback file is kept for manual recovery.
            return;
        }
        wp_delete_file($path);
    }

    private function importDatabase(Reader $reader, callable $log): int
    {
        $tmp    = $this->workspace->path('import-' . wp_generate_password(8, false) . '.sql');
        $handle = fopen($tmp, 'wb');
        if (false === $handle) {
            throw new \RuntimeException('Migrator: cannot open temp file for SQL import.');
        }
        $reader->streamTo(static function (string $chunk) use ($handle): void {
            fwrite($handle, $chunk);
        });
        fclose($handle);

        // Stream the temp file statement-by-statement — never load the whole
        // dump into memory.
        $count = (new SqlExecutor($this->db))->runFile($tmp);
        wp_delete_file($tmp);

        $log(sprintf('Imported database (%d statements).', $count));

        return $count;
    }

    /**
     * Recreate triggers and stored routines from the routines entry. Each create
     * is a single statement, so it runs whole with no DELIMITER handling.
     * Best-effort: a routine that cannot be created (e.g. lacking privilege) is
     * skipped rather than failing the whole restore.
     */
    private function importRoutines(string $json, callable $log): void
    {
        $routines = json_decode($json, true);
        if (! is_array($routines)) {
            return;
        }

        $count = 0;
        foreach ($routines as $routine) {
            if (! is_array($routine) || ! isset($routine['create'])) {
                continue;
            }
            if (isset($routine['drop'])) {
                // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
                $this->db->query((string) $routine['drop']);
            }
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
            if (false !== $this->db->query((string) $routine['create'])) {
                $count++;
            }
        }

        if ($count > 0) {
            $log(sprintf('Recreated %d triggers and routines.', $count));
        }
    }

    /**
     * @return bool True if the file was written, false if it was skipped.
     */
    private function extract(string $archivePath, Reader $reader): bool
    {
        foreach (self::PROTECTED_PREFIXES as $prefix) {
            if (str_starts_with($archivePath, $prefix)) {
                return false;
            }
        }

        $relative = substr($archivePath, strlen('wp-content/'));

        // Zip-slip guard: reject any entry that tries to escape wp-content via
        // "../" or an absolute path in the archived path.
        if ('' === $relative || str_contains($relative, '..') || str_starts_with($relative, '/')) {
            return false;
        }

        $base   = untrailingslashit((string) WP_CONTENT_DIR);
        $target = $base . '/' . $relative;

        $dir = dirname($target);
        if (! is_dir($dir)) {
            wp_mkdir_p($dir);
        }

        // Confirm the resolved directory really sits inside wp-content.
        $realDir  = realpath($dir);
        $realBase = realpath($base);
        if (false === $realDir || false === $realBase || ! str_starts_with($realDir . '/', $realBase . '/')) {
            return false;
        }

        $handle = fopen($target, 'wb');
        if (false === $handle) {
            return false;
        }
        $reader->streamTo(static function (string $chunk) use ($handle): void {
            fwrite($handle, $chunk);
        });
        fclose($handle);

        return true;
    }

    /**
     * Build ordered from/to replacement pairs. Longer paths first so a parent
     * path never partially rewrites a child.
     *
     * @param array{home:string,siteurl:string,content:string,abspath:string} $source
     * @param array{home:string,siteurl:string,content:string,abspath:string} $target
     *
     * @return array{0: string[], 1: string[]}
     */
    private function replacements(array $source, array $target): array
    {
        $from = [];
        $to   = [];
        foreach (['home', 'siteurl', 'content', 'abspath'] as $key) {
            if ('' !== $source[$key] && $source[$key] !== $target[$key]) {
                $from[] = $source[$key];
                $to[]   = $target[$key];
            }
        }

        return [$from, $to];
    }
}
