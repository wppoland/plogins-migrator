<?php

declare(strict_types=1);

namespace Migrator\Engine\Db;

use Migrator\Engine\Pipeline\TimeBudget;

defined('ABSPATH') || exit;

// Migrator streams large backup archives (often gigabytes) in chunks. WP_Filesystem
// reads and writes whole files into memory, which would exhaust it, so this file
// uses direct stream functions by necessity.
// phpcs:disable WordPress.WP.AlternativeFunctions

/**
 * Streams a portable SQL dump of the site's database.
 *
 * Rows are read in bounded batches (never the whole table at once) and written
 * straight to a stream, so a large table never has to sit in memory. Values are
 * dumped verbatim, search-and-replace happens at import time so one archive can
 * be restored onto any domain.
 *
 * The SQL is plain mysqldump-style: DROP + CREATE per table, then batched
 * INSERTs, wrapped in FOREIGN_KEY_CHECKS=0 so import order never matters.
 */
final class Dumper
{
    // 1 MiB: small enough to import on shared hosts whose max_allowed_packet is
    // far below the export host's. The export can't know the import server's
    // limit, so it stays conservative.
    private const DEFAULT_MAX_INSERT_BYTES = 1_048_576;

    private int $maxInsertBytes;

    public function __construct(
        private \wpdb $db,
        private int $batchSize = 1000,
    ) {
        $this->maxInsertBytes = $this->safeInsertSize();
    }

    /**
     * Base tables belonging to this site (its prefix). Views are returned
     * separately by {@see views()} so they can be created after their tables.
     * Pass an explicit list to dump a subset (selective backup).
     *
     * @return string[]
     */
    public function tables(): array
    {
        return $this->tablesOfType('BASE TABLE');
    }

    public function prefix(): string
    {
        return (string) $this->db->prefix;
    }

    /**
     * Views belonging to this site's prefix.
     *
     * @return string[]
     */
    public function views(): array
    {
        return $this->tablesOfType('VIEW');
    }

    /**
     * Triggers and stored routines (procedures + functions) for this database,
     * each as a { drop, create } pair. The create statement is a single SQL
     * statement (its inner semicolons are part of the body), so the importer can
     * run it whole with no DELIMITER handling. DEFINER is stripped so it imports
     * under whatever user runs the restore.
     *
     * @return array<int, array{drop: string, create: string}>
     */
    public function routines(): array
    {
        $out = [];

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
        $triggers = $this->db->get_results('SHOW TRIGGERS', ARRAY_A);
        foreach (is_array($triggers) ? $triggers : [] as $row) {
            $name = (string) ($row['Trigger'] ?? '');
            if ('' === $name) {
                continue;
            }
            $safe = $this->backtick($name);
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
            $def = $this->db->get_row("SHOW CREATE TRIGGER {$safe}", ARRAY_A);
            $create = is_array($def) ? (string) ($def['SQL Original Statement'] ?? '') : '';
            if ('' !== $create) {
                $out[] = ['drop' => "DROP TRIGGER IF EXISTS {$safe}", 'create' => $this->stripDefiner($create)];
            }
        }

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
        $dbName = (string) $this->db->get_var('SELECT DATABASE()');
        foreach (['PROCEDURE' => 'Create Procedure', 'FUNCTION' => 'Create Function'] as $kind => $col) {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
            $rows = $this->db->get_results(
                $this->db->prepare("SHOW {$kind} STATUS WHERE Db = %s", $dbName),
                ARRAY_A
            );
            foreach (is_array($rows) ? $rows : [] as $row) {
                $name = (string) ($row['Name'] ?? '');
                if ('' === $name) {
                    continue;
                }
                $safe = $this->backtick($name);
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
                $def = $this->db->get_row("SHOW CREATE {$kind} {$safe}", ARRAY_A);
                $create = is_array($def) ? (string) ($def[$col] ?? '') : '';
                if ('' !== $create) {
                    $out[] = ['drop' => "DROP {$kind} IF EXISTS {$safe}", 'create' => $this->stripDefiner($create)];
                }
            }
        }

        return $out;
    }

