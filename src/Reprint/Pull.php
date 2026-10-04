<?php

declare(strict_types=1);

namespace Migrator\Reprint;

use Migrator\Engine\Db\Dumper;
use Migrator\Engine\Db\SearchReplace;
use Migrator\Engine\Import\Importer;
use Migrator\Engine\Transform\SerializedReplacer;
use Migrator\Support\Workspace;

defined('ABSPATH') || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped, WordPress.WP.AlternativeFunctions -- WP-CLI only: messages go to a terminal, and the files are copied in bulk outside any request.

/**
 * Pulls a remote site into THIS install: its database and its wp-content.
 *
 * WordPress core and wp-config.php stay this site's own, as in a restore from an
 * archive. The Reprint client downloads into a private folder under the backups
 * directory and keeps its index there, so running the same pull again resumes
 * an interrupted one or, after a finished one, fetches only what changed.
 *
 * Order of work, chosen so a failure leaves something usable:
 * 1. files and the SQL dump are downloaded (nothing local changes yet),
 * 2. the database is dumped as it is, then replaced; on failure it is put back,
 * 3. wp-content is copied over, skipping Migrator itself and its backups.
 */
final class Pull
{
    /** @var callable(string):void */
    private $log;

    /**
     * @param callable(string):void $log
     */
    public function __construct(
        private Client $client,
        private Workspace $workspace,
        private \wpdb $db,
        callable $log,
    ) {
        $this->log = $log;
    }

    /**
     * @param list<string> $auth Credential flags passed through to the client
     *                           (--secret, --private-key-path, --insecure).
     * @return array{files: int, database: bool}
     */
    public function run(string $url, array $auth, bool $withFiles, bool $withDatabase, bool $hostPlugins = false): array
    {
        $this->workspace->ensure();
        $dirs = $this->client->dirs($url);
        $url  = Client::apiUrl($url);
        foreach ($dirs as $dir) {
            wp_mkdir_p($dir);
        }
        $base = ['--state-dir=' . $dirs['state'], '--fs-root=' . $dirs['files'], '--progress=compact'];

        // The old host's own must-use plugins (caching, staging tools, login
        // bridges) rarely work anywhere else; leave them behind unless asked.
        $hostFlag = $hostPlugins ? '--include-host-plugins' : '--exclude-host-plugins';

        ($this->log)('Checking the source site…');
        $code = $this->client->run(array_merge(['preflight', $url], $base, $auth));
        $this->stopOn($code, 'preflight');

        $source = $this->sourceSite($url, $dirs, $auth);
        if ($withDatabase && $source['tablePrefix'] !== $this->db->prefix) {
            throw new \RuntimeException(sprintf(
                'Table prefix mismatch: the source uses "%1$s" and this site uses "%2$s". Set $table_prefix to "%1$s" in wp-config.php and run the pull again; nothing has been changed here.',
                $source['tablePrefix'],
                $this->db->prefix,
            ));
        }

        $content = untrailingslashit($source['contentDirectory']);
        if ($withFiles) {
            $this->checkSpace($url, $base, $auth, $dirs['state'], $dirs['files']);
        }
        if ($withFiles) {
            ($this->log)('Downloading wp-content…');
            $code = $this->client->run(array_merge(
                ['pull-files', $url, '--include=' . $content, '--exclude=' . $content . '/' . Workspace::DIR_NAME, $hostFlag],
                $base,
                $auth,
            ));
            $this->stopOn($code, 'pull-files');
        }

        if ($withDatabase) {
            ($this->log)('Downloading the database…');
            $code = $this->client->run(array_merge(['db-pull', $url], $base, $auth));
            $this->stopOn($code, 'db-pull');
            $this->applyDatabase($url, array_merge($base, [$hostFlag]), $auth, $source);
        }

        $files = 0;
        if ($withFiles) {
            ($this->log)('Copying wp-content into this site…');
            $files = Mirror::copy($dirs['files'] . $content, untrailingslashit(WP_CONTENT_DIR));
        }

        wp_cache_flush();

        return ['files' => $files, 'database' => $withDatabase];
    }

