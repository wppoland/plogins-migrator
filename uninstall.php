<?php
/**
 * Uninstall cleanup. Removes Migrator's options and deletes its private working
 * directory (archives and any in-progress job state). Nothing is left behind.
 *
 * @package Migrator
 */

declare(strict_types=1);

defined('WP_UNINSTALL_PLUGIN') || exit;

// Migrator streams large backup archives (often gigabytes) in chunks. WP_Filesystem
// reads and writes whole files into memory, which would exhaust it, so this file
// uses direct stream functions by necessity.
// phpcs:disable WordPress.WP.AlternativeFunctions

delete_option('migrator_settings');
delete_option('migrator_db_version');
delete_option('migrator_export_job');

// Remove the backups directory, recursively. Defined inline so uninstall has no
// dependency on the (already-unloaded) plugin autoloader.
$migrator_dir = rtrim((string) WP_CONTENT_DIR, '/') . '/migrator-backups';

if (is_dir($migrator_dir)) {
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
