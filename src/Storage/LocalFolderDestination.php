<?php

declare(strict_types=1);

namespace Migrator\Storage;

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
        return '' !== $this->folder && $this->ensureFolder() && wp_is_writable($this->folder);
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
        $archives = $this->archives();
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
