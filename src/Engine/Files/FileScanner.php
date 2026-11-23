<?php

declare(strict_types=1);

namespace Migrator\Engine\Files;

defined('ABSPATH') || exit;

/**
 * Walks a directory tree (typically wp-content) yielding the files to archive,
 * pruning excluded directories as it descends so it never wastes time inside,
 * say, node_modules or the plugin's own backup folder.
 *
 * Symlinks are skipped: following them risks loops and copying files from
 * outside the site root into the archive.
 *
 * The yielded order is the filesystem's own; callers that need to resume across
 * requests should persist the list once and walk it by offset (the archive
 * pipeline does this) rather than re-scanning.
 */
final class FileScanner
{
    /**
     * @param string[] $excludeNames    Basenames pruned anywhere in the tree
     *                                   (e.g. 'node_modules', '.git', 'cache').
     * @param string[] $excludeAbsPaths Absolute path prefixes pruned entirely
     *                                   (e.g. the backups workspace).
     * @param string[] $excludePatterns fnmatch() patterns matched against a
     *                                   file's name (e.g. '*.log', '*.zip').
     * @param int      $maxBytes        Files larger than this are skipped; 0 = no limit.
     * @param int      $maxLogBytes     .log files larger than this are skipped; 0 = no limit.
     */
    public function __construct(
        private array $excludeNames = [],
        private array $excludeAbsPaths = [],
        private array $excludePatterns = [],
        private int $maxBytes = 0,
        private int $maxLogBytes = 0,
    ) {
    }

    /** A file above this size is named before a backup: it slows the export and the upload. */
    public const LARGE_BYTES = 100 * 1024 * 1024;

    /** Archive paths longer than this fail to extract on some systems. */
    public const LONG_PATH = 250;

    /**
     * Anything here that a backup should not carry to another host without
     * someone deciding to: credentials, private keys, copies of wp-config.php,
     * database dumps and other backups. .env.example and its kin are templates.
     */
    private const SENSITIVE = '/^(\.env(\.(?!example$|sample$|dist$|template$)[a-z0-9_.-]+)?|\.htpasswd|id_(rsa|dsa|ecdsa|ed25519)|wp-config\.php(\..+)?|wp-config[-_.].+\.php|backup.*\.zip)$/';

    /**
     * Database dumps and key files. Plugins and themes ship public certificate
     * bundles (cacert.pem) and install schemas, so these count only outside them.
     */
    private const SENSITIVE_DATA = '/\.(sql|sql\.gz|pem|key)$/';

    /**
     * What a file in the export deserves a warning for: sensitive, large,
     * long (path), encoding (name not UTF-8) or unreadable.
     *
     * @param string $rel Path relative to wp-content.
     *
     * @return list<string>
     */
    public static function concerns(string $rel, int $size, bool $readable): array
    {
        $name = strtolower(basename($rel));
        $code = (bool) preg_match('#^(plugins|themes|mu-plugins)/#', $rel);
        $out  = [];

        if ((preg_match(self::SENSITIVE, $name) && 'wp-config-sample.php' !== $name)
            || (! $code && preg_match(self::SENSITIVE_DATA, $name))
            || preg_match('#(^|/)\.git/#', $rel)) {
            $out[] = 'sensitive';
        }
        if ($size > self::LARGE_BYTES) {
            $out[] = 'large';
        }
        if (strlen('wp-content/' . $rel) > self::LONG_PATH) {
            $out[] = 'long';
        }
        if (1 !== preg_match('//u', $rel)) {
            $out[] = 'encoding';
        }
        if (! $readable) {
            $out[] = 'unreadable';
        }

        return $out;
    }

    /**
     * Walk the export set and collect what concerns() flags. A .git folder
     * (backed up only when a site filters it back in) is reported once.
     *
     * @return list<array{kind: string, path: string, size: int}>
     */
    public function findings(string $base, int $limit = 200): array
    {
        $out  = [];
        $seen = [];
        foreach ($this->scan($base) as $file) {
            foreach (self::concerns($file['rel'], $file['size'], is_readable($file['abs'])) as $kind) {
                $path = 'sensitive' === $kind && preg_match('#^(.*?\.git)/#', $file['rel'], $m) ? $m[1] : $file['rel'];
                if (isset($seen[$kind . ':' . $path])) {
                    continue;
                }
                $seen[$kind . ':' . $path] = true;
                $out[] = ['kind' => $kind, 'path' => $path, 'size' => $file['size']];
                if (count($out) >= $limit) {
                    return $out;
                }
            }
        }

        return $out;
    }