    /**
     * Approximate row count per table of this site, from SHOW TABLE STATUS (no
     * table scan). Only used to draw the progress bar.
     *
     * @return array<string, int>
     */
    public function rowEstimates(): array
    {
        $like = $this->db->esc_like($this->db->prefix) . '%';
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
        $rows = $this->db->get_results($this->db->prepare('SHOW TABLE STATUS LIKE %s', $like), ARRAY_A);

        $out = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            $out[(string) ($row['Name'] ?? '')] = (int) ($row['Rows'] ?? 0);
        }

        return $out;
    }

    private function backtick(string $identifier): string
    {
        return '`' . str_replace('`', '``', $identifier) . '`';
    }

    /**
     * The character set the dump's bytes are in, for its SET NAMES line.
     *
     * That is the connection's charset, not the database's: every row is read
     * through wpdb, so its bytes arrive in whatever wpdb set on the connection
     * (utf8mb4 on any current site). This used to report
     * @@character_set_database, which is latin1 or utf8mb3 on plenty of older
     * hosts while the tables themselves are utf8mb4. The dump then declared
     * latin1 over UTF-8 bytes and every non-ASCII character came back
     * double-encoded on restore, the safety rollback included.
     */
    public function charset(): string
    {
        $charset = preg_replace('/[^a-z0-9_]/i', '', (string) $this->db->charset) ?: '';

        return '' !== $charset ? $charset : 'utf8mb4';
    }

    /**
     * Dump the SQL preamble (settings, charset, foreign key check disable).
     *
     * @param resource $handle
     */
    public function dumpHeader($handle): void
    {
        $this->write($handle, "-- Migrator SQL dump\n");
        $this->write($handle, "SET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\n");
        // Pin the connection charset so multibyte data (for example Polish or any
        // UTF-8 content) imports correctly on a server with a different default.
        $this->write($handle, "SET NAMES {$this->charset()};\n");
        $this->write($handle, "SET FOREIGN_KEY_CHECKS=0;\n\n");
    }

    /**
     * Dump the SQL postamble (re-enable foreign key checks).
     *
     * @param resource $handle
     */
    public function dumpFooter($handle): void
    {
        $this->write($handle, "SET FOREIGN_KEY_CHECKS=1;\n");
    }

    /**
     * Dump all view definitions to a stream.
     *
     * @param resource $handle
     * @param string[] $skip
     */
    public function dumpViews($handle, array $skip = []): void
    {
        foreach ($this->views() as $view) {
            if (in_array($view, $skip, true)) {
                continue;
            }
            $this->dumpView($view, $handle);
        }
    }

    /**
     * Dump tables (structure + data) followed by views, to a writable stream.
     *
     * @param string[]               $tables Tables to dump.
     * @param resource               $handle
     * @param array<string, string>  $where  Optional table => WHERE clause to
     *                                        filter out disposable rows.
     * @param string[]               $skip   Tables and views to omit entirely.
     */
    public function dumpAll(array $tables, $handle, array $where = [], array $skip = []): void
    {
        $this->dumpHeader($handle);

        foreach ($tables as $table) {
            $table = (string) $table;
            if (in_array($table, $skip, true)) {
                continue;
            }
            $this->dumpTable($table, $handle, $where[$table] ?? null);
        }

        $this->dumpViews($handle, $skip);

        $this->dumpFooter($handle);
    }

    /**
     * @return string[]
     */
    private function tablesOfType(string $type): array
    {
        $like = $this->db->esc_like($this->db->prefix) . '%';
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
        $rows = $this->db->get_results($this->db->prepare('SHOW FULL TABLES LIKE %s', $like), ARRAY_N);
        if (! is_array($rows)) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            if (isset($row[0], $row[1]) && $type === $row[1]) {
                $out[] = (string) $row[0];
            }
        }

        return $out;
    }

    /**
     * Dump a view definition (DROP + CREATE), with the DEFINER clause removed so
     * it imports cleanly under whatever user runs the restore.
     *
     * @param resource $handle
     */
    private function dumpView(string $view, $handle): void
    {
        $safe = '`' . str_replace('`', '``', $view) . '`';
        $this->write($handle, "DROP VIEW IF EXISTS {$safe};\n");

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
        $row    = $this->db->get_row("SHOW CREATE VIEW {$safe}", ARRAY_A);
        $create = is_array($row) ? (string) ($row['Create View'] ?? '') : '';
        if ('' !== $create) {
            $this->write($handle, $this->stripDefiner($create) . ";\n\n");
        }
    }

    /**
     * Remove the DEFINER clause and downgrade SQL SECURITY DEFINER to INVOKER so
     * imported views/routines don't fail on a missing or mismatched MySQL user.
     */
    private function stripDefiner(string $sql): string
    {
        $sql = (string) preg_replace('/DEFINER=[^\s]+@[^\s]+\s/', '', $sql);

        return str_replace('SQL SECURITY DEFINER', 'SQL SECURITY INVOKER', $sql);
    }

    /**
     * Cap a single INSERT well under the server's max_allowed_packet so a wide
     * row never produces a packet the server rejects on import.
     */
    private function safeInsertSize(): int
    {
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
        $row    = $this->db->get_row("SHOW VARIABLES LIKE 'max_allowed_packet'", ARRAY_A);
        $packet = is_array($row) ? (int) ($row['Value'] ?? 0) : 0;
        if ($packet <= 0) {
            return self::DEFAULT_MAX_INSERT_BYTES;
        }

        return max(65_536, min(self::DEFAULT_MAX_INSERT_BYTES, (int) ($packet * 0.9)));
    }

    /**
     * Dump a single table: structure then data.
     *
     * @param resource $handle
     */
    /**
     * Dump a table. Pass $outputTable to write it under a different name (data is
     * read from $table but the DROP/CREATE/INSERT use $outputTable), which lets a
     * multisite subsite's wp_2_* tables be exported as standard wp_* tables.
     *
     * @param resource $handle
     */
    public function dumpTable(string $table, $handle, ?string $where = null, ?string $outputTable = null): void
    {
        $cursor = null;
        do {
            $cursor = $this->dumpTableChunk($table, $handle, $where, $cursor, null, $outputTable);
        } while (null !== $cursor);
    }

    /**
     * Dump a slice of a table's structure and rows within the given time budget.
     *
     * If $cursor is null, writes DROP and CREATE statements before the first batch of rows.
     * Returns null when the table has been completely dumped, or an updated cursor array
     * to resume from in the next step.
     *
     * @param resource                  $handle
     * @param array<string, mixed>|null $cursor
     *
     * @return array<string, mixed>|null
     */
    public function dumpTableChunk(
        string $table,
        $handle,
        ?string $where = null,
        ?array $cursor = null,
        ?TimeBudget $budget = null,
        ?string $outputTable = null
    ): ?array {
        $safe = '`' . str_replace('`', '``', $table) . '`';
        $out  = null === $outputTable ? $safe : '`' . str_replace('`', '``', $outputTable) . '`';

        if (null === $cursor) {
            $this->write($handle, "DROP TABLE IF EXISTS {$out};\n");

            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
            $create = $this->db->get_row("SHOW CREATE TABLE {$safe}", ARRAY_N);
            if (is_array($create) && isset($create[1])) {
                $createSql = (string) $create[1];
                if (null !== $outputTable) {
                    // Rename the table in its own CREATE statement (the exact
                    // back-ticked name only appears as the table identifier).
                    $createSql = str_replace($safe, $out, $createSql);
                }
                $this->write($handle, $createSql . ";\n\n");
            }
        }

        $keys = $this->primaryKeyColumns($safe);

        if ([] !== $keys) {
            return $this->dumpRowsPkChunk($safe, $out, $handle, $keys, $where, $cursor, $budget);
        }

        return $this->dumpRowsOffsetChunk($safe, $out, $handle, $where, $cursor, $budget);
    }

    /**
     * @param resource                  $handle
     * @param string[]                  $keys
     * @param array<string, mixed>|null $cursor
     *
     * @return array<string, mixed>|null
     */
    private function dumpRowsPkChunk(
        string $safe,
        string $outSafe,
        $handle,
        array $keys,
        ?string $where,
        ?array $cursor,
        ?TimeBudget $budget
    ): ?array {
        $columns  = implode(',', array_map([$this, 'backtick'], $keys));
        $pkCursor = isset($cursor['last']) && is_array($cursor['last']) ? $this->decodeKey($cursor['last']) : null;
        $rowsDone = (int) ($cursor['rows'] ?? 0);

        $insert  = '';
        $started = false;
        $done    = false;

        do {
            $clauses = [];
            $args    = [];

            if (null !== $where && '' !== $where) {
                $clauses[] = "({$where})";
            }
            if (null !== $pkCursor) {
                // Row-constructor comparison, so a composite key is paged as one
                // ordered tuple rather than column by column.
                $clauses[] = "({$columns}) > (" . implode(',', array_fill(0, count($keys), '%s')) . ')';
                $args      = $pkCursor;
            }

            $sql = "SELECT * FROM {$safe}"
                . ([] === $clauses ? '' : ' WHERE ' . implode(' AND ', $clauses))
                . " ORDER BY {$columns} LIMIT %d";

            $args[] = $this->batchSize;

            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
            $rows = $this->db->get_results($this->db->prepare($sql, $args), ARRAY_A);

            if (! is_array($rows) || [] === $rows) {
                $done = true;
                break;
            }

            foreach ($rows as $row) {
                /** @var array<string, scalar|null> $row */
                $values = '(' . $this->rowValues($row) . ')';

                if (! $started) {
                    $insert  = $this->insertPrefix($outSafe, array_keys($row)) . $values;
                    $started = true;
                } else {
                    $insert .= ',' . $values;
                }

                if (strlen($insert) >= $this->maxInsertBytes) {
                    $this->write($handle, $insert . ";\n");
                    $insert  = '';
                    $started = false;
                }
            }

            $rowsDone += count($rows);
            $last      = (array) end($rows);
            $pkCursor  = [];
            foreach ($keys as $key) {
                $pkCursor[] = (string) ($last[$key] ?? '');
            }

            if (count($rows) < $this->batchSize) {
                $done = true;
                break;
            }

            if (null !== $budget && $budget->expired()) {
                break;
            }
        } while (true);

        if ($started && '' !== $insert) {
            $this->write($handle, $insert . ";\n");
        }

        if ($done) {
            $this->write($handle, "\n");

            return null;
        }

        return [
            'last' => $this->encodeKey($pkCursor),
            'rows' => $rowsDone,
        ];
    }

    /**
     * @param resource                  $handle
     * @param array<string, mixed>|null $cursor
     *
     * @return array<string, mixed>|null
     */
    private function dumpRowsOffsetChunk(
        string $safe,
        string $outSafe,
        $handle,
        ?string $where,
        ?array $cursor,
        ?TimeBudget $budget
    ): ?array {
        $filter = (null !== $where && '' !== $where) ? " WHERE {$where}" : '';

        if (null === $cursor) {
            $before = $this->countRows($safe, $filter);
            $offset = 0;
        } else {
            $before = (int) ($cursor['before'] ?? $this->countRows($safe, $filter));
            $offset = (int) ($cursor['offset'] ?? 0);
        }

        $insert  = '';
        $started = false;
        $done    = false;

        do {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
            $rows = $this->db->get_results(
                $this->db->prepare("SELECT * FROM {$safe}{$filter} LIMIT %d OFFSET %d", $this->batchSize, $offset),
                ARRAY_A,
            );

            if (! is_array($rows) || [] === $rows) {
                $done = true;
                break;
            }

            foreach ($rows as $row) {
                /** @var array<string, scalar|null> $row */
                $values = '(' . $this->rowValues($row) . ')';

                if (! $started) {
                    $insert  = $this->insertPrefix($outSafe, array_keys($row)) . $values;
                    $started = true;
                } else {
                    $insert .= ',' . $values;
                }

                if (strlen($insert) >= $this->maxInsertBytes) {
                    $this->write($handle, $insert . ";\n");
                    $insert  = '';
                    $started = false;
                }
            }

            $offset += count($rows);

            if (count($rows) < $this->batchSize) {
                $done = true;
                break;
            }

            if (null !== $budget && $budget->expired()) {
                break;
            }
        } while (true);

        if ($started && '' !== $insert) {
            $this->write($handle, $insert . ";\n");
        }

        if ($done) {
            if ($this->countRows($safe, $filter) < $before) {
                throw new \RuntimeException(esc_html(sprintf(
                    'Migrator: rows were deleted from %s while it was being dumped, and it has no primary key to page on, so the dump may be missing rows. The backup was stopped.',
                    $safe
                )));
            }

            $this->write($handle, "\n");

            return null;
        }

        return [
            'offset' => $offset,
            'before' => $before,
            'rows'   => $offset,
        ];
    }

    /**
     * The cursor is saved in a wp_options row, a utf8mb4 column. A binary key
     * value is not valid UTF-8 there, and wpdb refuses or mangles it, so key
     * values travel base64-encoded.
     *
     * @param string[] $key
     *
     * @return string[]
     */
    private function encodeKey(array $key): array
    {
        return array_map('base64_encode', $key);
    }

    /**
     * @param array<mixed> $key
     *
     * @return string[]
     */
    private function decodeKey(array $key): array
    {
        return array_values(array_map(static fn ($v): string => (string) base64_decode((string) $v, true), $key));
    }

    private function countRows(string $safe, string $filter): int
    {
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
        return (int) $this->db->get_var("SELECT COUNT(*) FROM {$safe}{$filter}");
    }

    /**
     * The table's primary key columns, in key order. Empty when it has none.
     *
     * @return string[]
     */
    private function primaryKeyColumns(string $safe): array
    {
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
        $rows = $this->db->get_results("SHOW KEYS FROM {$safe} WHERE Key_name = 'PRIMARY'", ARRAY_A);
        if (! is_array($rows)) {
            return [];
        }

        $columns = [];
        foreach ($rows as $row) {
            $name = (string) ($row['Column_name'] ?? '');
            if ('' !== $name) {
                $columns[(int) ($row['Seq_in_index'] ?? count($columns) + 1)] = $name;
            }
        }
        ksort($columns);

        return array_values($columns);
    }

    /**
     * @param string[] $columns
     */
    private function insertPrefix(string $safe, array $columns): string
    {
        $cols = array_map(
            static fn (string $c): string => '`' . str_replace('`', '``', $c) . '`',
            $columns,
        );

        return "INSERT INTO {$safe} (" . implode(',', $cols) . ') VALUES ';
    }

    /**
     * @param array<string, scalar|null> $row
     */
    private function rowValues(array $row): string
    {
        $out = [];
        foreach ($row as $value) {
            if (null === $value) {
                $out[] = 'NULL';
            } else {
                // _real_escape() swaps every % for this request's placeholder
                // hash (it exists to protect prepare()), and nothing downstream
                // swaps it back: the dump left this request carrying the hash, so
                // every % in the site ("/%postname%/", "50% off", a serialized
                // %1$s whose byte length then no longer matched) came back as a
                // 66-character token on restore.
                $out[] = "'" . $this->db->remove_placeholder_escape($this->db->_real_escape((string) $value)) . "'";
            }
        }

        return implode(',', $out);
    }

    /**
     * @param resource $handle
     */
    private function write($handle, string $sql): void
    {
        // A full disk makes fwrite() write what fits and return the short
        // count; only checking for false let the dump end mid-statement.
        $written = fwrite($handle, $sql);
        if (false === $written || $written < strlen($sql)) {
            throw new \RuntimeException('Migrator: failed writing SQL dump (disk full?).');
        }
    }
}
