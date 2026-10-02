<?php

declare(strict_types=1);

namespace Migrator\Storage;

use Migrator\Backup\Schedule;
use Migrator\Support\Workspace;

defined('ABSPATH') || exit;

// Backups are multi-gigabyte archives; copy() streams them on disk without
// loading them into memory, so this adapter uses it deliberately.
// phpcs:disable WordPress.WP.AlternativeFunctions

/**
 * Copies finished backups to a folder outside the web root, typically a mounted
 * volume (NAS, external disk, or an rclone/sshfs mount that fronts cloud
 * storage). Off-site by configuration: point it anywhere the server can write.
 */
final class LocalFolderDestination implements BackupDestination
{
    public function __construct(private string $folder)
    {
        $this->folder = untrailingslashit($folder);
    }

    public function id(): string
    {
        return 'local-folder';
    }

    public function label(): string
    {
        return __('Local or mounted folder', 'plogins-migrator');
    }

    public function isConfigured(): bool
    {
        return null === $this->problem();
    }

    /**
     * Why this folder cannot take backups, or null when it can.
     *
     * Retention deletes files here, and anything under the web root can be
     * downloaded by whoever guesses the name, so the folder has to be an
     * absolute path outside the site and outside Migrator's own workspace (a
     * copy there would be pruned as if it were a local backup).
     */
    public function problem(): ?string
    {
        if ('' === $this->folder) {
            return __('No folder is set.', 'plogins-migrator');
        }
        if (! str_starts_with($this->folder, '/') && ! preg_match('#^[A-Za-z]:[\\\\/]#', $this->folder)) {
            return __('The folder must be an absolute path.', 'plogins-migrator');
        }

        $folder = $this->resolved();
        foreach ([(new Workspace())->path(), (string) ABSPATH, (string) WP_CONTENT_DIR] as $forbidden) {
            $base = untrailingslashit((string) (realpath($forbidden) ?: $forbidden));
            if ('' !== $base && ($folder === $base || str_starts_with($folder . '/', $base . '/'))) {
                return __('The folder must be outside the website and outside Migrator\'s own backups folder.', 'plogins-migrator');
            }
        }

        if (! $this->ensureFolder() || ! wp_is_writable($this->folder)) {
            return __('The folder does not exist and cannot be created, or the web server cannot write to it.', 'plogins-migrator');
        }

        return null;
    }

    /** The folder with symlinks and ".." resolved as far as it exists. */
    private function resolved(): string
    {
        $path   = $this->folder;
        $suffix = '';
        while ('' !== $path && '/' !== $path && false === realpath($path)) {
            $suffix = '/' . basename($path) . $suffix;
            $path   = dirname($path);
        }
        $real = realpath($path);

        return untrailingslashit((false === $real ? $path : $real) . $suffix);
    }

    public function store(string $archivePath): void
    {
        if (! is_readable($archivePath)) {
            throw new \RuntimeException(esc_html('Source archive is not readable: ' . $archivePath));
        }
        if (! $this->isConfigured()) {
            throw new \RuntimeException(esc_html('Destination folder is not writable: ' . $this->folder));
        }

        $target = $this->folder . '/' . basename($archivePath);

        // Copy to a temp name first, then rename, so a reader never sees a
        // half-written archive at the final path.
        $temp = $target . '.part';
        if (! copy($archivePath, $temp)) {
            throw new \RuntimeException(esc_html('Could not copy the backup to: ' . $this->folder));
        }
        if (! rename($temp, $target)) {
            wp_delete_file($temp);
            throw new \RuntimeException(esc_html('Could not finalise the backup at: ' . $this->folder));
        }
    }

    public function prune(int $retention): int
    {
        // Only this site's scheduled archives. The folder may hold manual
        // backups or another site's, and those used to be counted and deleted.
        $prefix   = Schedule::archivePrefix();
        $archives = array_values(array_filter(
            $this->archives(),
            static fn (array $a): bool => str_starts_with($a['file'], $prefix),
        ));
        foreach (array_slice($archives, $retention) as $old) {
            wp_delete_file($this->folder . '/' . $old['file']);
        }

        return min(count($archives), $retention);
    }

    public function archives(): array
    {
        if ('' === $this->folder || ! is_dir($this->folder)) {
            return [];
        }

        $items = [];
        foreach (glob($this->folder . '/*.migrator*') ?: [] as $path) {
            if (str_ends_with($path, '.part')) {
                continue;
            }
            $items[] = [
                'file'  => basename($path),
                'bytes' => (int) filesize($path),
                'time'  => (int) filemtime($path),
            ];
        }

        usort($items, static fn (array $a, array $b): int => $b['time'] <=> $a['time']);

        return $items;
    }

    /**
     * Create the folder if it does not exist yet. Returns whether it is a usable
     * directory afterwards.
     */
    private function ensureFolder(): bool
    {
        if ('' === $this->folder) {
            return false;
        }
        if (! is_dir($this->folder)) {
            wp_mkdir_p($this->folder);
        }

        return is_dir($this->folder);
    }
}
