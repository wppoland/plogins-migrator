<?php

declare(strict_types=1);

namespace Migrator\Engine\Import;

use Migrator\Engine\Archive\Manifest;

defined('ABSPATH') || exit;

/**
 * Read-only checks run BEFORE a restore, so a mismatch is caught while the site
 * is still intact rather than half-way through an import. Each check returns a
 * level: "error" (the restore would be refused or is unsafe), "warn" (proceed
 * with care) or "ok" (informational, all good).
 */
final class Preflight
{
    public const ERROR = 'error';
    public const WARN  = 'warn';
    public const OK    = 'ok';

    /**
     * @return array<int, array{level: string, label: string, message: string}>
     */
    public static function check(Manifest $manifest, int $archiveBytes, bool $compressed): array
    {
        global $wpdb;
        $out = [];

        // 1) Table prefix. Importer refuses a prefix mismatch (it would leave a
        //    silently broken site), so surface it here before the attempt.
        $sourcePrefix = (string) $manifest->get('tablePrefix');
        $targetPrefix = (string) $wpdb->prefix;
        if ('' !== $sourcePrefix && $sourcePrefix !== $targetPrefix) {
            $out[] = [
                'level'   => self::ERROR,
                'label'   => __('Table prefix', 'plogins-migrator'),
                'message' => sprintf(
                    /* translators: 1: source prefix, 2: this site's prefix */
                    __('Archive uses "%1$s" but this site uses "%2$s". The restore will be refused until this site\'s $table_prefix in wp-config.php matches.', 'plogins-migrator'),
                    $sourcePrefix,
                    $targetPrefix
                ),
            ];
        } else {
            $out[] = [
                'level'   => self::OK,
                'label'   => __('Table prefix', 'plogins-migrator'),
                'message' => sprintf(/* translators: %s: table prefix */ __('Matches (%s).', 'plogins-migrator'), $targetPrefix),
            ];
        }

        // 2) Multisite boundary. Core refuses a network restore (needs the Pro
        //    add-on), so warn if either side is a network.
        if ((bool) $manifest->get('multisite') || is_multisite()) {
            $out[] = [
                'level'   => self::WARN,
                'label'   => __('Multisite', 'plogins-migrator'),
                'message' => __('This archive or this site is a network. A network-to-network restore needs Migrator Pro; the free edition will refuse it.', 'plogins-migrator'),
            ];
        }

        // 3) Disk space. We do not know the exact uncompressed size without
        //    reading the whole archive, so estimate from the file on disk: a
        //    gzip archive expands roughly 3x, a raw one needs headroom for the
        //    temp copy. Heuristic, deliberately generous. ponytail: refine only
        //    if false positives show up in the wild.
        $factor = $compressed ? 3.5 : 1.5;
        $needed = (int) ($archiveBytes * $factor);
        $free   = @disk_free_space(WP_CONTENT_DIR); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- open_basedir can throw; a missing figure just skips this check.
        if (is_float($free) && $free > 0.0) {
            if ($free < $needed) {
                $out[] = [
                    'level'   => self::WARN,
                    'label'   => __('Disk space', 'plogins-migrator'),
                    'message' => sprintf(
                        /* translators: 1: free space, 2: estimated needed */
                        __('About %1$s free, restore may need ~%2$s. Free up space before restoring.', 'plogins-migrator'),
                        size_format((int) $free),
                        size_format($needed)
                    ),
                ];
            } else {
                $out[] = [
                    'level'   => self::OK,
                    'label'   => __('Disk space', 'plogins-migrator'),
                    'message' => sprintf(/* translators: %s: free space */ __('%s free.', 'plogins-migrator'), size_format((int) $free)),
                ];
            }
        }

        // 4) Writable content directory (the restore writes files back here).
        if (! wp_is_writable(WP_CONTENT_DIR)) {
            $out[] = [
                'level'   => self::ERROR,
                'label'   => __('Writable files', 'plogins-migrator'),
                'message' => __('wp-content is not writable by the web server. File restore will fail.', 'plogins-migrator'),
            ];
        }

        // 5) PHP version. Purely informational: a site built on a newer PHP may
        //    use syntax the older target cannot run.
        $sourcePhp = (string) $manifest->get('phpVersion');
        if ('' !== $sourcePhp && version_compare($sourcePhp, PHP_VERSION, '>')) {
            $out[] = [
                'level'   => self::WARN,
                'label'   => __('PHP version', 'plogins-migrator'),
                'message' => sprintf(
                    /* translators: 1: source PHP, 2: this PHP */
                    __('Backup was made on PHP %1$s; this server runs %2$s. Code relying on newer PHP may not run here.', 'plogins-migrator'),
                    $sourcePhp,
                    PHP_VERSION
                ),
            ];
        }

        return $out;
    }
}
