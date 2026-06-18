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
 * Content is written in chunks and an entry may be filled across several calls
 * (and even across separate PHP requests, via {@see resumeEntry()}), so a large
 * file never has to be copied within a single timeout window.
 */
final class Writer
{
    private const SIGNATURE  = "MIGR\x01";
    private const COPY_CHUNK = 5_242_880; // 5 MiB.

    /** @var resource */
    private $handle;

    private ?Entry $current = null;

    private int $currentWritten = 0;

    public function __construct(string $path, bool $append = false)
    {
        $handle = fopen($path, $append ? 'ab' : 'wb');
        if (false === $handle) {
            throw new \RuntimeException(esc_html(sprintf('Migrator: cannot open archive for writing: %s', $path)));
        }
        $this->handle = $handle;

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
     */
    public function addFile(string $relPath, string $absFile): Entry
    {
        $size  = (int) filesize($absFile);
        $mtime = (int) filemtime($absFile);
        $crc   = hash_file('crc32b', $absFile) ?: null;
        $entry = new Entry($relPath, $size, $mtime ?: time(), Entry::TYPE_FILE, $crc);

        $this->beginEntry($entry);
        $this->copyFrom($absFile, 0);
        $this->endEntry();

        return $entry;
    }

    /**
     * Write the header for a new entry. Content is expected to follow via
     * {@see writeChunk()} until {@see endEntry()}.
     */
    public function beginEntry(Entry $entry): void
    {
        $header = (string) wp_json_encode($entry->toHeader());
        $this->raw(pack('N', strlen($header)));
        $this->raw($header);

        $this->current        = $entry;
        $this->currentWritten = 0;
    }

    /**
     * Re-attach to an entry whose header is already on disk (resuming a large
     * file copy in a fresh request). Streams the remaining bytes from $offset.
     */
    public function resumeEntry(Entry $entry, string $absFile, int $offset): void
    {
        $this->current        = $entry;
        $this->currentWritten = $offset;
        $this->copyFrom($absFile, $offset);
        $this->endEntry();
    }

    public function writeChunk(string $data): void
    {
        if (null === $this->current) {
            throw new \RuntimeException('Migrator: writeChunk() called with no open entry.');
        }
        $this->raw($data);
        $this->currentWritten += strlen($data);
    }

    public function endEntry(): void
    {
        $this->current        = null;
        $this->currentWritten = 0;
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

    /**
     * Stream a file's content into the current entry, starting at $offset.
     */
    private function copyFrom(string $absFile, int $offset): void
    {
        $in = fopen($absFile, 'rb');
        if (false === $in) {
            throw new \RuntimeException(esc_html(sprintf('Migrator: cannot read file: %s', $absFile)));
        }

        if ($offset > 0) {
            fseek($in, $offset);
        }

        while (! feof($in)) {
            $chunk = fread($in, self::COPY_CHUNK);
            if (false === $chunk || '' === $chunk) {
                break;
            }
            $this->writeChunk($chunk);
        }

        fclose($in);
    }

    private function raw(string $bytes): void
    {
        $written = fwrite($this->handle, $bytes);
        if (false === $written || $written < strlen($bytes)) {
            throw new \RuntimeException('Migrator: short write while building archive (disk full?).');
        }
    }
}
