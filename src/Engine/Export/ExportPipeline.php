<?php

declare(strict_types=1);

namespace Migrator\Engine\Export;

use Migrator\Engine\Archive\Entry;
use Migrator\Engine\Archive\Manifest;
use Migrator\Engine\Archive\Writer;
use Migrator\Engine\Db\Dumper;
use Migrator\Engine\Files\FileScanner;
use Migrator\Engine\Pipeline\TimeBudget;
use Migrator\Support\Workspace;

defined('ABSPATH') || exit;

// Migrator streams large backup archives (often gigabytes) in chunks. WP_Filesystem
// reads and writes whole files into memory, which would exhaust it, so this file
// uses direct stream functions by necessity.
// phpcs:disable WordPress.WP.AlternativeFunctions

/**
 * Resumable export for the browser. Unlike the straight-through CLI exporter,
 * this runs in time-boxed slices so it survives the request timeout on large
 * sites:
 *
 *   start()  > write signature and manifest; create file list; initialize
 *              the stepped database dump.
 *   step()   > during the 'db' phase, dump table rows within time budget;
 *              once all tables are dumped, append the database entry to the
 *              archive and transition to 'files';
 *              during the 'files' phase, append a time-boxed batch of files;
 *              when the list is exhausted, write the end marker and finish.
 *
 * Each appended entry is complete and checksummed, so an interrupted export
 * simply resumes at the next chunk or file. Job state lives in one option.
 */
final class ExportPipeline
{
    public const JOB_OPTION = 'migrator_export_job';

    /**
     * Files appended per step. Caps a single request even when the server is fast
     * enough to do far more within the time budget, so the progress bar advances
     * in visible increments rather than one jump from 0 to 100.
     */
    private const FILES_PER_STEP = 1500;

    /**
     * A running job whose last step is older than this is taken to be dead (the
     * tab was closed, the request was killed) and may be replaced.
     */
    private const STALE_AFTER = 10 * MINUTE_IN_SECONDS;

    /** Warnings kept on the job, so a site full of unreadable files cannot bloat the option. */
    private const MAX_WARNINGS = 50;

    /** Suffix of an archive still being written; nothing lists or restores it. */
    public const PART = '.part';

    public function __construct(
        private Workspace $workspace,
        private Dumper $dumper,
    ) {
    }

    /**
     * Begin an export. Writes the manifest, prepares the database dump, and
     * enumerates the files to copy. Returns the initial job state.
     *
     * @return array<string, mixed>
     */
    public function start(?ExportOptions $options = null): array
    {
        $options ??= new ExportOptions();
        $final       = $this->destination();
        // Written under a .part name and renamed when finished, so the backups
        // list never offers an archive a step is still appending to.
        $destination = $final . self::PART;

        $writer = new Writer($destination);

        $prefix = $this->dumper->prefix();
        $skip   = $options->tablesToSkip($prefix);
        $tables = $options->excludeDatabase() ? [] : array_values(array_diff($this->dumper->tables(), $skip));

        $manifest = Manifest::forThisSite((string) \Migrator\VERSION, [
            'tables'   => $tables,
            'excludes' => $options->toArray(),
        ]);
        $writer->addString(Manifest::NAME, $manifest->toJson(), Entry::TYPE_MANIFEST);

        $sqlTmp = null;
        if (! $options->excludeDatabase()) {
            $sqlTmp = $this->workspace->path('tmp-' . wp_generate_password(8, false) . '.sql');
            $handle = fopen($sqlTmp, 'wb');
            if (false === $handle) {
                $writer->close();
                throw new \RuntimeException('Migrator: cannot open temp file for SQL dump.');
            }
            try {
                $this->dumper->dumpHeader($handle);
            } finally {
                fclose($handle);
            }
        }

        // Enumerate files to a list the steps walk by index.
        $listPath = $this->workspace->path('job-' . wp_generate_password(8, false) . '.list');
        $list     = fopen($listPath, 'wb');
        if (false === $list) {
            $writer->close();
            if (null !== $sqlTmp && is_file($sqlTmp)) {
                wp_delete_file($sqlTmp);
            }
            throw new \RuntimeException('Migrator: cannot open file list.');
        }
        $total = 0;
        foreach ($this->scanner($options)->scan($this->contentDir()) as $file) {
            $line = $file['rel'] . "\n";
            // The list IS the backup's contents. A short write here drops files
            // from it while $total still counts them, so the job reports a file
            // count the archive does not contain and nobody finds out until a
            // restore is missing something.
            if (fwrite($list, $line) !== strlen($line)) {
                fclose($list);
                $writer->close();
                if (null !== $sqlTmp && is_file($sqlTmp)) {
                    wp_delete_file($sqlTmp);
                }

                throw new \RuntimeException('Migrator: could not write the file list, the disk is most likely full.');
            }
            $total++;
        }
        fclose($list);

        $writer->close(); // Leave open-ended; steps append and finish.

        $phase = $options->excludeDatabase() ? 'files' : 'db';

        $job = [
            'id'             => wp_generate_password(12, false),
            'dest'           => $destination,
            'final'          => $final,
            'warnings'       => array_merge($this->uploadsWarning($options), $writer->warnings()),
            'list'           => $listPath,
            'index'          => 0,
            'total'          => $total,
            'bytes'          => (int) filesize($destination), // clean archive size after the last completed step
            'listOffset'     => 0,                            // byte offset into the file list
            'status'         => 'running',
            'startedAt'      => time(),
            'phase'          => $phase,
            'db_table_index' => 0,
            'db_cursor'      => null,
            'db_tmp'         => $sqlTmp,
            'db_tables'      => $tables,
            'db_skip'        => $skip,
            'db_where'       => $options->whereFilters($prefix),
            'db_rows'        => array_intersect_key($this->dumper->rowEstimates(), array_flip($tables)),
            'has_db'         => ! $options->excludeDatabase(),
        ];
        if (null !== $sqlTmp && is_file($sqlTmp)) {
            $job['db_bytes'] = (int) filesize($sqlTmp);
        }
        $this->save($job);

        return $job;
    }

