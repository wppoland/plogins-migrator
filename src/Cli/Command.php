<?php

declare(strict_types=1);

namespace Migrator\Cli;

use Migrator\Engine\Db\Dumper;
use Migrator\Engine\Db\SearchReplace;
use Migrator\Engine\Export\ExportOptions;
use Migrator\Engine\Export\Exporter;
use Migrator\Engine\Import\Importer;
use Migrator\Engine\Transform\SerializedReplacer;
use Migrator\Reprint\Client;
use Migrator\Reprint\EnrolKey;
use Migrator\Reprint\Pull;
use Migrator\Reprint\Push;
use Migrator\Support\Access;
use Migrator\Support\Workspace;

defined('ABSPATH') || exit;

/**
 * Back up, restore and search-replace a site from the command line.
 *
 * The CLI path has no web-request timeout, so it is the reliable way to back up
 * or move large sites.
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

        $this->guardNetwork();

        $workspace = new Workspace();
        $workspace->ensure();
        $exporter = new Exporter($workspace, new Dumper($wpdb));

        $destination = $assoc_args['output'] ?? $exporter->defaultDestination();
        // Relative to where the command was run, made absolute so the exporter
        // can recognise the file if it lands inside wp-content.
        if (! str_starts_with($destination, '/') && ! preg_match('#^[A-Za-z]:[\\\\/]#', $destination)) {
            $destination = getcwd() . '/' . $destination;
        }

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
     * Import an archive onto this site (database and files).
     *
     * The source site's URLs and paths are rewritten to this site's.
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

        $this->guardNetwork();

        $archive = $args[0] ?? '';
        if ('' === $archive || ! is_readable($archive)) {
            \WP_CLI::error('Archive not found or not readable: ' . $archive);
        }

        \WP_CLI::confirm('This overwrites the current database. Continue?', $assoc_args);

        $workspace = new Workspace();
        $workspace->ensure();
        $importer = new Importer($workspace, $wpdb);

        \WP_CLI::log('Importing archive…');
        try {
            $result = $importer->import(
                $archive,
                ! isset($assoc_args['skip-files']),
                static function (string $message): void {
                    \WP_CLI::log('  ' . $message);
                }
            );
        } catch (\Throwable $e) {
            // A refused or failed restore is an expected outcome with a message
            // written for the person running it, not a PHP fatal.
            \WP_CLI::error($e->getMessage());
        }

        foreach ($result['warnings'] as $warning) {
            \WP_CLI::warning($warning);
        }

        \WP_CLI::success(sprintf(
            'Imported %d SQL statements, rewrote %d rows, extracted %d files.',
            $result['statements'],
            $result['replaced'],
            $result['files'],
        ));
    }

    /**
     * Search and replace a literal string across this install's tables.
     *
     * Safe for serialized data: byte-length counts stay correct.
     *
     * ## OPTIONS
     *
     * <search>
     * : The text to find, for example an old site URL or file path.
     *
     * <replace>
     * : The text to put in its place.
     *
     * [--dry-run]
     * : Report how many rows would change without writing anything.
     *
     * ## EXAMPLES
     *
     *     wp migrator replace https://old.example.com https://new.example.com
     *     wp migrator replace /var/www/old /var/www/new --dry-run
     *
     * @param array<int, string>    $args       Positional args: search, replace.
     * @param array<string, string> $assoc_args Flags.
     */
    public function replace(array $args, array $assoc_args): void
    {
        global $wpdb;

        $this->guardNetwork();

        $from = (string) ($args[0] ?? '');
        $to   = (string) ($args[1] ?? '');
        if ('' === $from) {
            \WP_CLI::error('Provide the text to search for.');
        }
        if ($from === $to) {
            \WP_CLI::error('Search and replace values are identical.');
        }

        $dryRun = isset($assoc_args['dry-run']);
        $like   = $wpdb->esc_like($wpdb->prefix) . '%';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
        $tables = array_map('strval', (array) $wpdb->get_col($wpdb->prepare('SHOW TABLES LIKE %s', $like)));

        $engine = new SearchReplace($wpdb, new SerializedReplacer($from, $to));
        $result = $engine->run($tables, $dryRun);

        if (! $dryRun) {
            wp_cache_flush();
        }
        if ([] !== $result['skipped']) {
            \WP_CLI::warning('Skipped tables with no primary key: ' . implode(', ', $result['skipped']));
        }
        if ($result['failed'] > 0) {
            \WP_CLI::warning(sprintf('%d row(s) could not be written: %s', $result['failed'], $result['error']));
        }
        \WP_CLI::success(sprintf(
            '%s %d change(s) across %d table(s); %d row(s) scanned.',
            $dryRun ? 'Dry run: would make' : 'Made',
            $result['changes'],
            $result['tables'],
            $result['rows']
        ));
    }

    /**
     * Copy a remote site into this one over HTTP: its database and wp-content.
     *
     * The source runs Migrator with Pull and Push switched on. This site's WordPress core, wp-config.php and Migrator
     * itself stay as they are. Run it again to resume an interrupted pull or,
     * after a finished one, to fetch only what changed.
     *
     * ## OPTIONS
     *
     * <url>
     * : The source site's address.
     *
     * [--secret=<token>]
     * : Only for a source server without the OpenSSL extension: the connection
     * token set there. Otherwise leave it out; the first run makes a key and
     * prints it for enrolment on the source.
     *
     * [--private-key-path=<file>]
     * : A private key enrolled on the source, instead of the stored one.
     *
     * [--insecure]
     * : Allow a plain http:// source. Anyone on the network path can read the copy.
     *
     * [--include-host-plugins]
     * : Also copy the old host's own platform plugins. By default they are left
     * behind and deactivated, because they rarely work on another host.
     *
     * [--skip-files]
     * : Pull the database only.
     *
     * [--skip-database]
     * : Pull wp-content only.
     *
     * [--yes]
     * : Do not ask for confirmation.
     *
     * ## EXAMPLES
     *
     *     wp migrator pull https://example.com
     *     wp migrator pull https://example.com --skip-database
     *
     * @param array<int, string>    $args       Positional args: the source URL.
     * @param array<string, string> $assoc_args Flags.
     */
    public function pull(array $args, array $assoc_args): void
    {
        global $wpdb;

        $this->guardNetwork();
        $url = $this->remoteUrl($args);

        $withFiles    = ! isset($assoc_args['skip-files']);
        $withDatabase = ! isset($assoc_args['skip-database']);
        if ($withDatabase) {
            \WP_CLI::confirm('This replaces the database of this site with the one from ' . $url . '. Continue?', $assoc_args);
        }

        $workspace = new Workspace();
        $pull      = new Pull(new Client($workspace), $workspace, $wpdb, static function (string $message): void {
            \WP_CLI::log($message);
        });

        try {
            $result = $pull->run($url, $this->authFlags($assoc_args), $withFiles, $withDatabase, isset($assoc_args['include-host-plugins']));
        } catch (EnrolKey $key) {
            \WP_CLI::log('');
            \WP_CLI::log('This site made a key to sign its requests to ' . $url . '. On that site open');
            \WP_CLI::log('Migrator > Pull and Push, choose "Set the token or enrol a key", paste this line and save:');
            \WP_CLI::log('');
            \WP_CLI::log($key->publicKey);
            \WP_CLI::log('');
            \WP_CLI::warning('Then run the same command again. Nothing has been changed here.');
            \WP_CLI::halt(4);
        } catch (\Throwable $e) {
            \WP_CLI::error($e->getMessage());
        }

        \WP_CLI::success(sprintf(
            'Pulled %s%s.',
            $result['database'] ? 'the database and ' : '',
            sprintf('%d changed files', $result['files']),
        ));
    }

    /**
     * Send this site's wp-content changes back to the site it was pulled from.
     *
     * Only files that differ from the last pull travel. The source must grant
     * push access on its Pull and Push screen and needs a writable folder
     * beside its web root on the same disk. The database is not pushed here;
     * see `wp migrator remote db-push --help` for hosts that support it.
     *
     * ## OPTIONS
     *
     * <url>
     * : The source site's address, as used for the pull.
     *
     * [--secret=<token>]
     * : Only for a source server without the OpenSSL extension: the connection
     * token set there. Otherwise the key made by the pull is used.
     *
     * [--private-key-path=<file>]
     * : A private key enrolled on the source, instead of the stored one.
     *
     * [--insecure]
     * : Allow a plain http:// source.
     *
     * [--yes]
     * : Do not ask for confirmation.
     *
     * ## EXAMPLES
     *
     *     wp migrator push https://example.com
     *
     * @param array<int, string>    $args       Positional args: the source URL.
     * @param array<string, string> $assoc_args Flags.
     */
    public function push(array $args, array $assoc_args): void
    {
        $this->guardNetwork();
        $url = $this->remoteUrl($args);
        \WP_CLI::confirm('This overwrites files on ' . $url . ' with the ones changed here. Continue?', $assoc_args);

        $push = new Push(new Client(new Workspace()), static function (string $message): void {
            \WP_CLI::log($message);
        });

        try {
            $copied = $push->run($url, $this->authFlags($assoc_args));
        } catch (\Throwable $e) {
            \WP_CLI::error($e->getMessage());
        }

        \WP_CLI::success(sprintf('Pushed the files changed here (%d) to %s.', $copied, $url));
    }

    /**
     * Run any low-level transfer command against a remote site.
     *
     * For everything the pull and push shortcuts do not cover: keygen,
     * files-stats, db-push with --commit, mirror mode, and the rest. Migrator
     * supplies --state-dir and --fs-root (the same ones `pull` uses) unless you
     * pass your own. Run `wp migrator remote help` for the full list.
     *
     * ## OPTIONS
     *
     * <command>
     * : The command, e.g. keygen, files-stats, db-push.
     *
     * [<args>...]
     * : The remote URL and any further arguments, passed through unchanged.
     *
     * [--<field>=<value>]
     * : Any option, passed through unchanged.
     *
     * ## EXAMPLES
     *
     *     wp migrator remote keygen https://example.com
     *     wp migrator remote files-stats https://example.com
     *
     * @param array<int, string>    $args       Positional args.
     * @param array<string, string> $assoc_args Flags.
     */
    public function remote(array $args, array $assoc_args): void
    {
        $this->guardNetwork();
        $workspace = new Workspace();
        $workspace->ensure();
        $client = new Client($workspace);

        $argv = $args;
        foreach ($assoc_args as $key => $value) {
            $argv[] = true === $value || '' === $value ? '--' . $key : '--' . $key . '=' . $value;
        }

        $remote = $args[1] ?? '';
        if (str_contains($remote, '://')) {
            $argv[1] = Client::apiUrl($remote);
            $dirs    = $client->dirs($remote);
            if (! isset($assoc_args['state-dir'])) {
                wp_mkdir_p($dirs['state']);
                $argv[] = '--state-dir=' . $dirs['state'];
            }
            if (! isset($assoc_args['fs-root'])) {
                wp_mkdir_p($dirs['files']);
                $argv[] = '--fs-root=' . $dirs['files'];
            }
        }

        \WP_CLI::halt($client->run($argv));
    }

    /**
     * @param array<int, string> $args
     */
    private function remoteUrl(array $args): string
    {
        $url = trim((string) ($args[0] ?? ''));
        if (! preg_match('#^https?://#i', $url)) {
            \WP_CLI::error('Give the source site address, starting with https://');
        }

        return $url;
    }

    /**
     * @param array<string, string> $assoc_args
     * @return list<string>
     */
    private function authFlags(array $assoc_args): array
    {
        $flags = [];
        foreach (['secret', 'private-key-path'] as $key) {
            if (isset($assoc_args[$key]) && '' !== $assoc_args[$key]) {
                $flags[] = '--' . $key . '=' . $assoc_args[$key];
            }
        }
        if (isset($assoc_args['insecure'])) {
            $flags[] = '--insecure';
        }

        return $flags;
    }

    /**
     * On a network the admin screens take a super admin (see Access), and the
     * command line reaches the same data, so it asks for one too.
     */
    private function guardNetwork(): void
    {
        if (is_multisite() && ! Access::allowed()) {
            \WP_CLI::error('On a multisite network Migrator runs as a super admin. Add --user=<super admin login>.');
        }
    }
}
