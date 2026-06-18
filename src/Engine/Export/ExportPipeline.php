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
 *   start()  → write signature, manifest and the database dump; enumerate the
 *              files to a list; leave the archive open-ended (no end marker).
 *   step()   → append a time-boxed batch of files; when the list is exhausted,
 *              write the end marker and finish.
 *
 * Each appended file is a complete, checksummed entry, so an interrupted export
 * simply resumes at the next file. Job state lives in one option.
 */
final class ExportPipeline
{
    public const JOB_OPTION = 'migrator_export_job';

    public function __construct(
        private Workspace $workspace,
        private Dumper $dumper,
    ) {
    }

    /**
     * Begin an export. Writes the manifest + database immediately and enumerates
     * the files to copy. Returns the initial job state.
     *
     * @return array<string, mixed>
     */
    public function start(?ExportOptions $options = null): array
    {
        $options ??= new ExportOptions();
        $destination = $this->destination();

        $writer = new Writer($destination);

        $prefix = $this->dumper->prefix();
        $skip   = $options->tablesToSkip($prefix);
        $tables = $options->excludeDatabase() ? [] : array_values(array_diff($this->dumper->tables(), $skip));

        $manifest = Manifest::forThisSite((string) \Migrator\VERSION, [
            'tables'   => $tables,
            'excludes' => $options->toArray(),
        ]);
        $writer->addString(Manifest::NAME, $manifest->toJson(), Entry::TYPE_MANIFEST);

        if (! $options->excludeDatabase()) {
            $sqlTmp = $this->workspace->path('tmp-' . wp_generate_password(8, false) . '.sql');
            $handle = fopen($sqlTmp, 'wb');
            if (false === $handle) {
                $writer->close();
                throw new \RuntimeException('Migrator: cannot open temp file for SQL dump.');
            }
            $this->dumper->dumpAll($tables, $handle, $options->whereFilters($prefix), $skip);
            fclose($handle);
            $writer->addFile(Exporter::DB_ENTRY, $sqlTmp);
            wp_delete_file($sqlTmp);
        }

        // Enumerate files to a list the steps walk by index.
        $listPath = $this->workspace->path('job-' . wp_generate_password(8, false) . '.list');
        $list     = fopen($listPath, 'wb');
        if (false === $list) {
            $writer->close();
            throw new \RuntimeException('Migrator: cannot open file list.');
        }
        $total = 0;
        foreach ($this->scanner($options)->scan($this->contentDir()) as $file) {
            fwrite($list, $file['rel'] . "\n");
            $total++;
        }
        fclose($list);

        $writer->close(); // Leave open-ended; steps append and finish.

        $job = [
            'id'        => wp_generate_password(12, false),
            'dest'      => $destination,
            'list'      => $listPath,
            'index'     => 0,
            'total'     => $total,
            'status'    => 'running',
            'startedAt' => time(),
        ];
        $this->save($job);

        return $job;
    }

    /**
     * Append the next time-boxed batch of files. Returns the updated job.
     *
     * @return array<string, mixed>
     */
    public function step(): array
    {
        $job = $this->current();
        if ([] === $job || 'running' !== ($job['status'] ?? '')) {
            throw new \RuntimeException('Migrator: no export in progress.');
        }

        /** @var string[] $files */
        $files   = is_readable((string) $job['list']) ? (array) file((string) $job['list'], FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [];
        $index   = (int) $job['index'];
        $total   = (int) $job['total'];
        $base    = $this->contentDir();
        $budget  = TimeBudget::forRequest();
        $writer  = new Writer((string) $job['dest'], true);

        while ($index < $total && ! $budget->expired()) {
            $rel = (string) ($files[$index] ?? '');
            $abs = $base . '/' . $rel;
            if ('' !== $rel && is_file($abs)) {
                $writer->addFile('wp-content/' . $rel, $abs);
            }
            $index++;
        }

        if ($index >= $total) {
            $writer->finish();
            $job['status'] = 'done';
            $job['bytes']  = (int) filesize((string) $job['dest']);
            wp_delete_file((string) $job['list']);
        } else {
            $writer->close();
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

    public function clear(): void
    {
        $job = $this->current();
        if (isset($job['list']) && is_readable((string) $job['list'])) {
            wp_delete_file((string) $job['list']);
        }
        delete_option(self::JOB_OPTION);
    }

    private function scanner(ExportOptions $options): FileScanner
    {
        return new FileScanner(
            ['node_modules', '.git', '.DS_Store'],
            array_merge([$this->workspace->path()], $options->fileExcludePaths()),
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
        update_option(self::JOB_OPTION, $job, false);
    }
}
