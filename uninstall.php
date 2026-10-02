<?php
/**
 * Uninstall cleanup. Removes every option Migrator writes, its cron events and
 * its private working directory (archives and any in-progress job state).
 * Nothing is left behind.
 *
 * @package Migrator
 */

declare(strict_types=1);

defined('WP_UNINSTALL_PLUGIN') || exit;

// Migrator streams large backup archives (often gigabytes) in chunks. WP_Filesystem
// reads and writes whole files into memory, which would exhaust it, so this file
// uses direct stream functions by necessity.
// phpcs:disable WordPress.WP.AlternativeFunctions

/**
 * Everything stored per site: the options the plugin writes, the three it
 * still reads from before scheduling moved here from the paid add-on, and the
 * scheduled backup event under both hook names.
 */
$migrator_clean_site = static function (): void {
    foreach ([
        'migrator_export_job',
        'migrator_export_postprocess',
        'migrator_export_compress',
        'migrator_schedule',
        'migrator_offsite',
        'migrator_last_backup',
        'migrator_pro_schedule',
        'migrator_pro_offsite',
        'migrator_pro_last_backup',
    ] as $migrator_option) {
        delete_option($migrator_option);
    }

    wp_clear_scheduled_hook('migrator_run_scheduled_backup');
    wp_clear_scheduled_hook('migrator_pro_run_scheduled_backup');
};

if (is_multisite()) {
    foreach (get_sites(['fields' => 'ids', 'number' => 0]) as $migrator_site) {
        switch_to_blog((int) $migrator_site);
        $migrator_clean_site();
        restore_current_blog();
    }
} else {
    $migrator_clean_site();
}

// The PRO banner's dismissal is stored per user, so it belongs to the
// plugin rather than to the site content. User meta is global, not
// per-site, which is why this uses delete_metadata's \$delete_all rather
// than a loop over the users of one blog.
delete_metadata('user', 0, 'migrator_pro_banner_dismissed', '', true);

// The working directory, wherever a host moved it with the migrator/workspace_dir
// filter (the filter's owner is still loaded during uninstall). It is removed
// only when it carries the deny-all guard Migrator writes into it, so a filter
// pointed somewhere unexpected never turns uninstall into rm -rf of that place.
$migrator_default = rtrim((string) WP_CONTENT_DIR, '/') . '/migrator-backups';
$migrator_dir     = rtrim((string) apply_filters('migrator/workspace_dir', $migrator_default), '/');
$migrator_guard   = $migrator_dir . '/.htaccess';
$migrator_ours    = is_file($migrator_guard) && str_contains((string) file_get_contents($migrator_guard), 'Require all denied');
$migrator_root    = rtrim((string) ABSPATH, '/');

if ('' !== $migrator_dir && $migrator_dir !== $migrator_root && is_dir($migrator_dir) && $migrator_ours) {
    $migrator_items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($migrator_dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ($migrator_items as $migrator_item) {
        /** @var SplFileInfo $migrator_item */
        if ($migrator_item->isDir()) {
            @rmdir($migrator_item->getPathname());
        } else {
            @unlink($migrator_item->getPathname());
        }
    }

    @rmdir($migrator_dir);
}
