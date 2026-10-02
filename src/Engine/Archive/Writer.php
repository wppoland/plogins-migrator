<?php

declare(strict_types=1);

namespace Migrator\Engine\Archive;

defined('ABSPATH') || exit;

// Migrator streams large backup archives (often gigabytes) in chunks. WP_Filesystem
// reads and writes whole files into memory, which would exhaust it, so this file
// uses direct stream functions by necessity.
// phpcs:disable WordPress.WP.AlternativeFunctions

/**
 * Streams entries into an archive file. The on-disk format is deliberately
 * plain so it can be read back with nothing but PHP:
 *
 *   SIGNATURE  "MIGR" + 0x01                       (5 bytes)
 *   ENTRY*     headerLen (uint32 BE) + headerJSON + content(size bytes)
 *   END        headerLen == 0                       (4 bytes)
 *
 * Content is written in chunks, so a large file never has to sit in memory.
 */
final class Writer
{
    private const SIGNATURE  = "MIGR\x01";
    private const COPY_CHUNK = 5_242_880; // 5 MiB.

    /** Written into a file entry's header, then overwritten once the copy is done. */
    private const CRC_PLACEHOLDER = '00000000';

    /** @var list<string> */
    private array $warnings = [];

    /** @var resource */
    private $handle;

    private ?Entry $current = null;

    public function __construct(string $path, bool $append = false)
    {
        // Not 'ab': in append mode every write lands at the end whatever the
        // file position, and the checksum has to be patched in behind the data.
        $handle = fopen($path, $append ? 'c+b' : 'wb');
        if (false === $handle) {
            throw new \RuntimeException(esc_html(sprintf('Migrator: cannot open archive for writing: %s', $path)));
        }
        $this->handle = $handle;
        if ($append) {
            fseek($this->handle, 0, SEEK_END);
        }

        if (! $append) {
            $this->raw(self::SIGNATURE);
        }
    }

    /**
     * Add a complete entry from an in-memory string (manifest, small files).
     */
    public function addString(string $relPath, string $contents, string $type = Entry::TYPE_FILE, ?int $mtime = null): void
    {
        $crc   = hash('crc32b', $contents);
        $entry = new Entry($relPath, strlen($contents), $mtime ?? time(), $type, $crc);
        $this->beginEntry($entry);
        $this->writeChunk($contents);
        $this->endEntry();
    }

    /**
     * Add a complete file, streaming its content. Returns the entry written.
     *
     * Size and checksum used to be taken before the copy, and the copy then ran
     * to EOF. A file that grew or shrank in between (a log, a cache, an upload
     * landing) wrote a different number of bytes than the header promised,
     * which broke every entry after it, or a checksum that no longer matched,
     * which failed the restore half way. Now exactly the stat()ed size is
     * copied, the checksum is taken over the bytes actually written, and it is
     * patched into the header afterwards.
     */
    public function addFile(string $relPath, string $absFile): Entry
    {
        $in = fopen($absFile, 'rb');
        if (false === $in) {
            throw new \RuntimeException(esc_html(sprintf('Migrator: cannot read file: %s', $absFile)));
        }

        try {
            $stat  = fstat($in);
            $size  = (int) ($stat['size'] ?? 0);
            $mtime = (int) ($stat['mtime'] ?? 0);

            $headerAt = (int) ftell($this->handle);
            $header   = $this->beginEntry(new Entry($relPath, $size, $mtime ?: time(), Entry::TYPE_FILE, self::CRC_PLACEHOLDER));

            $context = hash_init('crc32b');
            $left    = $size;
            while ($left > 0) {
                $chunk = fread($in, min(self::COPY_CHUNK, $left));
                if (false === $chunk || '' === $chunk) {
                    break;
                }
                hash_update($context, $chunk);
                $this->writeChunk($chunk);
                $left -= strlen($chunk);
            }

            if ($left > 0) {
                // It shrank under us. The header already promised $size bytes,
                // so pad to keep every later entry where the reader expects it.
                $this->warnings[] = sprintf('%s changed while it was being copied; its backup copy is padded and will not match the original.', $relPath);
                while ($left > 0) {
                    $pad = str_repeat("\0", min(self::COPY_CHUNK, $left));
                    hash_update($context, $pad);
                    $this->writeChunk($pad);
                    $left -= strlen($pad);
                }
            }
        } finally {
            fclose($in);
        }

        $crc = hash_final($context);
        $this->patchCrc($headerAt, $header, $crc);
        $this->endEntry();

        return new Entry($relPath, $size, $mtime ?: time(), Entry::TYPE_FILE, $crc);
    }

    /**
     * Problems met while writing that did not stop the archive.
     *
     * @return list<string>
     */
    public function warnings(): array
    {
        return $this->warnings;
    }

    /**
     * Overwrite the placeholder checksum in an entry header already on disk.
     * crc32b is always eight hex characters, so the header length is unchanged.
     */
    private function patchCrc(int $headerAt, string $header, string $crc): void
    {
        $pos = strrpos($header, '"c":"' . self::CRC_PLACEHOLDER . '"');
        if (false === $pos) {
            throw new \RuntimeException('Migrator: entry header has no checksum slot.');
        }

        $end = ftell($this->handle);
        fseek($this->handle, $headerAt + 4 + $pos + 5);
        $this->raw($crc);
        fseek($this->handle, (int) $end);
    }

    /**
     * Write the header for a new entry. Content is expected to follow via
     * {@see writeChunk()} until {@see endEntry()}.
     */
    public function beginEntry(Entry $entry): string
    {
        $header = (string) wp_json_encode($entry->toHeader());
        $this->raw(pack('N', strlen($header)));
        $this->raw($header);

        $this->current        = $entry;

        return $header;
    }

    public function writeChunk(string $data): void
    {
        if (null === $this->current) {
            throw new \RuntimeException('Migrator: writeChunk() called with no open entry.');
        }
        $this->raw($data);
    }

    public function endEntry(): void
    {
        $this->current        = null;
    }

    /**
     * Finalise the archive: write the end marker and close the handle.
     */
    public function finish(): void
    {
        $this->raw(pack('N', 0));
        $this->close();
    }

    public function close(): void
    {
        if (is_resource($this->handle)) {
            fclose($this->handle);
        }
    }

    private function raw(string $bytes): void
    {
        $written = fwrite($this->handle, $bytes);
        if (false === $written || $written < strlen($bytes)) {
            throw new \RuntimeException('Migrator: short write while building archive (disk full?).');
        }
    }
}
