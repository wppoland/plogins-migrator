<?php

declare(strict_types=1);

namespace Migrator\Backup;

use Migrator\Engine\Archive\Compressor;
use Migrator\Engine\Db\Dumper;
use Migrator\Engine\Export\Exporter;
use Migrator\Storage\OffsiteSettings;
use Migrator\Support\Workspace;

defined('ABSPATH') || exit;

/**
 * Runs an unattended backup and enforces the retention policy. Reuses the
 * Exporter and Dumper verbatim, so a scheduled backup is byte-for-byte the same
 * archive a manual one produces. Records the outcome for the admin UI.
 *
 * A full backup each run is the whole of what this class does. Other strategies
 * (an incremental chain, say) are not special-cased here; an add-on answers
 * `migrator/backup_run` and owns that run end to end. That direction matters:
 * this class previously called into the incremental implementation AND read its
 * option keys back, so neither half could be moved without the other.
 */
final class BackupRunner
{
    public const STATUS_OPTION = 'migrator_last_backup';

    /** The pre-split option, read once so the last run still shows after upgrading. */
    private const LEGACY_STATUS_OPTION = 'migrator_pro_last_backup';

    public function __construct(private Workspace $workspace)
    {
    }

    /**
     * Build one archive per the schedule, then prune old scheduled archives down
     * to the retention count. Returns the recorded status.
     *
     * Pass $allowStrategies = false for a backup that must be a plain, readable
     * full archive whatever the site is configured to do: a rollback point taken
     * before a risky operation is worthless if it lands as an incremental link
     * in a chain, or encrypted with a passphrase nobody types at restore time.
     *
     * @return array<string, mixed>
     */
    public function run(Schedule $schedule, bool $allowStrategies = true): array
    {
        $this->workspace->ensure();

        $status = null;

        if ($allowStrategies) {
            /**
             * Take over this run entirely.
             *
             * Return a status array to declare the run handled; return null to
             * let the ordinary full backup proceed. The status is recorded and
             * broadcast exactly as if it came from here.
             *
             * @param array<string, mixed>|null $status
             * @param Schedule                  $schedule
             * @param Workspace                 $workspace
             */
            $status = apply_filters('migrator/backup_run', null, $schedule, $this->workspace);
        }

        if (! is_array($status)) {
            $status = $this->runFull($schedule, $allowStrategies);
        }

        update_option(self::STATUS_OPTION, $status, false);

        /**
         * Fires once a scheduled backup has run and its outcome is recorded,
         * whether it succeeded or failed. Activity log, email notifications and
         * recovery points listen here.
         *
         * @param array<string, mixed> $status   The recorded run status.
         * @param Schedule             $schedule The schedule that produced it.
         */
        do_action('migrator/backup_recorded', $status, $schedule);

        return $status;
    }

