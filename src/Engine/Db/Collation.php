<?php

declare(strict_types=1);

namespace Migrator\Engine\Db;

defined('ABSPATH') || exit;

/**
 * Rewrites collations the restoring server does not have.
 *
 * MySQL 8 dumps tables as utf8mb4_0900_ai_ci and MariaDB 11 as
 * utf8mb4_uca1400_ai_ci; neither exists on the other, nor on MySQL 5.7 or an
 * older MariaDB, and a CREATE TABLE naming an unknown collation fails, which
 * stops the restore. Each unknown name is swapped for the closest one the
 * server lists in SHOW COLLATION. Only CREATE statements are touched, so a
 * collation name that happens to sit in row data is never changed.
 */
final class Collation
{
    /** @var array<string, true> */
    private array $supported;

    /**
     * @param string[] $supported Collation names the server lists.
     */
    public function __construct(array $supported)
    {
        $this->supported = array_fill_keys(array_map('strtolower', $supported), true);
    }

    public static function forServer(\wpdb $db): self
    {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
        $names = $db->get_col('SHOW COLLATION');

        return new self(array_map('strval', is_array($names) ? $names : []));
    }

    public function normalise(string $statement): string
    {
        if ([] === $this->supported || 0 !== stripos(ltrim($statement), 'CREATE')) {
            return $statement;
        }

        return (string) preg_replace_callback(
            '/\b(utf8mb4|utf8mb3|utf8)_[a-z0-9_]+\b/i',
            fn (array $m): string => $this->pick(strtolower($m[0]), strtolower($m[1])),
            $statement,
        );
    }

    private function pick(string $name, string $charset): string
    {
        if ($this->has($name)) {
            return $name;
        }

        $candidates = 'utf8mb4' === $charset
            ? ['utf8mb4_unicode_520_ci', 'utf8mb4_unicode_ci', 'utf8mb4_general_ci']
            : ['utf8_unicode_ci', 'utf8mb3_unicode_ci', 'utf8_general_ci', 'utf8mb3_general_ci'];

        foreach ($candidates as $candidate) {
            if ($this->has($candidate)) {
                return $candidate;
            }
        }

        return $name;
    }

    /**
     * utf8 and utf8mb3 are one charset under two names, and servers list only
     * one of them, so either spelling counts as present.
     */
    private function has(string $name): bool
    {
        if (isset($this->supported[$name])) {
            return true;
        }
        $alias = str_starts_with($name, 'utf8mb3_')
            ? 'utf8_' . substr($name, 8)
            : (str_starts_with($name, 'utf8_') ? 'utf8mb3_' . substr($name, 5) : '');

        return '' !== $alias && isset($this->supported[$alias]);
    }
}