    /**
     * Run the next time-boxed slice of the export.
     *
     * @return array<string, mixed>
     */
    public function step(): array
    {
        $job = $this->current();
        if ([] === $job || 'running' !== ($job['status'] ?? '')) {
            throw new \RuntimeException('Migrator: no export in progress.');
        }

        $budget = TimeBudget::forRequest();
        $phase  = (string) ($job['phase'] ?? 'files');

        if ('db' === $phase) {
            return $this->stepDb($job, $budget);
        }

        return $this->stepFiles($job, $budget);
    }

    /**
     * Dump database table rows within the request time budget.
     *
     * @param array<string, mixed> $job
     * @return array<string, mixed>
     */
    private function stepDb(array $job, TimeBudget $budget): array
    {
        $dest        = (string) $job['dest'];
        $clean       = (int) ($job['bytes'] ?? filesize($dest));
        $sqlTmp      = (string) ($job['db_tmp'] ?? '');
        $tables      = (array) ($job['db_tables'] ?? []);
        $totalTables = count($tables);
        $skip        = (array) ($job['db_skip'] ?? []);
        $where       = (array) ($job['db_where'] ?? []);
        $tableIndex  = (int) ($job['db_table_index'] ?? 0);
        $cursor      = isset($job['db_cursor']) && is_array($job['db_cursor']) ? $job['db_cursor'] : null;

        // If a previous step died mid-file, the archive has a partial entry past
        // its last clean size. Truncate it away before appending more.
        clearstatcache(true, $dest);
        if ((int) filesize($dest) > $clean) {
            $trunc = fopen($dest, 'r+b');
            if (false === $trunc || ! ftruncate($trunc, $clean)) {
                if (false !== $trunc) {
                    fclose($trunc);
                }
                throw new \RuntimeException('Migrator: could not cut a half-written entry off the archive, so the export cannot continue. Start a new backup.');
            }
            fclose($trunc);
        }

        if ('' === $sqlTmp || ! is_file($sqlTmp)) {
            throw new \RuntimeException('Migrator: the export\'s temporary SQL dump is gone, so it cannot continue. Start a new backup.');
        }

        // Same for the SQL dump: rows past the last saved cursor were written
        // by a step that died before saving it, and would be dumped twice.
        clearstatcache(true, $sqlTmp);
        $cleanDb = (int) ($job['db_bytes'] ?? filesize($sqlTmp));
        if ((int) filesize($sqlTmp) > $cleanDb) {
            $trunc = fopen($sqlTmp, 'r+b');
            if (false === $trunc || ! ftruncate($trunc, $cleanDb)) {
                if (false !== $trunc) {
                    fclose($trunc);
                }
                throw new \RuntimeException('Migrator: could not cut half-written rows off the database dump, so the export cannot continue. Start a new backup.');
            }
            fclose($trunc);
        }

        $handle = fopen($sqlTmp, 'c+b');
        if (false === $handle) {
            throw new \RuntimeException('Migrator: cannot open temp file for SQL dump.');
        }
        fseek($handle, 0, SEEK_END);

        // At least one chunk per step, so even a budget that is already spent
        // moves the dump forward instead of looping forever.
        while ($tableIndex < $totalTables) {
            $table  = (string) $tables[$tableIndex];
            $cursor = $this->dumper->dumpTableChunk($table, $handle, $where[$table] ?? null, $cursor, $budget);
            if (null === $cursor) {
                $tableIndex++;
            }
            if ($budget->expired()) {
                break;
            }
        }

        if ($tableIndex >= $totalTables) {
            $this->dumper->dumpViews($handle, $skip);
            $this->dumper->dumpFooter($handle);
            fclose($handle);

            $writer = new Writer($dest, true);
            $writer->addFile(Exporter::DB_ENTRY, $sqlTmp);
            wp_delete_file($sqlTmp);

            $routines = $this->dumper->routines();
            if ([] !== $routines) {
                $writer->addString(Exporter::ROUTINES_ENTRY, (string) wp_json_encode($routines));
            }
            $writer->close();

            clearstatcache(true, $dest);
            $job['bytes']          = (int) filesize($dest);
            $job['phase']          = 'files';
            $job['db_table_index'] = $tableIndex;
            $job['db_cursor']      = null;
            unset($job['db_tmp'], $job['db_tables'], $job['db_skip'], $job['db_where'], $job['db_bytes'], $job['db_rows']);
        } else {
            fclose($handle);
            clearstatcache(true, $sqlTmp);
            $job['db_bytes']       = (int) filesize($sqlTmp);
            $job['db_table_index'] = $tableIndex;
            $job['db_cursor']      = $cursor;
        }

        $this->save($job);

        return $job;
    }

