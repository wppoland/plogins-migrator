<?php

declare(strict_types=1);

namespace Migrator\Reprint;

use Migrator\Support\Workspace;

defined('ABSPATH') || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped, WordPress.WP.AlternativeFunctions -- WP-CLI only: messages go to a terminal, and the files are copied in bulk outside any request.

/**
 * Copies one wp-content tree over another, for pull (pulled tree to this site)
 * and push (this site to the pushed tree).
 *
 * Migrator itself and its backups folder never travel, and neither do the
 * drop-ins that bind a site to its server: a page cache, an object cache or a
 * database driver from the old host makes the copy fail to boot on the new one
 * (Reprint issue #114). A file is copied when
 * its size differs, or its time differs and its contents do too: a push and the
 * pull after it touch every time stamp, and comparing times alone recopied the
 * whole site. Files only the destination has are left alone.
 */
final class Mirror
{
    /** Drop-ins that carry server-specific wiring. */
    public const DROP_INS = ['advanced-cache.php', 'object-cache.php', 'db.php', 'db-error.php', 'sunrise.php', 'fatal-error-handler.php'];

    public static function copy(string $from, string $to): int
    {
        if (! is_dir($from)) {
            throw new \RuntimeException('There is no wp-content folder at ' . $from . '.');
        }

        // Matched on the path relative to wp-content, on both sides alike.
        // Absolute paths cannot be used: the pushed tree itself sits inside
        // this site's backups folder, so every target would look protected.
        // Migrator is skipped under its folder here and under its wp.org slug,
        // which is what the other site most likely runs it from.
        $skip = ['/' . Workspace::DIR_NAME, '/plugins/' . basename(untrailingslashit(\Migrator\PLUGIN_DIR)), '/plugins/plogins-migrator', '/plugins/plogins-migrator-pro'];
        if (defined('Migrator\\Pro\\PLUGIN_FILE')) {
            $skip[] = '/plugins/' . basename(dirname((string) constant('Migrator\\Pro\\PLUGIN_FILE')));
        }
        foreach (self::DROP_INS as $dropIn) {
            $skip[] = '/' . $dropIn;
        }

        $copied = 0;
        $items  = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($from, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );

        foreach ($items as $item) {
            /** @var \SplFileInfo $item */
            $path     = $item->getPathname();
            $relative = substr($path, strlen($from));
            $target   = $to . $relative;
            foreach ($skip as $protected) {
                if ($relative === $protected || str_starts_with($relative, $protected . '/')) {
                    continue 2;
                }
            }

            if ($item->isDir()) {
                wp_mkdir_p($target);
                continue;
            }
            if (is_file($target) && filesize($target) === $item->getSize()
                && (filemtime($target) === $item->getMTime() || md5_file($target) === md5_file($path))) {
                continue;
            }
            if (! copy($path, $target)) {
                throw new \RuntimeException('Could not write ' . $target . '. Check that the web server user can write there.');
            }
            touch($target, $item->getMTime());
            $copied++;
        }

        return $copied;
    }
}