    /**
     * @param list<string> $base
     * @param list<string> $auth
     * @param array{tablePrefix: string, contentDirectory: string, homeUrl: string, siteUrl: string} $source
     */
    private function applyDatabase(string $url, array $base, array $auth, array $source): void
    {
        $importer = new Importer($this->workspace, $this->db);
        $rollback = $importer->backupDatabase($this->log);

        $target = [
            'home'    => (string) get_option('home'),
            'siteurl' => (string) get_option('siteurl'),
        ];
        $active = (array) get_option('active_plugins', []);

        [$host, $port] = $this->dbHostPort();
        $args = array_merge(['db-apply', $url], $base, $auth, [
            '--target-engine=mysql',
            '--target-host=' . $host,
            '--target-port=' . $port,
            '--target-user=' . DB_USER,
            '--target-db=' . DB_NAME,
            '--new-site-url=' . untrailingslashit($target['home']),
        ]);

        // --new-site-url only maps the address this pull connected to. A source
        // reached by another name (an IP, a staging alias, a Docker host) keeps
        // its real home and siteurl in the content, so map those too.
        foreach (['homeUrl' => 'home', 'siteUrl' => 'siteurl'] as $from => $to) {
            $old = untrailingslashit($source[$from]);
            if ('' !== $old && $old !== untrailingslashit($target[$to])) {
                array_push($args, '--rewrite-url', $old, untrailingslashit($target[$to]));
            }
        }

        ($this->log)('Replacing the database and rewriting addresses…');
        // Through the environment, not --target-pass: an argument shows in the
        // process list to every user on the server (Reprint issue #24).
        $code = $this->client->run($args, ['MYSQL_PASSWORD' => (string) DB_PASSWORD]);
        if (0 !== $code) {
            ($this->log)('The import failed, restoring the previous database…');
            if (! $importer->restoreDatabase($rollback)) {
                throw new \RuntimeException('The database import failed AND the rollback failed. The previous database is kept at ' . $rollback . ' and must be imported by hand.');
            }
            throw new \RuntimeException('The database import failed (exit code ' . $code . ') and the previous database was put back. Run the same command again to retry.');
        }

        // home and siteurl are known exactly; with WordPress in a subfolder a
        // prefix rewrite of one can land the other on the wrong address.
        foreach ($target as $option => $value) {
            $this->db->update($this->db->options, ['option_value' => $value], ['option_name' => $option]);
        }

        // Reprint rewrites addresses; server paths stored in options and meta
        // (upload_path, page builder caches) still name the source's folders.
        $fromContent = untrailingslashit($source['contentDirectory']);
        $toContent   = untrailingslashit((string) WP_CONTENT_DIR);
        if ($fromContent !== $toContent) {
            $dumper = new Dumper($this->db);
            (new SearchReplace($this->db, new SerializedReplacer([$fromContent], [$toContent])))->run($dumper->tables());
        }

        // The source may have run Reprint through its own plugin rather than
        // Migrator. Keep Migrator active here so the next pull still works.
        $self = plugin_basename(\Migrator\PLUGIN_FILE);
        $now  = maybe_unserialize((string) $this->db->get_var($this->db->prepare(
            "SELECT option_value FROM {$this->db->options} WHERE option_name = %s",
            'active_plugins',
        )));
        $now = is_array($now) ? $now : [];
        if (in_array($self, $active, true) && ! in_array($self, $now, true)) {
            $now[] = $self;
            $this->db->update($this->db->options, ['option_value' => serialize($now)], ['option_name' => 'active_plugins']); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- option_value is stored serialized.
        }

        wp_delete_file($rollback);
    }