    /**
     * Append the next time-boxed batch of files.
     *
     * @param array<string, mixed> $job
     * @return array<string, mixed>
     */
    private function stepFiles(array $job, TimeBudget $budget): array
    {
        $dest    = (string) $job['dest'];
        $index   = (int) $job['index'];
        $total   = (int) $job['total'];
        $clean   = (int) ($job['bytes'] ?? filesize($dest));
        $offset  = (int) ($job['listOffset'] ?? 0);
        $base    = $this->contentDir();

        // If a previous step died mid-file, the archive has a partial entry past
        // its last clean size. Truncate it away before appending more. Failing
        // to is fatal: appending after half an entry corrupts everything after.
        clearstatcache(true, $dest);
        if ((int) filesize($dest) > $clean) {
            $trunc = fopen($dest, 'r+b');
            if (false === $trunc || ! ftruncate($trunc, $clean)) {
                if (false !== $trunc) {
                    fclose($trunc);
                }
                throw new \RuntimeException('Migrator: could not cut a half-written entry off the archive, so the export cannot continue. Start a new backup.');
            }
            fclose($trunc);
        }

        $writer   = new Writer($dest, true);
        $warnings = is_array($job['warnings'] ?? null) ? $job['warnings'] : [];

        // Walk the file list from the saved byte offset, one line at a time, so a
        // list with millions of entries never has to sit in memory.
        $list = fopen((string) $job['list'], 'rb');
        if (false === $list) {
            $writer->close();
            throw new \RuntimeException('Migrator: the export\'s file list is gone, so it cannot continue. Start a new backup.');
        }
        fseek($list, $offset);
        $start = $index;
        // Stop on the time budget (slow hosts) or after a batch of files
        // (fast hosts), whichever comes first, so the browser gets frequent
        // progress updates instead of one long step that looks frozen. The
        // first file always goes, so a tiny budget still makes progress.
        while ($index < $total && ($index === $start || ! $budget->expired()) && ($index - $start) < self::FILES_PER_STEP) {
            $line = fgets($list);
            if (false === $line) {
                break;
            }
            $rel = rtrim($line, "\r\n");
            $abs = $base . '/' . $rel;
            if ('' !== $rel && is_file($abs)) {
                if (is_readable($abs)) {
                    $writer->addFile('wp-content/' . $rel, $abs);
                } else {
                    // Skip it and say so. Throwing failed the whole export on
                    // one file the web server user is not allowed to read.
                    $warnings[] = sprintf('wp-content/%s is not readable by the web server and was left out.', $rel);
                }
            }
            $index++;
        }
        $offset = (int) ftell($list);
        fclose($list);

        $job['warnings'] = array_slice(array_merge($warnings, $writer->warnings()), 0, self::MAX_WARNINGS);

        if ($index >= $total) {
            $writer->finish();
            $final = (string) ($job['final'] ?? '');
            if ('' !== $final && $final !== $dest) {
                // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
                if (! rename($dest, $final)) {
                    throw new \RuntimeException('Migrator: the backup was written but could not be given its final name.');
                }
                $dest        = $final;
                $job['dest'] = $final;
            }
            $job['status'] = 'done';
            clearstatcache(true, $dest);
            $job['bytes']  = (int) filesize($dest);
            wp_delete_file((string) $job['list']);
        } else {
            $writer->close();
            $job['bytes']      = (int) filesize($dest); // record the clean size after this step
            $job['listOffset'] = $offset;
        }

        $job['index'] = $index;
        $this->save($job);

        return $job;
    }

