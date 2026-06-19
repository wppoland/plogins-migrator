<?php

declare(strict_types=1);

namespace Migrator\Engine\Archive;

defined('ABSPATH') || exit;

// Archives are multi-gigabyte; gzip is streamed in chunks, never held in memory.
// phpcs:disable WordPress.WP.AlternativeFunctions

/**
 * Optional gzip wrapping for a finished archive. Compression is applied to the
 * whole archive after it is written, and undone into a temp file before reading,
 * so the container format itself never changes and the resumable writer is
 * untouched. The compressible parts of a backup (the SQL dump, themes, plugins,
 * text assets) shrink; already-compressed media simply passes through.
 */
final class Compressor
{
    public const EXT = '.gz';

    private const CHUNK = 4_194_304; // 4 MiB.

    /** gzip magic bytes. */
    private const MAGIC = "\x1f\x8b";

    /**
     * Whether a file looks gzip-compressed (by magic bytes).
     */
    public static function isCompressed(string $path): bool
    {
        $handle = fopen($path, 'rb');
        if (false === $handle) {
            return false;
        }
        $magic = (string) fread($handle, 2);
        fclose($handle);

        return self::MAGIC === $magic;
    }

    /**
     * Compress $src into $dest (gzip). Streams, so memory stays flat.
     *
     * @throws \RuntimeException On I/O failure.
     */
    public function compress(string $src, string $dest): void
    {
        $in = fopen($src, 'rb');
        if (false === $in) {
            throw new \RuntimeException(esc_html('Migrator: cannot read archive to compress: ' . $src));
        }
        $out = gzopen($dest, 'wb6');
        if (false === $out) {
            fclose($in);
            throw new \RuntimeException(esc_html('Migrator: cannot open compressed archive: ' . $dest));
        }

        try {
            while (! feof($in)) {
                $chunk = fread($in, self::CHUNK);
                if (false === $chunk) {
                    throw new \RuntimeException('Migrator: read error while compressing.');
                }
                if ('' !== $chunk && false === gzwrite($out, $chunk)) {
                    throw new \RuntimeException('Migrator: write error while compressing (disk full?).');
                }
            }
        } finally {
            fclose($in);
            gzclose($out);
        }
    }

    /**
     * Decompress a gzip archive $src into $dest. Streams.
     *
     * @throws \RuntimeException On I/O failure.
     */
    public function decompress(string $src, string $dest): void
    {
        $in = gzopen($src, 'rb');
        if (false === $in) {
            throw new \RuntimeException(esc_html('Migrator: cannot open compressed archive: ' . $src));
        }
        $out = fopen($dest, 'wb');
        if (false === $out) {
            gzclose($in);
            throw new \RuntimeException(esc_html('Migrator: cannot write decompressed archive: ' . $dest));
        }

        try {
            while (! gzeof($in)) {
                $chunk = gzread($in, self::CHUNK);
                if (false === $chunk) {
                    throw new \RuntimeException('Migrator: the compressed archive is corrupt.');
                }
                if ('' !== $chunk && (false === fwrite($out, $chunk))) {
                    throw new \RuntimeException('Migrator: write error while decompressing (disk full?).');
                }
            }
        } finally {
            gzclose($in);
            fclose($out);
        }
    }
}