    /**
     * A finding as a sentence for the export screen and WP-CLI.
     *
     * @param array{kind: string, path: string, size: int} $finding
     */
    public static function describe(array $finding): string
    {
        $path = 'wp-content/' . self::printable($finding['path']);

        return match ($finding['kind']) {
            /* translators: %s: file path */
            'sensitive' => sprintf(__('%s may hold passwords, keys or a copy of a database.', 'plogins-migrator'), $path),
            /* translators: 1: file path, 2: file size */
            'large'     => sprintf(__('%1$s is %2$s, which makes the backup slow to create and to upload.', 'plogins-migrator'), $path, size_format($finding['size'])),
            /* translators: %s: file path */
            'long'      => sprintf(__('%s has a path longer than 250 characters, which some systems cannot extract.', 'plogins-migrator'), $path),
            /* translators: %s: file path, with the bytes that are not UTF-8 shown as ? */
            'encoding'  => sprintf(__('%s has a name that is not valid UTF-8 and may not restore on another server.', 'plogins-migrator'), $path),
            /* translators: %s: file path */
            default     => sprintf(__('%s cannot be read by the web server and will be left out.', 'plogins-migrator'), $path),
        };
    }

    /** A path safe to put in JSON and on screen: bytes that are not UTF-8 become "?". */
    public static function printable(string $path): string
    {
        return 1 === preg_match('//u', $path) ? $path : (string) preg_replace('/[\x80-\xFF]/', '?', $path);
    }

    /**
     * @return \Generator<array{rel: string, abs: string, size: int}>
     */
    public function scan(string $base): \Generator
    {
        $base = rtrim(str_replace('\\', '/', $base), '/');
        if (! is_dir($base)) {
            return;
        }

        $excludeNames    = $this->excludeNames;
        $excludeAbsPaths = array_map(
            static fn (string $p): string => rtrim(str_replace('\\', '/', $p), '/'),
            $this->excludeAbsPaths,
        );

        $directory = new \RecursiveDirectoryIterator(
            $base,
            \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::UNIX_PATHS,
        );

        $filtered = new \RecursiveCallbackFilterIterator(
            $directory,
            static function (\SplFileInfo $current) use ($excludeNames, $excludeAbsPaths): bool {
                if ($current->isLink()) {
                    return false;
                }
                if (in_array($current->getFilename(), $excludeNames, true)) {
                    return false;
                }

                $path = str_replace('\\', '/', $current->getPathname());
                foreach ($excludeAbsPaths as $prefix) {
                    if ($path === $prefix || str_starts_with($path, $prefix . '/')) {
                        return false;
                    }
                }

                return true;
            },
        );

        // A directory that vanishes mid-scan (a cache being purged) or cannot
        // be opened is skipped instead of ending the whole backup.
        $iterator = new \RecursiveIteratorIterator($filtered, \RecursiveIteratorIterator::LEAVES_ONLY, \RecursiveIteratorIterator::CATCH_GET_CHILD);

        $prefixLen = strlen($base) + 1;
        foreach ($iterator as $file) {
            if (! $file instanceof \SplFileInfo || ! $file->isFile()) {
                continue;
            }

            if ($this->excluded($file)) {
                continue;
            }

            $abs = str_replace('\\', '/', $file->getPathname());

            yield [
                'rel'  => substr($abs, $prefixLen),
                'abs'  => $abs,
                'size' => (int) $file->getSize(),
            ];
        }
    }

    private function excluded(\SplFileInfo $file): bool
    {
        if ($this->maxBytes > 0 && $file->getSize() > $this->maxBytes) {
            return true;
        }

        if ($this->maxLogBytes > 0 && str_ends_with(strtolower($file->getFilename()), '.log') && $file->getSize() > $this->maxLogBytes) {
            return true;
        }

        foreach ($this->excludePatterns as $pattern) {
            if (fnmatch(strtolower($pattern), strtolower($file->getFilename()))) {
                return true;
            }
        }

        return false;
    }
}