    /**
     * @return array<string, mixed>
     */
    public function current(): array
    {
        $job = get_option(self::JOB_OPTION, []);

        return is_array($job) ? $job : [];
    }

    /**
     * Whether another export is still being driven by a live browser tab.
     */
    public function isRunning(): bool
    {
        $job = $this->current();

        return 'running' === ($job['status'] ?? '')
            && (int) ($job['updatedAt'] ?? $job['startedAt'] ?? 0) > time() - self::STALE_AFTER;
    }

    /**
     * Forget the current job. An unfinished one also loses its partial archive,
     * which can never be completed.
     */
    public function clear(): void
    {
        $job = $this->current();
        if (isset($job['list']) && is_readable((string) $job['list'])) {
            wp_delete_file((string) $job['list']);
        }
        if (isset($job['db_tmp']) && is_file((string) $job['db_tmp'])) {
            wp_delete_file((string) $job['db_tmp']);
        }
        $dest = (string) ($job['dest'] ?? '');
        if ('done' !== ($job['status'] ?? '') && str_ends_with($dest, self::PART) && is_file($dest)) {
            wp_delete_file($dest);
        }
        delete_option(self::JOB_OPTION);
    }

    /**
     * @return list<string>
     */
    private function uploadsWarning(ExportOptions $options): array
    {
        $outside = $options->is('no_media') ? null : Exporter::uploadsOutsideContent();

        return null === $outside ? [] : [sprintf('The media library is outside wp-content (%s) and is not in this backup.', $outside)];
    }

    private function scanner(ExportOptions $options): FileScanner
    {
        return new FileScanner(
            ['node_modules', '.git', '.DS_Store'],
            array_merge([$this->workspace->path()], $options->fileExcludePaths()),
            $options->fileExcludePatterns(),
            $options->maxFileBytes(),
        );
    }

    private function contentDir(): string
    {
        return untrailingslashit((string) WP_CONTENT_DIR);
    }

    private function destination(): string
    {
        $host = (string) wp_parse_url((string) get_option('home'), PHP_URL_HOST);
        $host = preg_replace('/[^a-z0-9.-]/i', '', $host) ?: 'site';

        // Include a random token so a backup is never at a guessable URL (hosts
        // on nginx don't honour the .htaccess deny).
        return $this->workspace->path(sprintf('%s-%s-%s.migrator', $host, gmdate('Ymd-His'), wp_generate_password(8, false)));
    }

    /**
     * @param array<string, mixed> $job
     */
    private function save(array $job): void
    {
        $job['updatedAt'] = time();
        update_option(self::JOB_OPTION, $job, false);
    }
}
