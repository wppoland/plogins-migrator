<?php

declare(strict_types=1);

namespace Migrator\Engine\Archive;

defined('ABSPATH') || exit;

// Migrator streams large backup archives (often gigabytes) in chunks. WP_Filesystem
// reads and writes whole files into memory, which would exhaust it, so this file
// uses direct stream functions by necessity.
// phpcs:disable WordPress.WP.AlternativeFunctions

/**
 * Reads an archive produced by {@see Writer}. Sequential by nature: call
 * {@see nextEntry()} to advance to each entry's header, then consume its
 * content with {@see readContents()} or {@see streamTo()} (or {@see skip()} it).
 *
 * The absolute byte offset of the current entry's content is exposed so an
 * import can stop mid-file when it runs out of time and resume later by
 * re-opening and seeking back to where it left off.
 */
final class Reader
{
    private const SIGNATURE  = "MIGR\x01";
    private const READ_CHUNK = 5_242_880; // 5 MiB.

    /** @var resource */
    private $handle;

    private int $remaining = 0;

    private int $contentOffset = 0;

    private ?string $currentCrc = null;

    private int $currentSize = 0;

    public function __construct(string $path)
    {
        $handle = fopen($path, 'rb');
        if (false === $handle) {
            throw new \RuntimeException(esc_html(sprintf('Migrator: cannot open archive for reading: %s', $path)));
        }
        $this->handle = $handle;

        $signature = (string) fread($this->handle, strlen(self::SIGNATURE));
        if (self::SIGNATURE !== $signature) {
            throw new \RuntimeException('Migrator: not a Migrator archive (bad signature).');
        }
    }

    /**
     * Whether the file ends with the end marker the writer puts there last.
     *
     * Reads four bytes from the tail, so it answers "was this archive finished"
     * before a restore has touched anything, instead of half way through one.
     * It is a pre-flight, not a proof: a cut that happens to land on four zero
     * bytes passes it, and it says nothing about the middle of the file. The
     * per-entry checks in {@see nextEntry()} and the checksums stay the backstop.
     *
     * Takes a path rather than working on the open handle because the callers
     * that need it ask before they open anything, and because a stream wrapper
     * (compressed archives, as the Inspector reads them) cannot seek to the end.
     */
    public static function endsWithMarker(string $path): bool
    {
        $handle = fopen($path, 'rb');
        if (false === $handle) {
            return false;
        }
        $sought = fseek($handle, -4, SEEK_END);
        $tail   = -1 === $sought ? '' : (string) fread($handle, 4);
        fclose($handle);

        return pack('N', 0) === $tail;
    }

    /**
     * Advance to the next entry. Skips any unconsumed content of the previous
     * entry first. Returns null at end of archive.
     */
    public function nextEntry(): ?Entry
    {
        if ($this->remaining > 0) {
            fseek($this->handle, $this->remaining, SEEK_CUR);
            $this->remaining = 0;
        }

        // A finished archive always ends with the four-byte end marker, so
        // running out of file instead is not the end of the archive: it is an
        // archive that was cut short while it was being written. Treating that
        // as a clean end is how a half-written backup restores as if it were
        // whole, with everything after the cut silently absent.
        $lenBytes = (string) fread($this->handle, 4);
        if (strlen($lenBytes) < 4) {
            throw new \RuntimeException(
                'Migrator: this archive ends part way through, so it is not a complete backup. '
                . 'The run that wrote it was cut short (execution time, memory, or a full disk).'
            );
        }

        /** @var array{1: int} $unpacked */
        $unpacked  = unpack('N', $lenBytes);
        $headerLen = $unpacked[1];
        if (0 === $headerLen) {
            return null; // End marker.
        }

        $headerJson = (string) fread($this->handle, $headerLen);
        if (strlen($headerJson) < $headerLen) {
            throw new \RuntimeException(
                'Migrator: this archive ends part way through an entry, so it is not a complete backup. '
                . 'The run that wrote it was cut short (execution time, memory, or a full disk).'
            );
        }
        /** @var array<string, mixed> $header */
        $header = json_decode($headerJson, true) ?: [];
        $entry  = Entry::fromHeader($header);

        $this->remaining     = $entry->size;
        $this->currentSize   = $entry->size;
        $this->currentCrc    = $entry->crc;
        $this->contentOffset = (int) ftell($this->handle);

        return $entry;
    }

    /**
     * Read the full content of the current entry into a string. Intended for
     * small entries (the manifest); files should be streamed. Verifies the
     * entry checksum when one is present and the entry is read in full.
     */
    public function readContents(): string
    {
        $context = $this->maybeHashContext();
        $buffer  = '';
        while ($this->remaining > 0) {
            $chunk = (string) fread($this->handle, (int) min(self::READ_CHUNK, $this->remaining));
            if ('' === $chunk) {
                break;
            }
            $buffer .= $chunk;
            if (null !== $context) {
                hash_update($context, $chunk);
            }
            $this->remaining -= strlen($chunk);
        }
        $this->verify($context);

        return $buffer;
    }

    /**
     * Stream the current entry's content in chunks to a sink callback. Verifies
     * the entry checksum when one is present and the entry is read in full.
     *
     * @param callable(string):void $sink
     */
    public function streamTo(callable $sink): void
    {
        $context = $this->maybeHashContext();
        while ($this->remaining > 0) {
            $chunk = (string) fread($this->handle, (int) min(self::READ_CHUNK, $this->remaining));
            if ('' === $chunk) {
                break;
            }
            $sink($chunk);
            if (null !== $context) {
                hash_update($context, $chunk);
            }
            $this->remaining -= strlen($chunk);
        }
        $this->verify($context);
    }

    /**
     * Start a checksum context only when the current entry has a checksum AND we
     * are at its start (a resumed partial read cannot be verified this way).
     *
     * @return \HashContext|null
     */
    private function maybeHashContext(): ?\HashContext
    {
        if (null !== $this->currentCrc && $this->remaining === $this->currentSize) {
            return hash_init('crc32b');
        }

        return null;
    }

    private function verify(?\HashContext $context): void
    {
        if (null === $context || null === $this->currentCrc) {
            return;
        }
        $actual = hash_final($context);
        if (! hash_equals($this->currentCrc, $actual)) {
            throw new \RuntimeException(esc_html(sprintf(
                'Migrator: checksum mismatch, the archive is corrupt or truncated (expected %s, got %s).',
                $this->currentCrc,
                $actual
            )));
        }
    }

    public function skip(): void
    {
        if ($this->remaining > 0) {
            fseek($this->handle, $this->remaining, SEEK_CUR);
            $this->remaining = 0;
        }
    }

    /**
     * Absolute file offset where the current entry's content begins. Persist
     * this (plus bytes already extracted) to resume a large extraction later.
     */
    public function contentOffset(): int
    {
        return $this->contentOffset;
    }

    /**
     * Re-position to an absolute offset and declare how many content bytes of
     * the current entry remain. Used to resume extraction across requests.
     */
    public function resumeAt(int $offset, int $remaining): void
    {
        fseek($this->handle, $offset, SEEK_SET);
        $this->remaining = $remaining;
    }

    public function close(): void
    {
        if (is_resource($this->handle)) {
            fclose($this->handle);
        }
    }
}
