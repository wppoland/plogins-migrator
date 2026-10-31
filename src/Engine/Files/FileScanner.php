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
     */
    public function __construct(
        private array $excludeNames = [],
        private array $excludeAbsPaths = [],
        private array $excludePatterns = [],
        private int $maxBytes = 0,
    ) {
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

        $iterator = new \RecursiveIteratorIterator($filtered, \RecursiveIteratorIterator::LEAVES_ONLY);

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

        foreach ($this->excludePatterns as $pattern) {
            if (fnmatch(strtolower($pattern), strtolower($file->getFilename()))) {
                return true;
            }
        }

        return false;
    }
}
