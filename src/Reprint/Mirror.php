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

        $skip = [
            $from . '/' . Workspace::DIR_NAME,
            $to . '/' . Workspace::DIR_NAME,
            untrailingslashit(\Migrator\PLUGIN_DIR),
        ];
        if (defined('Migrator\\Pro\\PLUGIN_FILE')) {
            $skip[] = dirname((string) constant('Migrator\\Pro\\PLUGIN_FILE'));
        }
        // The plugin folder may have a different name on the other side of the copy.
        $own = basename(untrailingslashit(\Migrator\PLUGIN_DIR));
        $skip[] = $from . '/plugins/' . $own;
        $skip[] = $to . '/plugins/' . $own;
        foreach (self::DROP_INS as $dropIn) {
            $skip[] = $from . '/' . $dropIn;
        }

        $copied = 0;
        $items  = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($from, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );

        foreach ($items as $item) {
            /** @var \SplFileInfo $item */
            $path   = $item->getPathname();
            $target = $to . substr($path, strlen($from));
            foreach ($skip as $protected) {
                if (str_starts_with($path . '/', $protected . '/') || str_starts_with($target . '/', $protected . '/')) {
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
