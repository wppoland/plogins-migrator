<?php

declare(strict_types=1);

namespace Migrator\Cli;

use Migrator\Engine\Db\Dumper;
use Migrator\Engine\Export\ExportOptions;
use Migrator\Engine\Export\Exporter;
use Migrator\Engine\Import\Importer;
use Migrator\Support\Workspace;

defined('ABSPATH') || exit;

/**
 * WP-CLI commands for Migrator. The CLI path has no web-request timeout, so it
 * is the reliable way to back up or move large sites.
 */
final class Command
{
    /**
     * Export the whole site (database + wp-content) into a single archive.
     *
     * ## OPTIONS
     *
     * [--output=<file>]
     * : Where to write the archive. Defaults to a dated file in the backups folder.
     *
     * [--exclude=<list>]
     * : Comma-separated things to leave out. Any of: database, media, themes,
     * inactive-themes, plugins, inactive-plugins, muplugins, cache,
     * spam-comments, post-revisions, transients, sessions, action-scheduler.
     *
     * [--exclude-tables=<list>]
     * : Comma-separated exact table names to leave out of the database dump.
     *
     * [--exclude-files=<list>]
     * : Comma-separated wp-content-relative paths to leave out (e.g. uploads/2019,cache).
     *
     * [--compress]
     * : Gzip the finished archive (smaller file). Import auto-detects compression.
     *
     * ## EXAMPLES
     *
     *     wp migrator export
     *     wp migrator export --output=/tmp/my-site.migrator
     *     wp migrator export --exclude=media,spam-comments,post-revisions,inactive-plugins
     *     wp migrator export --exclude-tables=wp_actionscheduler_logs --exclude-files=uploads/2019
     *
     * @param array<int, string>    $args       Positional args (unused).
     * @param array<string, string> $assoc_args Flags.
     */
    public function export(array $args, array $assoc_args): void
    {
        global $wpdb;

        $workspace = new Workspace();
        $workspace->ensure();
        $exporter = new Exporter($workspace, new Dumper($wpdb));

        $destination = $assoc_args['output'] ?? $exporter->defaultDestination();

        $exclude = array_filter(array_map('trim', explode(',', (string) ($assoc_args['exclude'] ?? ''))));
        $flags   = [];
        foreach (ExportOptions::keys() as $key) {
            $name        = str_replace(['no_', '_'], ['', '-'], $key); // no_post_revisions -> post-revisions
            $flags[$key] = in_array($name, $exclude, true);
        }
        $flags['exclude_tables'] = array_filter(array_map('trim', explode(',', (string) ($assoc_args['exclude-tables'] ?? ''))));
        $flags['exclude_paths']  = array_filter(array_map('trim', explode(',', (string) ($assoc_args['exclude-files'] ?? ''))));
        $options = ExportOptions::fromArray($flags);

        \WP_CLI::log('Exporting site…');
        $result = $exporter->export($destination, static function (string $message): void {
            \WP_CLI::log('  ' . $message);
        }, $options);

        if (isset($assoc_args['compress'])) {
            \WP_CLI::log('Compressing…');
            $gz = $result['path'] . \Migrator\Engine\Archive\Compressor::EXT;
            (new \Migrator\Engine\Archive\Compressor())->compress($result['path'], $gz);
            wp_delete_file($result['path']);
            $result['path']  = $gz;
            $result['bytes'] = (int) filesize($gz);
        }

        \WP_CLI::success(sprintf(
            'Exported %d tables and %d files to %s (%s).',
            $result['tables'],
            $result['files'],
            $result['path'],
            size_format($result['bytes']),
        ));
    }

    /**
     * Import an archive onto this site (database + files), rewriting the source
     * site's URLs and paths to this site's.
     *
     * ## OPTIONS
     *
     * <file>
     * : Path to the .migrator archive to restore.
     *
     * [--skip-files]
     * : Import the database only; do not extract wp-content files.
     *
     * [--yes]
     * : Skip the confirmation prompt.
     *
     * ## EXAMPLES
     *
     *     wp migrator import /tmp/my-site.migrator
     *     wp migrator import /tmp/db-only.migrator --skip-files --yes
     *
     * @param array<int, string>    $args       Positional args: the archive path.
     * @param array<string, string> $assoc_args Flags.
     */
    public function import(array $args, array $assoc_args): void
    {
        global $wpdb;

        $archive = $args[0] ?? '';
        if ('' === $archive || ! is_readable($archive)) {
            \WP_CLI::error('Archive not found or not readable: ' . $archive);
        }

        \WP_CLI::confirm('This overwrites the current database. Continue?', $assoc_args);

        $workspace = new Workspace();
        $workspace->ensure();
        $importer = new Importer($workspace, $wpdb);

        \WP_CLI::log('Importing archive…');
        $result = $importer->import(
            $archive,
            ! isset($assoc_args['skip-files']),
            static function (string $message): void {
                \WP_CLI::log('  ' . $message);
            }
        );

        \WP_CLI::success(sprintf(
            'Imported %d SQL statements, rewrote %d rows, extracted %d files.',
            $result['statements'],
            $result['replaced'],
            $result['files'],
        ));
    }
}
