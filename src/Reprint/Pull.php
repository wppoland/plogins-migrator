<?php

declare(strict_types=1);

namespace Migrator\Reprint;

use Migrator\Engine\Db\Dumper;
use Migrator\Engine\Db\SearchReplace;
use Migrator\Engine\Import\Importer;
use Migrator\Engine\Transform\SerializedReplacer;
use Migrator\Support\Workspace;

defined('ABSPATH') || exit;

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
    public function run(string $url, array $auth, bool $withFiles, bool $withDatabase): array
    {
        $this->workspace->ensure();
        $dirs = $this->client->dirs($url);
        $url  = Client::apiUrl($url);
        foreach ($dirs as $dir) {
            wp_mkdir_p($dir);
        }
        $base = ['--state-dir=' . $dirs['state'], '--fs-root=' . $dirs['files'], '--progress=compact'];

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
            ($this->log)('Downloading wp-content…');
            $code = $this->client->run(array_merge(
                ['pull-files', $url, '--include=' . $content, '--exclude=' . $content . '/' . Workspace::DIR_NAME],
                $base,
                $auth,
            ));
            $this->stopOn($code, 'pull-files');
        }

        if ($withDatabase) {
            ($this->log)('Downloading the database…');
            $code = $this->client->run(array_merge(['db-pull', $url], $base, $auth));
            $this->stopOn($code, 'db-pull');
            $this->applyDatabase($url, $base, $auth, $source);
        }

        $files = 0;
        if ($withFiles) {
            ($this->log)('Copying wp-content into this site…');
            $files = $this->copyContent($dirs['files'] . $content, untrailingslashit(WP_CONTENT_DIR));
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
        if ('' !== (string) DB_PASSWORD) {
            $args[] = '--target-pass=' . DB_PASSWORD;
        }

        ($this->log)('Replacing the database and rewriting addresses…');
        $code = $this->client->run($args);
        if (0 !== $code) {
            ($this->log)('The import failed, restoring the previous database…');
            if (! $importer->restoreDatabase($rollback)) {
                throw new \RuntimeException('The database import failed AND the rollback failed. The previous database is kept at ' . $rollback . ' and must be imported by hand.');
            }
            throw new \RuntimeException('The database import failed (Reprint exit code ' . $code . ') and the previous database was put back. Run the same command again to retry.');
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
     * Copy the pulled wp-content over this one. A file is copied only when its
     * size or modification time differs, so a delta pull copies only what the
     * delta brought. Files that exist only here are left alone.
     */
    private function copyContent(string $from, string $to): int
    {
        if (! is_dir($from)) {
            throw new \RuntimeException('The pulled files have no wp-content folder at ' . $from . '.');
        }

        $skip = [
            $to . '/' . Workspace::DIR_NAME,
            untrailingslashit(\Migrator\PLUGIN_DIR),
        ];
        if (defined('Migrator\\Pro\\PLUGIN_FILE')) {
            $skip[] = dirname((string) constant('Migrator\\Pro\\PLUGIN_FILE'));
        }

        $copied = 0;
        $items  = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($from, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );

        foreach ($items as $item) {
            /** @var \SplFileInfo $item */
            $relative = substr($item->getPathname(), strlen($from));
            $target   = $to . $relative;

            foreach ($skip as $protected) {
                if ($target === $protected || str_starts_with($target, $protected . '/')) {
                    continue 2;
                }
            }

            if ($item->isDir()) {
                wp_mkdir_p($target);
                continue;
            }

            if (is_file($target) && filesize($target) === $item->getSize() && filemtime($target) === $item->getMTime()) {
                continue;
            }

            if (! copy($item->getPathname(), $target)) {
                throw new \RuntimeException('Could not write ' . $target . '. Check that the web server user can write to wp-content.');
            }
            touch($target, $item->getMTime());
            $copied++;
        }

        return $copied;
    }

    private function stopOn(int $code, string $stage): void
    {
        if (0 === $code) {
            return;
        }
        if (Client::EXIT_ENROLL === $code) {
            throw new \RuntimeException('The source site does not know this site yet. Enrol the key printed above on the source under Tools > Reprint Server, or pass --secret=<token>, then run the same command again.');
        }
        if (Client::EXIT_AGAIN === $code) {
            throw new \RuntimeException('The ' . $stage . ' step was interrupted. Run the same command again: it carries on where it stopped.');
        }
        throw new \RuntimeException('The ' . $stage . ' step failed (Reprint exit code ' . $code . '). Nothing on this site has been changed by it; the output above says why.');
    }
}
