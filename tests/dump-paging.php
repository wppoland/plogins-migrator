<?php
/**
 * Standalone tests for how Dumper pages through a table while the site is still
 * writing to it (no WordPress, no MySQL):  php tests/dump-paging.php
 *
 * The case that matters is a row deleted behind the walk. With OFFSET paging
 * every later row shifts one place towards the start, so the row sitting on the
 * next offset boundary is read by nobody and is missing from the backup with no
 * error anywhere. The fake connection below deletes a row between batches and
 * the test asserts every surviving row still reached the dump.
 *
 * @package Migrator
 */

declare(strict_types=1);

define('ABSPATH', __DIR__ . '/');
defined('ARRAY_A') || define('ARRAY_A', 'ARRAY_A');
defined('ARRAY_N') || define('ARRAY_N', 'ARRAY_N');

if (! function_exists('esc_html')) {
    function esc_html(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
}

/**
 * A minimal stand-in for the WordPress connection, holding one table in memory
 * and answering only the statements Dumper issues.
 */
class wpdb // phpcs:ignore
{
    public string $prefix = 'wp_';

    /** @var array<int, array<string, string>> */
    public array $rows;

    /** @var string[] */
    public array $keys;

    /** Delete this row id once this many SELECT batches have been served. */
    public ?string $deleteAfterBatch = null;

    public int $batches = 0;

    /** @var string[] */
    public array $queries = [];

    /**
     * @param array<int, array<string, string>> $rows
     * @param string[]                          $keys Primary key columns ([] = no primary key).
     */
    public function __construct(array $rows, array $keys)
    {
        $this->rows = $rows;
        $this->keys = $keys;
    }

    public function prepare(string $query, mixed ...$args): string
    {
        $values = (1 === count($args) && is_array($args[0])) ? $args[0] : $args;

        return (string) preg_replace_callback(
            '/%[ds]/',
            function (array $m) use (&$values): string {
                $value = array_shift($values);

                return '%d' === $m[0] ? (string) (int) $value : "'" . $this->_real_escape((string) $value) . "'";
            },
            $query,
        );
    }

    public function _real_escape(string $value): string // phpcs:ignore PSR2.Methods.MethodDeclaration.Underscore
    {
        return addslashes($value);
    }

    public function esc_like(string $text): string // phpcs:ignore PSR2.Methods.MethodDeclaration.Underscore
    {
        return $text;
    }

    /**
     * @return array<int, array<string, string>>|array<int, array<int, string>>
     */
    public function get_results(string $sql, mixed $output = null): array // phpcs:ignore PSR2.Methods.MethodDeclaration.Underscore
    {
        $this->queries[] = $sql;

        if (str_starts_with($sql, 'SHOW KEYS')) {
            $out = [];
            foreach ($this->keys as $i => $column) {
                $out[] = ['Seq_in_index' => (string) ($i + 1), 'Column_name' => $column];
            }

            return $out;
        }

        if (str_starts_with($sql, 'SHOW FULL TABLES') || str_starts_with($sql, 'SHOW ')) {
            return [];
        }

        $rows = array_values($this->rows);
        usort($rows, fn (array $a, array $b): int => $this->cmp($a, $b, $this->orderColumns($sql)));

        if (preg_match('/\(([^)]*)\) > \(([^)]*)\)/', $sql, $m)) {
            $columns = array_map(static fn (string $c): string => trim($c, '` '), explode(',', $m[1]));
            $cursor  = array_map(static fn (string $v): string => trim($v, "' "), explode(',', $m[2]));
            $rows    = array_values(array_filter(
                $rows,
                fn (array $row): bool => $this->cmp($row, array_combine($columns, $cursor), $columns) > 0,
            ));
        }

        $offset = preg_match('/OFFSET (\d+)/', $sql, $m) ? (int) $m[1] : 0;
        $limit  = preg_match('/LIMIT (\d+)/', $sql, $m) ? (int) $m[1] : count($rows);
        $rows   = array_slice($rows, $offset, $limit);

        $this->batches++;
        if (null !== $this->deleteAfterBatch && 1 === $this->batches) {
            unset($this->rows[(int) $this->deleteAfterBatch]);
        }

        return $rows;
    }

    /**
     * @return array<string, string>|array<int, string>|null
     */
    public function get_row(string $sql, mixed $output = null): ?array // phpcs:ignore PSR2.Methods.MethodDeclaration.Underscore
    {
        if (str_contains($sql, 'max_allowed_packet')) {
            return ['Value' => '1048576'];
        }
        if (str_starts_with($sql, 'SHOW CREATE TABLE')) {
            return [0 => 'wp_thing', 1 => 'CREATE TABLE `wp_thing` (`id` int)'];
        }

        return null;
    }

    public function get_var(string $sql): string // phpcs:ignore PSR2.Methods.MethodDeclaration.Underscore
    {
        if (str_starts_with($sql, 'SELECT COUNT(*)')) {
            return (string) count($this->rows);
        }

        return 'utf8mb4';
    }

    /**
     * @param array<string, string> $a
     * @param array<string, string> $b
     * @param string[]              $columns
     */
    private function cmp(array $a, array $b, array $columns): int
    {
        foreach ($columns as $column) {
            $left  = $a[$column] ?? '';
            $right = $b[$column] ?? '';
            $order = (is_numeric($left) && is_numeric($right))
                ? ((float) $left <=> (float) $right)
                : strcmp($left, $right);
            if (0 !== $order) {
                return $order;
            }
        }

        return 0;
    }

    /**
     * @return string[]
     */
    private function orderColumns(string $sql): array
    {
        if (! preg_match('/ORDER BY ([^L]+)/', $sql, $m)) {
            return $this->keys ?: ['id'];
        }

        return array_map(static fn (string $c): string => trim($c, '` '), explode(',', trim($m[1])));
    }
}

require __DIR__ . '/../src/Engine/Db/Dumper.php';

use Migrator\Engine\Db\Dumper;

$failures = 0;
function ok(string $label, bool $cond): void
{
    global $failures;
    echo ($cond ? '  ok   ' : '  FAIL ') . $label . "\n";
    if (! $cond) {
        $failures++;
    }
}

/** Dump one table and return the SQL it produced. */
function dump(wpdb $db, int $batchSize = 2): string
{
    $handle = fopen('php://memory', 'r+b');
    (new Dumper($db, $batchSize))->dumpTable('wp_thing', $handle);
    rewind($handle);
    $sql = (string) stream_get_contents($handle);
    fclose($handle);

    return $sql;
}

/**
 * @param int[] $ids
 *
 * @return array<int, array<string, string>>
 */
function table(array $ids): array
{
    $rows = [];
    foreach ($ids as $id) {
        $rows[$id] = ['id' => (string) $id, 'name' => 'row-' . $id];
    }

    return $rows;
}

echo "Paging a table nothing is writing to\n";
$db  = new wpdb(table([1, 2, 3, 4, 5, 6]), ['id']);
$sql = dump($db);
foreach ([1, 2, 3, 4, 5, 6] as $id) {
    ok("row {$id} is in the dump", str_contains($sql, "'row-{$id}'"));
}

echo "\nA row deleted behind the walk does not take another row with it\n";
$db = new wpdb(table([1, 2, 3, 4, 5, 6]), ['id']);
// Row 1 is read in the first batch, then deleted. Under OFFSET paging the
// second batch starts at offset 2 of a list that is now one shorter, so row 3
// is never read by anybody.
$db->deleteAfterBatch = '1';
$sql = dump($db);
foreach ([2, 3, 4, 5, 6] as $id) {
    ok("row {$id} survived the delete and is in the dump", str_contains($sql, "'row-{$id}'"));
}

echo "\nA composite primary key is paged as one ordered tuple\n";
$rows = [];
foreach ([['1', 'a'], ['1', 'b'], ['2', 'a'], ['2', 'b'], ['3', 'a']] as $i => [$a, $b]) {
    $rows[$i] = ['blog_id' => $a, 'meta_key' => $b, 'name' => 'row-' . $a . $b];
}
$db  = new wpdb($rows, ['blog_id', 'meta_key']);
$sql = dump($db);
foreach (['1a', '1b', '2a', '2b', '3a'] as $id) {
    ok("row {$id} is in the dump", str_contains($sql, "'row-{$id}'"));
}

echo "\nA table with no primary key that shrinks mid-dump fails loudly\n";
$db                   = new wpdb(table([1, 2, 3, 4, 5, 6]), []);
$db->deleteAfterBatch = '1';
$thrown               = '';
try {
    dump($db);
} catch (\RuntimeException $e) {
    $thrown = $e->getMessage();
}
ok('the dump throws rather than returning a short table', str_contains($thrown, 'may be missing rows'));

$db  = new wpdb(table([1, 2, 3, 4, 5, 6]), []);
$sql = dump($db);
ok('a table with no primary key and no deletes still dumps', str_contains($sql, "'row-6'"));

echo $failures === 0 ? "\nALL VERIFIED\n" : "\n{$failures} FAILED\n";
exit($failures === 0 ? 0 : 1);
