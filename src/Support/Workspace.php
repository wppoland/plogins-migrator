<?php

declare(strict_types=1);

namespace Migrator\Support;

defined('ABSPATH') || exit;

/**
 * Owns the private working directory where archives and in-progress job state
 * live. It sits under wp-content (survives plugin updates, writable on most
 * hosts) and is locked down so the archives, which contain a full copy of the
 * site, including secrets, are never web-accessible.
 *
 * Files are never served by URL: downloads go through an authenticated admin
 * handler that streams them, so the directory stays deny-all.
 */
final class Workspace
{
    private const DIR_NAME = 'migrator-backups';

    private ?string $base = null;

    /**
     * Absolute path to the working directory, creating and protecting it on
     * first use. Pass a relative path to resolve a child (no path traversal:
     * the result is always confined to the workspace).
     */
    public function path(string $relative = ''): string
    {
        $base = $this->ensure();

        if ('' === $relative) {
            return $base;
        }

        // Drop empty, current, and parent segments so no traversal survives. A
        // single non-recursive str_replace('../', '') is bypassable (e.g.
        // '....//' collapses to '../'); filtering per segment is not.
        $segments = array_filter(
            explode('/', str_replace('\\', '/', $relative)),
            static fn (string $s): bool => '' !== $s && '.' !== $s && '..' !== $s
        );

        return $base . '/' . implode('/', $segments);
    }

    /**
     * Create the directory (if needed) and (re)write its guard files. Idempotent
     * and cheap to call; safe from the activation hook and from job startup.
     *
     * @return string Absolute path to the directory.
     */
    public function ensure(): string
    {
        $dir = $this->baseDir();

        if (! is_dir($dir)) {
            wp_mkdir_p($dir);
        }

        $this->writeGuard($dir . '/.htaccess', "Order Deny,Allow\nDeny from all\n");
        $this->writeGuard(
            $dir . '/web.config',
            "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<configuration><system.webServer>"
            . "<authorization><deny users=\"*\" /></authorization>"
            . "</system.webServer></configuration>\n"
        );
        $this->writeGuard($dir . '/index.php', "<?php\n// Silence is golden.\n");

        return $dir;
    }

    /**
     * Resolve the base directory, allowing hosts to relocate it (e.g. onto a
     * larger volume) via filter. Cached per request.
     */
    private function baseDir(): string
    {
        if (null !== $this->base) {
            return $this->base;
        }

        $default = rtrim((string) WP_CONTENT_DIR, '/') . '/' . self::DIR_NAME;

        /**
         * Filters the absolute path of Migrator's private working directory.
         *
         * @param string $default Default path under wp-content.
         */
        $dir = (string) apply_filters('migrator/workspace_dir', $default);

        return $this->base = rtrim($dir, '/');
    }

    /**
     * Write a guard file only when missing or changed, to avoid touching the
     * disk on every request.
     */
    private function writeGuard(string $file, string $contents): void
    {
        if (is_file($file) && md5_file($file) === md5($contents)) {
            return;
        }

        // Direct write: these are tiny static guard files outside the upload
        // pipeline; WP_Filesystem would add a credentials round-trip for no gain.
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
        @file_put_contents($file, $contents, LOCK_EX);
    }
}
