<?php

declare(strict_types=1);

namespace Migrator\Engine\Archive;

defined('ABSPATH') || exit;

/**
 * Re-packs a stored backup as a standard .tar.gz, streamed, so it opens with
 * `tar -xzf`, 7-Zip or the desktop archive tool without Migrator installed.
 * The .migrator container stays the format Migrator restores from; this is a
 * read-only copy of the same entries (database.sql, the manifest, wp-content).
 *
 * POSIX ustar headers, with a pax extended header for a path over 100 bytes or
 * an entry of 8 GiB and more. Output is gzip via deflate_add(), chunk by chunk,
 * so memory stays flat whatever the backup size.
 */
final class TarExport
{
    public const EXT = '.tar.gz';

    private const BLOCK = 512;

    /** Largest size the 11-digit octal field holds (8 GiB - 1). */
    private const MAX_OCTAL_SIZE = 0o77777777777;

    /**
     * Stream $path (a .migrator or .migrator.gz) as .tar.gz bytes into $sink.
     * The reader is opened and the first entry read before the first byte goes
     * out, so a file that is not a readable backup (an encrypted one, a foreign
     * file) throws while the caller can still answer with an error.
     *
     * @param callable(string):void $sink
     * @throws \RuntimeException When the backup cannot be read.
     */
    public static function stream(string $path, callable $sink): void
    {
        $reader = new Reader(Compressor::isCompressed($path) ? 'compress.zlib://' . $path : $path);
        $gzip   = deflate_init(ZLIB_ENCODING_GZIP, ['level' => 6]);
        if (false === $gzip) {
            $reader->close();
            throw new \RuntimeException('Migrator: zlib could not start a gzip stream.');
        }
        $out = static function (string $bytes, int $flush = ZLIB_NO_FLUSH) use ($gzip, $sink): void {
            $packed = (string) deflate_add($gzip, $bytes, $flush);
            if ('' !== $packed) {
                $sink($packed);
            }
        };

        try {
            while (null !== ($entry = $reader->nextEntry())) {
                $out(self::header($entry));
                $reader->streamTo($out);
                $tail = $entry->size % self::BLOCK;
                if (0 !== $tail) {
                    $out(str_repeat("\0", self::BLOCK - $tail));
                }
            }
            // Two zero blocks end a tar archive.
            $out(str_repeat("\0", 2 * self::BLOCK), ZLIB_FINISH);
        } finally {
            $reader->close();
        }
    }

    private static function header(Entry $entry): string
    {
        $pax = '';
        if (strlen($entry->path) > 100) {
            $pax .= self::paxRecord('path', $entry->path);
        }
        if ($entry->size > self::MAX_OCTAL_SIZE) {
            $pax .= self::paxRecord('size', (string) $entry->size);
        }

        $head = '';
        if ('' !== $pax) {
            $head = self::block('PaxHeader', strlen($pax), $entry->mtime, 'x') . $pax
                . str_repeat("\0", (self::BLOCK - strlen($pax) % self::BLOCK) % self::BLOCK);
        }

        return $head . self::block($entry->path, min($entry->size, self::MAX_OCTAL_SIZE), $entry->mtime, '0');
    }

    /**
     * One pax record: "<length> <key>=<value>\n", where length counts itself.
     */
    private static function paxRecord(string $key, string $value): string
    {
        $rest = ' ' . $key . '=' . $value . "\n";
        $len  = strlen($rest) + strlen((string) strlen($rest));
        $len  = strlen($rest) + strlen((string) $len);

        return $len . $rest;
    }

    private static function block(string $name, int $size, int $mtime, string $type): string
    {
        $header = str_pad(substr($name, 0, 100), 100, "\0")
            . sprintf('%07o', 0644) . "\0"
            . sprintf('%07o', 0) . "\0"
            . sprintf('%07o', 0) . "\0"
            . sprintf('%011o', $size) . "\0"
            . sprintf('%011o', max(0, $mtime)) . "\0"
            . '        '
            . $type
            . str_repeat("\0", 100)
            . "ustar\0" . '00';
        $header = str_pad($header, self::BLOCK, "\0");

        $sum = array_sum((array) unpack('C*', $header));

        return substr_replace($header, sprintf('%06o', $sum) . "\0 ", 148, 8);
    }
}