    /**
     * DB_HOST may carry a port or a socket ("db:3307", "localhost:/tmp/m.sock").
     *
     * @return array{0: string, 1: string}
     */
    private function dbHostPort(): array
    {
        $parsed = $this->db->parse_db_host(DB_HOST);
        $host   = is_array($parsed) && '' !== (string) $parsed[0] ? (string) $parsed[0] : 'localhost';
        $port   = is_array($parsed) && ! empty($parsed[1]) ? (string) $parsed[1] : '3306';

        return [$host, $port];
    }

    /**
     * @param array{state: string, files: string, site: string} $dirs
     * @param list<string>                                      $auth
     * @return array{tablePrefix: string, contentDirectory: string, homeUrl: string, siteUrl: string}
     */
    private function sourceSite(string $url, array $dirs, array $auth): array
    {
        $insecure = in_array('--insecure', $auth, true) ? ['--insecure'] : [];
        $out      = $this->client->capture(array_merge(
            ['pull-metadata', $url, '--state-dir=' . $dirs['state'], '--fs-root=' . $dirs['files']],
            $insecure,
        ));
        $first = strtok($out, "\n");
        $meta  = json_decode(false === $first ? '' : $first, true);
        $site  = is_array($meta) && is_array($meta['sourceSite'] ?? null) ? $meta['sourceSite'] : [];

        if (! isset($site['tablePrefix'], $site['contentDirectory'])) {
            throw new \RuntimeException('Could not read the source site details after preflight.');
        }

        return [
            'tablePrefix'      => (string) $site['tablePrefix'],
            'contentDirectory' => (string) $site['contentDirectory'],
            'homeUrl'          => (string) ($site['homeUrl'] ?? ''),
            'siteUrl'          => (string) ($site['siteUrl'] ?? ''),
        ];
    }

    /**
     * Refuse a first pull this disk cannot hold (Reprint issue #25). The pulled
     * files are stored once in the private copy and once in wp-content, so the
     * check asks for twice the source's size (core included, so it errs on the
     * safe side).
     *
     * @param list<string> $base
     * @param list<string> $auth
     */
    private function checkSpace(string $url, array $base, array $auth, string $state, string $files): void
    {
        // Only before a first pull: files-index runs once per state, and a
        // delta brings what changed, which is rarely the size of the site.
        if (is_dir($files) && [] !== array_diff((array) scandir($files), ['.', '..'])) {
            return;
        }

        $code = $this->client->run(array_merge(['files-index', $url], $base, $auth));
        $this->stopOn($code, 'files-index');

        $out   = $this->client->capture(array_merge(['files-stats', $url], array_slice($base, 0, 2), in_array('--insecure', $auth, true) ? ['--insecure'] : []));
        $json  = substr($out, 0, (int) strpos($out, "\n}") + 2);
        $stats = json_decode($json, true);
        $bytes = is_array($stats) ? (int) ($stats['indexed']['bytes'] ?? 0) : 0;
        $free  = @disk_free_space($state);

        if (false !== $free && $bytes > 0 && $free < $bytes * 2) {
            throw new \RuntimeException(sprintf(
                'Not enough disk space: the source holds %1$s, and needs about %2$s free (a private copy plus wp-content), but only %3$s is free. Nothing has been changed here.',
                size_format($bytes),
                size_format($bytes * 2),
                size_format((int) $free),
            ));
        }
        ($this->log)(sprintf('%s to download, %s free.', size_format($bytes), false === $free ? '?' : size_format((int) $free)));
    }

    private function stopOn(int $code, string $stage): void
    {
        if (0 === $code) {
            return;
        }
        if (Client::EXIT_ENROLL === $code) {
            throw new \RuntimeException('The source site does not know this site yet. Enrol the key printed above on the Pull and Push screen of the source, or pass --secret=<token>, then run the same command again.');
        }
        if (Client::EXIT_AGAIN === $code) {
            throw new \RuntimeException('The ' . $stage . ' step was interrupted. Run the same command again: it carries on where it stopped.');
        }
        throw new \RuntimeException('The ' . $stage . ' step failed (exit code ' . $code . '). Nothing on this site has been changed by it; the output above says why.');
    }
}
