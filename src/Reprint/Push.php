<?php

declare(strict_types=1);

namespace Migrator\Reprint;

use Migrator\Support\Workspace;

defined('ABSPATH') || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped, WordPress.WP.AlternativeFunctions -- WP-CLI only: messages go to a terminal, and the files are copied in bulk outside any request.

/**
 * Sends this site's wp-content back to the site it was pulled from.
 *
 * Reprint pushes the tree it pulled into, and only the files that differ from
 * what it last saw on the source. So the live wp-content is first copied over
 * that tree, then the client's files-push sends the difference. The database is
 * not sent here: Reprint pushes a database only to a host that runs its API on
 * a standalone route, which a plugin cannot set up. `wp migrator reprint
 * db-push` covers that host.
 */
final class Push
{
    /** files-push stops at a time or memory boundary with exit 2; rerun it. */
    private const MAX_ROUNDS = 50;

    /** @var callable(string):void */
    private $log;

    /**
     * @param callable(string):void $log
     */
    public function __construct(private Client $client, callable $log)
    {
        $this->log = $log;
    }

    /**
     * @param list<string> $auth
     * @return int Files copied into the push tree.
     */
    public function run(string $url, array $auth): int
    {
        $dirs = $this->client->dirs($url);
        $url  = Client::apiUrl($url);
        if (! is_dir($dirs['state'] . '/remotes')) {
            throw new \RuntimeException('Pull this site from ' . $url . ' first: a push sends back changes to a tree that was pulled.');
        }

        $insecure = in_array('--insecure', $auth, true) ? ['--insecure'] : [];
        $out      = $this->client->capture(array_merge(
            ['pull-metadata', $url, '--state-dir=' . $dirs['state'], '--fs-root=' . $dirs['files']],
            $insecure,
        ));
        $first   = strtok($out, "\n");
        $meta    = json_decode(false === $first ? '' : $first, true);
        $content = is_array($meta) ? (string) ($meta['sourceSite']['contentDirectory'] ?? '') : '';
        if ('' === $content) {
            throw new \RuntimeException('Could not read the pulled site details. Run the pull again, then push.');
        }

        ($this->log)('Collecting this site\'s changes…');
        $copied = $this->mirrorContent(untrailingslashit((string) WP_CONTENT_DIR), $dirs['files'] . untrailingslashit($content));
        ($this->log)(sprintf('%d files changed here since the pull.', $copied));

        $base = ['--state-dir=' . $dirs['state'], '--fs-root=' . $dirs['files'], '--progress=compact'];
        for ($round = 1; $round <= self::MAX_ROUNDS; $round++) {
            $code = $this->client->run(array_merge(['files-push', $url], $base, $auth));
            if (Client::EXIT_AGAIN !== $code) {
                break;
            }
        }

        if (0 !== $code) {
            throw new \RuntimeException('The files push stopped (Reprint exit code ' . $code . '). The output above says why; run the same command again to continue.');
        }

        return $copied;
    }

    /**
     * Make the push tree's wp-content match this one, skipping Migrator and its
     * backups, which must never travel to the source.
     */
    private function mirrorContent(string $from, string $to): int
    {
        $skip = [$from . '/' . Workspace::DIR_NAME, untrailingslashit(\Migrator\PLUGIN_DIR)];
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
            $path = $item->getPathname();
            foreach ($skip as $protected) {
                if ($path === $protected || str_starts_with($path, $protected . '/')) {
                    continue 2;
                }
            }

            $target = $to . substr($path, strlen($from));
            if ($item->isDir()) {
                wp_mkdir_p($target);
                continue;
            }
            if (is_file($target) && filesize($target) === $item->getSize() && filemtime($target) === $item->getMTime()) {
                continue;
            }
            if (! copy($path, $target)) {
                throw new \RuntimeException('Could not copy ' . $path . ' into the push tree.');
            }
            touch($target, $item->getMTime());
            $copied++;
        }

        return $copied;
    }
}
