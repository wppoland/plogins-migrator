<?php

declare(strict_types=1);

namespace Migrator\Engine\Import;

use Migrator\Engine\Archive\Compressor;
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
    /**
     * Archive paths a restore must never write over, derived rather than typed.
     *
     * These used to be three hardcoded strings, and two of them were the folder
     * names this plugin had before it was renamed. The archive stores entries as
     * `wp-content/<rel>`, so on a real install the plugin's own files arrive as
     * `wp-content/plugins/plogins-migrator/...` and matched none of them. The
     * guard the class docblock promises has therefore never fired, and a restore
     * has been free to extract an older copy of the plugin over the code running
     * the restore.
     *
     * @return list<string>
     */
    private function protectedPrefixes(): array
    {
        $prefixes = ['wp-content/' . Workspace::DIR_NAME . '/'];

        foreach (['Migrator\\PLUGIN_FILE', 'Migrator\\Pro\\PLUGIN_FILE'] as $constant) {
            $file = defined($constant) ? constant($constant) : null;

            if (! is_string($file) || '' === $file) {
                continue;
            }

            $dir = dirname(plugin_basename($file));
            if ('' !== $dir && '.' !== $dir) {
                $prefixes[] = 'wp-content/plugins/' . $dir . '/';
            }
        }

        return $prefixes;
    }

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

        // A gzip-compressed archive is expanded to a temp file first; the rest of
        // the import is unchanged and the temp is always cleaned up.
        $temp = null;
        if (Compressor::isCompressed($archivePath)) {
            $this->workspace->ensure();
            $temp = $this->workspace->path('decompress-' . wp_generate_password(8, false) . '.migrator');
            (new Compressor())->decompress($archivePath, $temp);
            $archivePath = $temp;
        }

        try {
            return $this->runImport($archivePath, $importFiles, $log);
        } finally {
            if (null !== $temp) {
                wp_delete_file($temp);
            }
        }
    }

    /**
     * @param callable(string):void $log
     * @return array{tables: int, statements: int, replaced: int, files: int}
     */
    private function runImport(string $archivePath, bool $importFiles, callable $log): array
    {
        // An archive cut short while it was being written is otherwise found out
        // part way through the restore, which is too late: the database entry
        // comes before the files, so by then it has been replaced. The end marker
        // is four bytes at the tail of a finished archive, so ask for it now,
        // while refusing still costs the site nothing.
        if (! Reader::endsWithMarker($archivePath)) {
            throw new \RuntimeException(
                'Migrator: this archive was never finished, so it is not a complete backup. The run that wrote it '
                . 'was cut short (execution time, memory, or a full disk). Nothing has been imported, this site is '
                . 'untouched. Restore from a backup that finished.'
            );
        }

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
        // leaving a silently broken site, so refuse rather than corrupt.
        $sourcePrefix = (string) $manifest->get('tablePrefix');
        if ('' !== $sourcePrefix && $sourcePrefix !== $this->db->prefix) {
            throw new \RuntimeException(esc_html(sprintf(
                'Migrator: table prefix mismatch. This archive uses "%1$s" but this site uses "%2$s". Set this site\'s $table_prefix to "%1$s" in wp-config.php and try again.',
                $sourcePrefix,
                $this->db->prefix
            )));
        }

        // Multisite has its own table layout and URL handling; importing across a
        // single-site/multisite boundary silently corrupts. A companion add-on can
        // declare support for a matched network-to-network restore; otherwise refuse.
        $archiveMultisite = (bool) $manifest->get('multisite');
        if ($archiveMultisite || is_multisite()) {
            /**
             * Filters whether this multisite restore is supported by a handler.
             *
             * @param bool $supported       Default false (core refuses multisite).
             * @param bool $archiveMultisite Whether the archive is a network backup.
             * @param bool $siteMultisite    Whether this site is a network.
             */
            $supported = (bool) apply_filters('migrator/multisite_supported', false, $archiveMultisite, is_multisite());
            if (! $supported) {
                // States the limitation without naming a paid edition. The
                // network rewrite this restore needs (wp_blogs and wp_site
                // domains and paths) is genuinely not in this package, so
                // refusing is honest, but a free plugin's own code should
                // not read as an upsell in a thrown exception.
                throw new \RuntimeException('Migrator: this restore crosses a multisite boundary. Restoring a network backup rewrites the network tables to the destination domain, which this plugin does not do, so the import was stopped rather than left half applied.');
            }
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

        // The dump of the database as it was, kept until the WHOLE restore is
        // through. Deleting it the moment the SQL was in left anything that
        // failed later (a checksum, a truncation the tail check cannot see)
        // standing on a replaced database with nothing to go back to.
        $replacedDbDump = null;

        try {
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

                        /**
                         * Fires after the database is imported and the standard
                         * URL/path rewrite has run, while the safety backup is still
                         * in place. A handler that throws triggers the rollback. Used
                         * by the Pro add-on to fix up multisite network tables
                         * (wp_blogs / wp_site) that hold bare host names the URL
                         * rewrite cannot reach.
                         *
                         * @param array{source: array, target: array} $context Source and target identities.
                         * @param Manifest                            $manifest The archive manifest.
                         * @param \wpdb                               $db       The database handle.
                         */
                        do_action('migrator/after_database_import', ['source' => $source, 'target' => $target], $manifest, $this->db);
                    } catch (\Throwable $e) {
                        $log('Import failed, restoring the previous database…');
                        $restored = $this->restoreDatabase($rollback);
                        $reader->close();
                        if (! $restored) {
                            $log('The rollback did not complete. The previous database is in ' . $rollback);

                            throw new \RuntimeException(esc_html(
                                'Migrator: the import failed AND the rollback failed, so the database is in a partly imported state. '
                                . 'The dump of the previous database is kept at ' . $rollback . ' and must be restored by hand. '
                                . $e->getMessage()
                            ));
                        }

                        throw new \RuntimeException(esc_html(
                            'Migrator: import failed and the database was rolled back to its previous state. ' . $e->getMessage()
                        ));
                    }

                    $replacedDbDump = $rollback;
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
        } catch (\Throwable $e) {
            $reader->close();

            if (null === $replacedDbDump) {
                throw $e;
            }

            // The database is already the archive's and the files got only as
            // far as the read did. Rolling the database back on its own would
            // pair the old database with the new files that were written before
            // the stop, so state what the site is standing on and hand over the
            // dump that makes either choice possible.
            throw new \RuntimeException(esc_html(sprintf(
                'Migrator: the restore stopped after the database had already been replaced, so this site is now part restored: '
                . 'the database is the one from the archive, %1$d files came across in full, the file it stopped on may be part '
                . 'written, and the rest of the archive was not read. The database as it was before is dumped at %2$s: import '
                . 'that file to put the database back, or restore again from a backup that finished. Do one of the two before '
                . 'letting visitors in. %3$s',
                $files,
                $replacedDbDump,
                $e->getMessage()
            )));
        }

        if (null !== $replacedDbDump) {
            wp_delete_file($replacedDbDump);
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
     * Restore the rollback dump after a failed import.
     *
     * Returns false when the database is NOT back to its previous state. The
     * caller has to say which of the two happened: "rolled back" and "half
     * imported, rollback also failed" are different emergencies, and telling a
     * merchant the first when the second is true sends them away from a site
     * that needs them.
     */
    private function restoreDatabase(string $path): bool
    {
        if (! is_readable($path)) {
            return false;
        }
        try {
            (new SqlExecutor($this->db))->runFile($path);
        } catch (\Throwable $e) {
            // Keep the rollback file: it is now the only copy of the previous
            // database, and manual recovery needs it.
            return false;
        }
        wp_delete_file($path);

        return true;
    }

    private function importDatabase(Reader $reader, callable $log): int
    {
        $tmp    = $this->workspace->path('import-' . wp_generate_password(8, false) . '.sql');
        $handle = fopen($tmp, 'wb');
        if (false === $handle) {
            throw new \RuntimeException('Migrator: cannot open temp file for SQL import.');
        }
        // A discarded fwrite() return is how a restore silently truncates. On a
        // disk that fills up mid-stream, fwrite writes what fits and reports the
        // short count; the SQL file then ends at a chunk boundary and executes
        // cleanly up to that point, so the merchant is told the import succeeded
        // while the tail of their database was never written.
        $reader->streamTo(static function (string $chunk) use ($handle, $tmp): void {
            $written = fwrite($handle, $chunk);
            if (false === $written || $written < strlen($chunk)) {
                fclose($handle);

                throw new \RuntimeException(esc_html(
                    'Migrator: could not write the whole SQL dump to ' . $tmp
                    . '. The disk is most likely full. Nothing has been imported.'
                ));
            }
        });
        fclose($handle);

        // Stream the temp file statement-by-statement, never load the whole
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
        foreach ($this->protectedPrefixes() as $prefix) {
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
        // Same trap as the SQL stream: a short write leaves a truncated file on
        // disk and this used to return true for it, so a half-written image,
        // theme file or PHP file was restored and reported as a success.
        $short = false;
        $reader->streamTo(static function (string $chunk) use ($handle, &$short): void {
            $written = fwrite($handle, $chunk);
            if (false === $written || $written < strlen($chunk)) {
                $short = true;
            }
        });
        fclose($handle);

        if ($short) {
            wp_delete_file($target);

            return false;
        }

        return true;
    }

    /**
     * Build ordered from/to replacement pairs. Longer paths first so a parent
     * path never partially rewrites a child.
     *
     * A source's home and siteurl hold the same string on almost every site, and
     * the pairs are applied in order, so listing it twice would rewrite a value
     * that was already rewritten: importing into a subdirectory would leave
     * https://new/sub/sub. Each distinct source appears once.
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
            if ('' !== $source[$key] && $source[$key] !== $target[$key] && ! in_array($source[$key], $from, true)) {
                $from[] = $source[$key];
                $to[]   = $target[$key];
            }
        }

        foreach ($this->schemelessPairs($from, $to) as $old => $new) {
            $from[] = $old;
            $to[]   = $new;
        }

        return [$from, $to];
    }

    /**
     * Scheme-relative leftovers: "//old.example/wp-content/..." is a real URL in
     * the wild (PeepSo caches its reaction icons that way) and none of the pairs
     * above match it, because they all carry a scheme. Dropping the scheme also
     * catches a mixed-scheme site, "http://old" contains "//old", so one pair
     * rewrites the host and leaves whatever scheme was there.
     *
     * These are appended, never prepended: str_replace applies pairs in order,
     * so by the time "//old" runs, every full URL has already become "//new" and
     * only the genuinely scheme-less occurrences are still there to match.
     *
     * That ordering has one hole. When the old host is a *prefix* of the new one
     * (old-host.t moving to old-host.test), "//old-host.t" still matches the
     * "//old-host.test" an earlier pair just wrote, and the value is rewritten
     * twice into old-host.testest. Such a pair is dropped: a scheme-relative URL
     * left pointing at the old host is recoverable, a mangled one is not.
     * ponytail: fixing that case properly needs a single-pass replacer instead of
     * sequential str_replace, worth doing only if a real move hits it.
     *
     * @param string[] $from
     * @param string[] $to
     *
     * @return array<string, string> old scheme-less prefix => new one
     */
    private function schemelessPairs(array $from, array $to): array
    {
        $pairs = [];
        foreach ($from as $i => $url) {
            $old = $this->schemeless($url);
            $new = $this->schemeless($to[$i] ?? '');
            if (null === $old || null === $new || $old === $new) {
                continue;
            }
            // Never shadow a full-URL pair, and keep the first mapping for a host.
            if (in_array($old, $from, true) || isset($pairs[$old])) {
                continue;
            }
            // Would re-match something an earlier pair already wrote.
            foreach ($to as $written) {
                if (str_contains($written, $old)) {
                    continue 2;
                }
            }
            $pairs[$old] = $new;
        }

        return $pairs;
    }

    /**
     * Strip the scheme from a URL, keeping the leading "//". Returns null for a
     * value that is not a URL at all (abspath is a filesystem path).
     */
    private function schemeless(string $url): ?string
    {
        $pos = strpos($url, '://');

        return false === $pos ? null : substr($url, $pos + 1);
    }
}
