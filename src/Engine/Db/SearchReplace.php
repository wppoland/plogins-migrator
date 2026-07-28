<?php

declare(strict_types=1);

namespace Migrator\Engine\Db;

use Migrator\Engine\Transform\SerializedReplacer;

defined('ABSPATH') || exit;

/**
 * Runs a serialization-safe search-and-replace across every row of every table,
 * used after an import to rewrite the source site's URLs and paths to the
 * target's. Each cell goes through {@see SerializedReplacer}, so serialized
 * values keep correct byte lengths.
 *
 * Rows are updated by primary key in bounded batches. Tables without a single
 * primary key column are skipped (and reported) rather than risk an ambiguous
 * UPDATE.
 */
final class SearchReplace
{
    public function __construct(
        private \wpdb $db,
        private SerializedReplacer $replacer,
        private int $batchSize = 500,
    ) {
    }

    /**
     * @param string[] $tables
     * @param bool     $dryRun When true, count what would change but write nothing.
     *
     * @return array{tables: int, rows: int, changes: int, skipped: string[]}
     */
    public function run(array $tables, bool $dryRun = false): array
    {
        $rowsSeen = 0;
        $changes  = 0;
        $skipped  = [];

        foreach ($tables as $table) {
            $table = (string) $table;
            $pks   = $this->primaryKeys($table);
            if ([] === $pks) {
                $skipped[] = $table; // No primary key: cannot target rows safely.
                continue;
            }

            $safe    = '`' . str_replace('`', '``', $table) . '`';
            $orderBy = implode(', ', array_map(
                static fn (string $c): string => '`' . str_replace('`', '``', $c) . '`',
                $pks
            ));
            $offset = 0;

            do {
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
                $rows = $this->db->get_results(
                    $this->db->prepare("SELECT * FROM {$safe} ORDER BY {$orderBy} LIMIT %d OFFSET %d", $this->batchSize, $offset),
                    ARRAY_A
                );
                if (! is_array($rows) || [] === $rows) {
                    break;
                }

                foreach ($rows as $row) {
                    $rowsSeen++;
                    /** @var array<string, scalar|null> $row */
                    $update = [];
                    foreach ($row as $column => $value) {
                        if (! is_string($value) || in_array($column, $pks, true)) {
                            continue;
                        }
                        $before = $this->replacer->replacements();
                        $new    = $this->replacer->replace($value);
                        if ($this->replacer->replacements() > $before && $new !== $value) {
                            $update[$column] = (string) $new;
                        }
                    }

                    if ([] !== $update) {
                        if (! $dryRun) {
                            $where = [];
                            foreach ($pks as $pk) {
                                $where[$pk] = $row[$pk];
                            }
                            $this->db->update($table, $update, $where);
                        }
                        $changes++;
                    }
                }

                $offset += $this->batchSize;
            } while (count($rows) === $this->batchSize);
        }

        return [
            'tables'  => count($tables) - count($skipped),
            'rows'    => $rowsSeen,
            'changes' => $changes,
            'skipped' => $skipped,
        ];
    }

    /**
     * All columns of a table's PRIMARY key (one for a simple key, several for a
     * composite key). Empty if the table has no primary key.
     *
     * @return string[]
     */
    private function primaryKeys(string $table): array
    {
        $safe = '`' . str_replace('`', '``', $table) . '`';
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
        $keys = $this->db->get_results("SHOW KEYS FROM {$safe} WHERE Key_name = 'PRIMARY'", ARRAY_A);
        if (! is_array($keys)) {
            return [];
        }

        $columns = [];
        foreach ($keys as $key) {
            $column = $key['Column_name'] ?? null;
            if (is_string($column)) {
                $columns[] = $column;
            }
        }

        return $columns;
    }
}
