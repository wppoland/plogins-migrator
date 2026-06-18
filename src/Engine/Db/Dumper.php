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
 * dumped verbatim — search-and-replace happens at import time so one archive can
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
     * Dump tables (structure + data) followed by views, to a writable stream.
     *
     * @param string[]               $tables Tables to dump.
     * @param resource               $handle
     * @param array<string, string>  $where  Optional table => WHERE clause to
     *                                        filter out disposable rows.
     * @param string[]               $skip   Tables to omit entirely.
     */
    public function dumpAll(array $tables, $handle, array $where = [], array $skip = []): void
    {
        $this->write($handle, "-- Migrator SQL dump\n");
        $this->write($handle, "SET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\n");
        $this->write($handle, "SET FOREIGN_KEY_CHECKS=0;\n\n");

        foreach ($tables as $table) {
            $table = (string) $table;
            if (in_array($table, $skip, true)) {
                continue;
            }
            $this->dumpTable($table, $handle, $where[$table] ?? null);
        }

        // Views are created after every base table, since they reference them.
        foreach ($this->views() as $view) {
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
    public function dumpTable(string $table, $handle, ?string $where = null): void
    {
        $safe = '`' . str_replace('`', '``', $table) . '`';

        $this->write($handle, "DROP TABLE IF EXISTS {$safe};\n");

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
        $create = $this->db->get_row("SHOW CREATE TABLE {$safe}", ARRAY_N);
        if (is_array($create) && isset($create[1])) {
            $this->write($handle, $create[1] . ";\n\n");
        }

        $this->dumpRows($safe, $handle, $where);
        $this->write($handle, "\n");
    }

    /**
     * Stream a table's rows as batched INSERT statements.
     *
     * @param resource $handle
     */
    private function dumpRows(string $safe, $handle, ?string $where = null): void
    {
        $offset  = 0;
        $insert  = '';
        $started = false;
        $filter  = (null !== $where && '' !== $where) ? " WHERE {$where}" : '';

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
                $values = '(' . $this->rowValues($row) . ')';

                if (! $started) {
                    $insert  = $this->insertPrefix($safe, array_keys($row)) . $values;
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

            $offset += $this->batchSize;
        } while (count($rows) === $this->batchSize);

        if ($started && '' !== $insert) {
            $this->write($handle, $insert . ";\n");
        }
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
