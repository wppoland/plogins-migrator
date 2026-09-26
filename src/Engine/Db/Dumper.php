<?php

declare(strict_types=1);

namespace Migrator\Engine\Db;

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

    private function backtick(string $identifier): string
    {
        return '`' . str_replace('`', '``', $identifier) . '`';
    }

    /**
     * The database's character set, sanitised to an identifier for SET NAMES.
     * Falls back to utf8mb4 (the WordPress default) when unavailable.
     */
    public function charset(): string
    {
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
        $charset = (string) $this->db->get_var('SELECT @@character_set_database');
        $charset = preg_replace('/[^a-z0-9_]/i', '', $charset) ?: '';

        return '' !== $charset ? $charset : 'utf8mb4';
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
        $this->write($handle, "-- Migrator SQL dump\n");
        $this->write($handle, "SET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\n");
        // Pin the connection charset so multibyte data (for example Polish or any
        // UTF-8 content) imports correctly on a server with a different default.
        $this->write($handle, "SET NAMES {$this->charset()};\n");
        $this->write($handle, "SET FOREIGN_KEY_CHECKS=0;\n\n");

        foreach ($tables as $table) {
            $table = (string) $table;
            if (in_array($table, $skip, true)) {
                continue;
            }
            $this->dumpTable($table, $handle, $where[$table] ?? null);
        }

        // Views are created after every base table, since they reference them.
        // The exclusion list is built from SHOW TABLES, which lists views next to
        // base tables, so a merchant could tick a view and still find its
        // definition in the archive. The skip list applies here too.
        foreach ($this->views() as $view) {
            if (in_array($view, $skip, true)) {
                continue;
            }
            $this->dumpView($view, $handle);
        }

        $this->write($handle, "SET FOREIGN_KEY_CHECKS=1;\n");
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
        $safe = '`' . str_replace('`', '``', $table) . '`';
        $out  = null === $outputTable ? $safe : '`' . str_replace('`', '``', $outputTable) . '`';

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

        $this->dumpRows($safe, $handle, $where, $out);
        $this->write($handle, "\n");
    }

    /**
     * Stream a table's rows as batched INSERT statements.
     *
     * @param resource $handle
     */
    private function dumpRows(string $safe, $handle, ?string $where = null, ?string $outSafe = null): void
    {
        $outSafe ??= $safe;
        $insert  = '';
        $started = false;

        foreach ($this->readRows($safe, $where) as $row) {
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

        if ($started && '' !== $insert) {
            $this->write($handle, $insert . ";\n");
        }
    }

    /**
     * Read a table in bounded batches.
     *
     * Paging by OFFSET over a live site skips rows: delete a row the walk has
     * already passed and every later row shifts one place towards the start, so
     * the row that lands on the next offset boundary is never read and never
     * reaches the backup. Nothing reports it. Expiring transients, WooCommerce
     * sessions and abandoned carts delete rows constantly, so this is ordinary
     * traffic and not a rare race.
     *
     * So batches are cut by primary key instead: each one asks for the rows
     * AFTER the last key already read, which no concurrent delete can move. An
     * insert during the walk either lands after the cursor (and is included) or
     * before it (and is not), but nothing existing is ever skipped.
     *
     * @return \Generator<array<string, scalar|null>>
     */
    private function readRows(string $safe, ?string $where): \Generator
    {
        $keys = $this->primaryKeyColumns($safe);

        if ([] === $keys) {
            yield from $this->readRowsByOffset($safe, $where);

            return;
        }

        $columns = implode(',', array_map([$this, 'backtick'], $keys));
        $cursor  = null;

        do {
            $clauses = [];
            $args    = [];

            if (null !== $where && '' !== $where) {
                $clauses[] = "({$where})";
            }
            if (null !== $cursor) {
                // Row-constructor comparison, so a composite key is paged as one
                // ordered tuple rather than column by column.
                $clauses[] = "({$columns}) > (" . implode(',', array_fill(0, count($keys), '%s')) . ')';
                $args      = $cursor;
            }

            $sql = "SELECT * FROM {$safe}"
                . ([] === $clauses ? '' : ' WHERE ' . implode(' AND ', $clauses))
                . " ORDER BY {$columns} LIMIT %d";

            $args[] = $this->batchSize;

            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
            $rows = $this->db->get_results($this->db->prepare($sql, $args), ARRAY_A);

            if (! is_array($rows) || [] === $rows) {
                return;
            }

            foreach ($rows as $row) {
                /** @var array<string, scalar|null> $row */
                yield $row;
            }

            $last   = (array) end($rows);
            $cursor = [];
            foreach ($keys as $key) {
                $cursor[] = (string) ($last[$key] ?? '');
            }
        } while (count($rows) === $this->batchSize);
    }

    /**
     * The fallback for a table with no primary key, where there is no column the
     * batches can be cut on. It pages by offset, which is only safe while no row
     * is deleted underneath it, so the row count is taken before and after: a
     * table that shrank during the walk may have skipped rows, and the dump
     * stops rather than writing an archive that quietly lacks them.
     *
     * @return \Generator<array<string, scalar|null>>
     */
    private function readRowsByOffset(string $safe, ?string $where): \Generator
    {
        $filter = (null !== $where && '' !== $where) ? " WHERE {$where}" : '';
        $before = $this->countRows($safe, $filter);
        $offset = 0;

        do {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
            $rows = $this->db->get_results(
                $this->db->prepare("SELECT * FROM {$safe}{$filter} LIMIT %d OFFSET %d", $this->batchSize, $offset),
                ARRAY_A,
            );

            if (! is_array($rows) || [] === $rows) {
                break;
            }

            foreach ($rows as $row) {
                /** @var array<string, scalar|null> $row */
                yield $row;
            }

            $offset += $this->batchSize;
        } while (count($rows) === $this->batchSize);

        if ($this->countRows($safe, $filter) < $before) {
            throw new \RuntimeException(esc_html(sprintf(
                'Migrator: rows were deleted from %s while it was being dumped, and it has no primary key to page on, so the dump may be missing rows. The backup was stopped.',
                $safe
            )));
        }
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
                $out[] = "'" . $this->db->_real_escape((string) $value) . "'";
            }
        }

        return implode(',', $out);
    }

    /**
     * @param resource $handle
     */
    private function write($handle, string $sql): void
    {
        if (false === fwrite($handle, $sql)) {
            throw new \RuntimeException('Migrator: failed writing SQL dump (disk full?).');
        }
    }
}
