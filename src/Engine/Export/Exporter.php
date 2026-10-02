<?php

declare(strict_types=1);

namespace Migrator\Engine\Export;

use Migrator\Engine\Archive\Entry;
use Migrator\Engine\Archive\Manifest;
use Migrator\Engine\Archive\Writer;
use Migrator\Engine\Db\Dumper;
use Migrator\Engine\Files\FileScanner;
use Migrator\Support\Workspace;

defined('ABSPATH') || exit;

// Migrator streams large backup archives (often gigabytes) in chunks. WP_Filesystem
// reads and writes whole files into memory, which would exhaust it, so this file
// uses direct stream functions by necessity.
// phpcs:disable WordPress.WP.AlternativeFunctions

/**
 * Builds a complete archive of the current site: a manifest, a SQL dump of the
 * database, and every file under wp-content.
 *
 * This is the straight-through (synchronous) exporter used by WP-CLI, where
 * there is no request timeout to fear. The browser path will drive the same
 * pieces through a resumable step runner.
 */
final class Exporter
{
    public const DB_ENTRY = 'database.sql';

    public const ROUTINES_ENTRY = 'migrator-routines.json';

    public function __construct(
        private Workspace $workspace,
        private Dumper $dumper,
    ) {
    }

    /**
     * @param callable(string):void|null $log     Optional progress sink.
     * @param ExportOptions|null          $options What to leave out (defaults to all-in).
     *
     * @return array{path: string, tables: int, files: int, bytes: int, warnings: list<string>}
     */
    public function export(string $destination, ?callable $log = null, ?ExportOptions $options = null): array
    {
        $log ??= static function (string $m): void {};
        $options ??= new ExportOptions();

        // Built under a .part name and renamed at the end, so the backups list
        // and retention never see an archive that is still being written.
        $working = $destination . ExportPipeline::PART;
        $writer  = new Writer($working);

        // 1. Manifest (first entry), recording the dumped table list for the importer.
        $tables = $options->excludeDatabase() ? [] : $this->dumper->tables();
        $skip   = $options->tablesToSkip($this->dumper->prefix());
        $tables = array_values(array_diff($tables, $skip));

        $manifest = Manifest::forThisSite((string) \Migrator\VERSION, [
            'tables'   => $tables,
            'excludes' => $options->toArray(),
        ]);
        $writer->addString(Manifest::NAME, $manifest->toJson(), Entry::TYPE_MANIFEST);
        $log(sprintf('Manifest written (%d tables).', count($tables)));

        // 2. Database dump, generated to a temp file, then streamed into the
        //    archive (so the entry size is known without buffering it in memory).
        if (! $options->excludeDatabase()) {
            $sqlTmp = $this->workspace->path('tmp-' . wp_generate_password(8, false) . '.sql');
            $handle = fopen($sqlTmp, 'wb');
            if (false === $handle) {
                $writer->close();
                throw new \RuntimeException('Migrator: cannot open temp file for SQL dump.');
            }
            try {
                $this->dumper->dumpAll($tables, $handle, $options->whereFilters($this->dumper->prefix()), $skip);
            } finally {
                fclose($handle);
            }
            $writer->addFile(self::DB_ENTRY, $sqlTmp);
            $dbBytes = (int) filesize($sqlTmp);
            wp_delete_file($sqlTmp);
            $log(sprintf('Database dumped (%s).', size_format($dbBytes)));

            $routines = $this->dumper->routines();
            if ([] !== $routines) {
                $writer->addString(self::ROUTINES_ENTRY, (string) wp_json_encode($routines));
                $log(sprintf('Triggers and routines: %d.', count($routines)));
            }
        }

        // 3. Files under wp-content (skipping the backups workspace, dev junk, and
        //    anything the export options exclude).
        $contentDir = untrailingslashit((string) WP_CONTENT_DIR);
        // The archive itself is excluded too: `wp migrator export --output`
        // pointed inside wp-content otherwise copied the growing archive into
        // itself.
        $scanner = new FileScanner(
            ['node_modules', '.git', '.DS_Store'],
            array_merge([$this->workspace->path(), $working, $destination], $options->fileExcludePaths()),
        );

        $fileCount = 0;
        $fileBytes = 0;
        $warnings  = [];
        foreach ($scanner->scan($contentDir) as $file) {
            if (! is_readable($file['abs'])) {
                $warnings[] = sprintf('wp-content/%s is not readable and was left out.', $file['rel']);
                continue;
            }
            $writer->addFile('wp-content/' . $file['rel'], $file['abs']);
            $fileCount++;
            $fileBytes += $file['size'];
        }
        $log(sprintf('Files archived: %d (%s).', $fileCount, size_format($fileBytes)));

        $writer->finish();
        $warnings = array_merge($warnings, $writer->warnings());
        foreach ($warnings as $warning) {
            $log('Warning: ' . $warning);
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
        if (! rename($working, $destination)) {
            throw new \RuntimeException(esc_html('Migrator: the archive was written but could not be renamed to ' . $destination));
        }

        return [
            'path'     => $destination,
            'tables'   => count($tables),
            'files'    => $fileCount,
            'bytes'    => (int) filesize($destination),
            'warnings' => $warnings,
        ];
    }

    /**
     * Default archive path inside the workspace, stamped with the date.
     */
    public function defaultDestination(): string
    {
        $host = (string) wp_parse_url((string) get_option('home'), PHP_URL_HOST);
        $host = preg_replace('/[^a-z0-9.-]/i', '', $host) ?: 'site';

        // Random token so backups are never at a guessable URL on hosts that
        // ignore the .htaccess deny (nginx).
        return $this->workspace->path(
            sprintf('%s-%s-%s.migrator', $host, gmdate('Ymd-His'), wp_generate_password(8, false))
        );
    }
}
