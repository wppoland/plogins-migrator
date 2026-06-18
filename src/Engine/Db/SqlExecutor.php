<?php

declare(strict_types=1);

namespace Migrator\Engine\Db;

defined('ABSPATH') || exit;

// Migrator streams large backup archives (often gigabytes) in chunks. WP_Filesystem
// reads and writes whole files into memory, which would exhaust it, so this file
// uses direct stream functions by necessity.
// phpcs:disable WordPress.WP.AlternativeFunctions

/**
 * Executes a SQL dump statement by statement.
 *
 * Splitting on ";" naively would break on any value that contains a semicolon,
 * so this tokenises the SQL with a small state machine: it tracks whether it is
 * inside a single-quoted string (honouring backslash escapes) and only treats a
 * ";" outside a string as a statement boundary. That makes it safe for arbitrary
 * serialized/binary data in INSERTs.
 *
 * It streams: {@see runFile()} reads the dump in chunks and executes each
 * statement as it completes, so even a multi-gigabyte dump never has to be held
 * in memory — only the current statement is buffered.
 */
final class SqlExecutor
{
    private const READ_CHUNK = 1_048_576; // 1 MiB.

    private string $buffer = '';

    private bool $inString = false;

    private bool $escapeNext = false;

    public function __construct(private \wpdb $db)
    {
    }

    /**
     * Execute every statement in a SQL file, streaming it. Returns the count.
     */
    public function runFile(string $path): int
    {
        $handle = fopen($path, 'rb');
        if (false === $handle) {
            throw new \RuntimeException(esc_html('Migrator: cannot read SQL file: ' . $path));
        }

        try {
            $count = $this->runStream($handle);
        } finally {
            fclose($handle);
        }

        return $count;
    }

    /**
     * Execute every statement in an in-memory SQL string. Returns the count.
     */
    public function run(string $sql): int
    {
        $this->reset();
        $count = $this->consume($sql);

        return $count + $this->flush();
    }

    /**
     * @param resource $handle
     */
    private function runStream($handle): int
    {
        $this->reset();
        $count = 0;
        while (! feof($handle)) {
            $chunk = fread($handle, self::READ_CHUNK);
            if (false === $chunk || '' === $chunk) {
                break;
            }
            $count += $this->consume($chunk);
        }

        return $count + $this->flush();
    }

    /**
     * Feed a chunk through the tokeniser, executing each statement it completes.
     * State persists between calls so a statement may span chunk boundaries.
     */
    private function consume(string $chunk): int
    {
        $count  = 0;
        $length = strlen($chunk);

        for ($i = 0; $i < $length; $i++) {
            $char          = $chunk[$i];
            $this->buffer .= $char;

            if ($this->escapeNext) {
                $this->escapeNext = false;
                continue;
            }

            if ($this->inString) {
                if ('\\' === $char) {
                    $this->escapeNext = true;
                } elseif ("'" === $char) {
                    $this->inString = false;
                }
                continue;
            }

            if ("'" === $char) {
                $this->inString = true;
            } elseif (';' === $char) {
                if ($this->execute($this->buffer)) {
                    $count++;
                }
                $this->buffer = '';
            }
        }

        return $count;
    }

    private function flush(): int
    {
        $executed = $this->execute($this->buffer) ? 1 : 0;
        $this->reset();

        return $executed;
    }

    /**
     * Clean and run one statement. Returns false for empty/comment-only buffers.
     */
    private function execute(string $buffer): bool
    {
        $statement = $this->clean($buffer);
        if ('' === $statement) {
            return false;
        }

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
        $result = $this->db->query($statement);
        if (false === $result) {
            throw new \RuntimeException(esc_html(sprintf(
                'Migrator: SQL import failed near: %s',
                substr(ltrim($statement), 0, 120)
            )));
        }

        return true;
    }

    /**
     * Trim a statement: drop the trailing semicolon and surrounding whitespace,
     * and discard buffers that are only whitespace or `--` comment lines. The
     * statement body is otherwise left verbatim (MySQL parses any inline `--`
     * comment itself), so a data line that happens to start with "-- " inside a
     * quoted value is never mangled.
     */
    private function clean(string $buffer): string
    {
        $statement = trim(rtrim(trim($buffer), ';'));
        if ('' === $statement) {
            return '';
        }

        if (str_starts_with($statement, '-- ')) {
            foreach (explode("\n", $statement) as $line) {
                $line = ltrim($line);
                if ('' !== $line && ! str_starts_with($line, '-- ')) {
                    return $statement; // Has real SQL beyond the comment lines.
                }
            }

            return ''; // Comment-only buffer (e.g. the dump header).
        }

        return $statement;
    }

    private function reset(): void
    {
        $this->buffer     = '';
        $this->inString   = false;
        $this->escapeNext = false;
    }
}
