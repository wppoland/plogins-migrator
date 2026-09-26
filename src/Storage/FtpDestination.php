<?php

declare(strict_types=1);

namespace Migrator\Storage;

defined('ABSPATH') || exit;

/**
 * Off-site backups to an FTP or FTPS server. Uploads stream straight from the
 * archive on disk via ext-ftp's {@see ftp_put()}, so a multi-gigabyte backup is
 * never loaded into memory. Listing and pruning use the same connection.
 *
 * Requires the PHP FTP extension; {@see self::available()} reports whether it is
 * present so the UI can explain a missing requirement instead of failing late.
 */
final class FtpDestination implements BackupDestination
{
    private string $path;

    public function __construct(
        private string $host,
        private int $port,
        private string $username,
        private string $password,
        string $path = '',
        private bool $passive = true,
        private bool $secure = false,
    ) {
        $this->host = trim($host);
        $this->path = '/' . trim(trim($path), '/');
    }

    public static function available(): bool
    {
        return function_exists('ftp_connect') && function_exists('ftp_put');
    }

    public function id(): string
    {
        return 'ftp';
    }

    public function label(): string
    {
        return $this->secure ? __('FTPS server', 'plogins-migrator') : __('FTP server', 'plogins-migrator');
    }

    public function isConfigured(): bool
    {
        return self::available()
            && '' !== $this->host
            && '' !== $this->username
            && '' !== $this->password;
    }

    public function store(string $archivePath): void
    {
        if (! is_readable($archivePath)) {
            throw new \RuntimeException(esc_html('Source archive is not readable: ' . $archivePath));
        }

        $conn = $this->connect();
        try {
            $this->ensureDir($conn);
            $remote = $this->remote(basename($archivePath));
            // Upload to a temp name, then rename, so a reader never sees a
            // half-written archive at the final path.
            $temp = $remote . '.part';
            if (! ftp_put($conn, $temp, $archivePath, FTP_BINARY)) {
                throw new \RuntimeException(esc_html('FTP upload failed for: ' . basename($archivePath)));
            }
            if (! ftp_rename($conn, $temp, $remote)) {
                @ftp_delete($conn, $temp); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
                throw new \RuntimeException(esc_html('Could not finalise the FTP upload for: ' . basename($archivePath)));
            }
        } finally {
            ftp_close($conn);
        }
    }

    public function prune(int $retention): int
    {
        $archives = $this->archives();
        if ([] === $archives) {
            return 0;
        }

        $conn = $this->connect();
        try {
            foreach (array_slice($archives, $retention) as $old) {
                @ftp_delete($conn, $this->remote($old['file'])); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
            }
        } finally {
            ftp_close($conn);
        }

        return min(count($archives), $retention);
    }

    public function archives(): array
    {
        $conn = $this->connect();
        try {
            $names = ftp_nlist($conn, $this->path);
            if (false === $names) {
                return [];
            }

            $items = [];
            foreach ($names as $name) {
                $base = basename($name);
                if (! str_contains($base, '.migrator') || str_ends_with($base, '.part')) {
                    continue;
                }
                $remote = $this->remote($base);
                $size   = ftp_size($conn, $remote);
                $mtime  = ftp_mdtm($conn, $remote);
                $items[] = [
                    'file'  => $base,
                    'bytes' => $size > 0 ? $size : 0,
                    'time'  => $mtime > 0 ? $mtime : 0,
                ];
            }
        } finally {
            ftp_close($conn);
        }

        usort($items, static fn (array $a, array $b): int => $b['time'] <=> $a['time']);

        return $items;
    }

    /**
     * Open and authenticate a connection, or throw.
     *
     * @return \FTP\Connection
     */
    private function connect()
    {
        $port = $this->port > 0 ? $this->port : 21;
        $conn = $this->secure
            ? (function_exists('ftp_ssl_connect') ? ftp_ssl_connect($this->host, $port, 20) : false)
            : ftp_connect($this->host, $port, 20);

        if (false === $conn) {
            throw new \RuntimeException(esc_html('Could not connect to the FTP server: ' . $this->host));
        }
        if (! ftp_login($conn, $this->username, $this->password)) {
            ftp_close($conn);
            throw new \RuntimeException(esc_html__('FTP login failed: check the username and password.', 'plogins-migrator'));
        }
        ftp_pasv($conn, $this->passive);

        return $conn;
    }

    /**
     * Create the destination directory if needed, segment by segment.
     *
     * @param \FTP\Connection $conn
     */
    private function ensureDir($conn): void
    {
        if ('/' === $this->path) {
            return;
        }
        if (@ftp_chdir($conn, $this->path)) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
            return;
        }

        $built = '';
        foreach (array_filter(explode('/', $this->path)) as $segment) {
            $built .= '/' . $segment;
            if (! @ftp_chdir($conn, $built)) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
                @ftp_mkdir($conn, $built); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
            }
        }
    }

    private function remote(string $file): string
    {
        return ('/' === $this->path ? '' : $this->path) . '/' . $file;
    }
}
