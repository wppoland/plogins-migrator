<?php

declare(strict_types=1);

namespace Migrator\Reprint;

use Migrator\Support\Workspace;

defined('ABSPATH') || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped, WordPress.WP.AlternativeFunctions -- WP-CLI only: messages go to a terminal, and the files are copied in bulk outside any request.

/**
 * The target role: runs the bundled Reprint client against a remote site.
 *
 * The client is a command-line program built to run without WordPress loaded,
 * so it runs in its own PHP process. That also keeps its classes out of this
 * request, where the standalone Reprint plugin may have loaded other copies.
 * Only WP-CLI calls this: a pull takes as long as the site is big.
 */
final class Client
{
    /** Exit code the client uses for "enrol the printed key, then run again". */
    public const EXIT_ENROLL = 4;

    /** Exit code for "partial or interrupted, run again". */
    public const EXIT_AGAIN = 2;

    private const BIN = '/lib/vendor/reprint/packages/reprint-client/bin/reprint-client';

    public function __construct(private Workspace $workspace)
    {
    }

    /**
     * The endpoint address. Only the client's `pull` adds ?reprint-api by
     * itself; the lower-level commands take the address as given.
     */
    public static function apiUrl(string $url): string
    {
        if (str_contains($url, 'reprint-api')) {
            return $url;
        }

        return $url . (str_contains($url, '?') ? '&' : '?') . 'reprint-api';
    }

    /**
     * Per-remote state, kept inside the backups folder, which is already
     * protected from web access and from being restored over.
     *
     * @return array{state: string, files: string, site: string}
     */
    public function dirs(string $url): array
    {
        $host = (string) wp_parse_url($url, PHP_URL_HOST);
        $slug = sanitize_key($host) . '-' . substr(md5(self::apiUrl(untrailingslashit($url))), 0, 8);
        $base = $this->workspace->path('reprint/' . $slug);

        return [
            'state' => $base . '/state',
            'files' => $base . '/files',
            'site'  => $base . '/site',
        ];
    }

    /**
     * Run one client command with stdout and stderr passed straight through,
     * so the client's own progress output reaches the terminal.
     *
     * @param list<string>          $args Command and its arguments, unescaped.
     * @param array<string, string> $env  Extra environment, e.g. credentials
     *                                    that must not appear in the process list.
     */
    public function run(array $args, array $env = []): int
    {
        $command = array_merge([PHP_BINARY, \Migrator\PLUGIN_DIR . self::BIN], $args);

        // phpcs:ignore Generic.PHP.ForbiddenFunctions.Found -- WP-CLI only: the client is a separate command-line program.
        $process = proc_open($command, [STDIN, STDOUT, STDERR], $pipes, null, array_merge(getenv(), $env));
        if (! is_resource($process)) {
            throw new \RuntimeException('Could not start the Reprint client. proc_open is disabled on this server.');
        }

        return proc_close($process);
    }

    /**
     * Run a command and return its standard output (for JSON reports).
     *
     * @param list<string> $args
     */
    public function capture(array $args): string
    {
        $command = array_merge([PHP_BINARY, \Migrator\PLUGIN_DIR . self::BIN], $args);

        // phpcs:ignore Generic.PHP.ForbiddenFunctions.Found -- WP-CLI only, see run().
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => STDERR], $pipes);
        if (! is_resource($process)) {
            throw new \RuntimeException('Could not start the Reprint client. proc_open is disabled on this server.');
        }
        $out = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        proc_close($process);

        return $out;
    }
}