    /**
     * A full backup, optionally compressed and copied off-site, pruned to the
     * retention count.
     *
     * @return array<string, mixed>
     */
    private function runFull(Schedule $schedule, bool $allowStrategies): array
    {
        global $wpdb;

        try {
            $exporter    = new Exporter($this->workspace, new Dumper($wpdb));
            $destination = $this->destination();
            $result      = $exporter->export($destination, null, $schedule->options());

            $archivePath = (string) $result['path'];
            $pp          = $this->postProcess($archivePath, $schedule, $allowStrategies);
            $archivePath = $pp['path'];

            $kept = $this->prune($schedule->retention);

            return [
                'time'       => time(),
                'ok'         => true,
                'path'       => $archivePath,
                'file'       => basename($archivePath),
                'bytes'      => (int) filesize($archivePath),
                'tables'     => (int) $result['tables'],
                'files'      => (int) $result['files'],
                'compressed' => $pp['compressed'],
                'kept'       => $kept,
                'offsite'    => $this->copyOffsite($archivePath, $schedule->retention),
                'message'    => '',
            ] + $pp['extra'];
        } catch (\Throwable $e) {
            return [
                'time'    => time(),
                'ok'      => false,
                'path'    => '',
                'file'    => '',
                'bytes'   => 0,
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Post-process a finished archive: gzip when the schedule asks for it, then
     * hand the file to anything else that wants a pass over it.
     *
     * Compression runs first on purpose. It must see the original bytes, and an
     * add-on that encrypts randomises them, so the order is not interchangeable.
     * A failed step leaves the previous good file rather than losing the backup.
     *
     * @return array{path: string, compressed: bool, extra: array<string, mixed>}
     */
    private function postProcess(string $path, Schedule $schedule, bool $allowStrategies): array
    {
        $compressed = false;

        if ($schedule->compress) {
            $gz = $path . Compressor::EXT;
            try {
                (new Compressor())->compress($path, $gz);
                wp_delete_file($path);
                $path       = $gz;
                $compressed = true;
            } catch (\Throwable $e) {
                // Keep the uncompressed archive.
            }
        }

        /**
         * Transform a finished archive before it is pruned and copied off-site.
         *
         * Receives and must return `['path' => string, 'extra' => array]`. The
         * path may point at a new file; whatever is returned is what the rest of
         * the run treats as the backup. `extra` is merged into the recorded
         * status so an add-on can report what it did.
         *
         * @param array{path: string, extra: array<string, mixed>} $result
         * @param Schedule                                         $schedule
         */
        $result = $allowStrategies
            ? apply_filters('migrator/backup_post_process', ['path' => $path, 'extra' => []], $schedule)
            : ['path' => $path, 'extra' => []];

        $path  = is_array($result) && is_string($result['path'] ?? null) && is_readable($result['path'])
            ? $result['path']
            : $path;
        $extra = is_array($result) && is_array($result['extra'] ?? null) ? $result['extra'] : [];

        return ['path' => $path, 'compressed' => $compressed, 'extra' => $extra];
    }

    /**
     * Copy the finished archive to the configured off-site destination and prune
     * it there. Never fails the backup: a local backup that cannot reach the
     * off-site target is still a good backup, so the outcome is recorded, not
     * thrown.
     *
     * @return array<string, mixed> Status: enabled/ok/kept/message.
     */
    private function copyOffsite(string $archivePath, int $retention): array
    {
        $destination = OffsiteSettings::load()->destination();
        if (null === $destination) {
            return ['enabled' => false];
        }

        try {
            $destination->store($archivePath);
            $kept = $destination->prune($retention);

            return ['enabled' => true, 'ok' => true, 'kept' => $kept, 'label' => $destination->label(), 'message' => ''];
        } catch (\Throwable $e) {
            return ['enabled' => true, 'ok' => false, 'kept' => 0, 'label' => $destination->label(), 'message' => $e->getMessage()];
        }
    }

    /**
     * The most recent recorded run, or null if a backup has never run.
     *
     * @return array<string, mixed>|null
     */
    public function lastStatus(): ?array
    {
        $status = get_option(self::STATUS_OPTION, null);

        if (! is_array($status)) {
            $status = get_option(self::LEGACY_STATUS_OPTION, null);
        }

        return is_array($status) ? $status : null;
    }

    /**
     * Scheduled archives newest-first. This is the set retention owns.
     *
     * @return array<int, array{file: string, path: string, bytes: int, time: int}>
     */
    public function archives(): array
    {
        return $this->describe(glob($this->workspace->path('*-' . Schedule::MARKER . '-*.migrator*')) ?: []);
    }

    /**
     * Every archive a schedule has produced, for the Scheduled Backups screen.
     *
     * Deliberately NOT the same set as archives(). An add-on that runs its own
     * strategy writes its own filenames, and the screen listing "Kept backups"
     * showed nothing at all while those backups were being taken normally.
     *
     * It must stay separate from archives(), which retention owns: prune() keeps
     * the newest N of whatever it is handed and deletes the rest, so widening
     * archives() itself would let retention delete a file another strategy still
     * depends on.
     *
     * @return array<int, array{file: string, path: string, bytes: int, time: int}>
     */
    public function listArchives(): array
    {
        /**
         * Additional archive paths to show on the Scheduled Backups screen.
         *
         * @param list<string> $paths
         */
        $extra = apply_filters('migrator/backup_listed_archives', []);

        $paths = array_merge(
            glob($this->workspace->path('*-' . Schedule::MARKER . '-*.migrator*')) ?: [],
            is_array($extra) ? array_filter($extra, 'is_string') : [],
        );

        return $this->describe(array_values(array_unique($paths)));
    }

    /**
     * @param list<string> $paths
     *
     * @return array<int, array{file: string, path: string, bytes: int, time: int}>
     */
    private function describe(array $paths): array
    {
        $items = [];
        foreach ($paths as $path) {
            $items[] = [
                'file'  => basename($path),
                'path'  => $path,
                'bytes' => (int) filesize($path),
                'time'  => (int) filemtime($path),
            ];
        }

        usort($items, static fn (array $a, array $b): int => $b['time'] <=> $a['time']);

        return $items;
    }

    /**
     * Keep the newest $retention scheduled archives; delete the rest.
     *
     * @return int How many archives remain after pruning.
     */
    private function prune(int $retention): int
    {
        $archives = $this->archives();
        foreach (array_slice($archives, $retention) as $old) {
            wp_delete_file($old['path']);
        }

        return min(count($archives), $retention);
    }

    /**
     * A dated, unguessable filename carrying the scheduled marker so retention can
     * find exactly the backups it owns and never a manual one.
     */
    private function destination(): string
    {
        $host = (string) wp_parse_url((string) get_option('home'), PHP_URL_HOST);
        $host = preg_replace('/[^a-z0-9.-]/i', '', $host) ?: 'site';

        return $this->workspace->path(sprintf(
            '%s-%s-%s-%s.migrator',
            $host,
            Schedule::MARKER,
            gmdate('Ymd-His'),
            wp_generate_password(8, false),
        ));
    }
}
