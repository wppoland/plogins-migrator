<?php
/**
 * Standalone test for the search-replace dry-run branch (no WordPress needed):
 *   php tests/search-replace-dryrun.php
 *
 * Verifies that a dry run counts what would change but calls update() zero
 * times, while a live run writes exactly once. That is the whole contract of the
 * new $dryRun flag.
 *
 * @package Migrator
 */

declare(strict_types=1);

define('ABSPATH', __DIR__ . '/');
defined('ARRAY_A') || define('ARRAY_A', 'ARRAY_A');

require __DIR__ . '/../src/Engine/Transform/SerializedReplacer.php';
require __DIR__ . '/../src/Engine/Db/SearchReplace.php';

use Migrator\Engine\Db\SearchReplace;
use Migrator\Engine\Transform\SerializedReplacer;

/** Minimal wpdb stub exposing only what SearchReplace::run() touches. */
class wpdb
{
    /** @var array<int, array{0: string, 1: array, 2: array}> */
    public array $updates = [];
    private bool $served = false;

    public function prepare(string $query, mixed ...$args): string
    {
        return $query;
    }

    /** @return list<array<string, mixed>> */
    public function get_results(string $query, mixed $output = null): array
    {
        if (str_contains($query, 'SHOW KEYS')) {
            return [['Column_name' => 'id']];
        }
        if ($this->served) {
            return []; // End of table on the next page.
        }
        $this->served = true;

        return [['id' => 1, 'value' => 'visit https://old.example today']];
    }

    public function update(string $table, array $data, array $where): int
    {
        $this->updates[] = [$table, $data, $where];

        return 1;
    }
}

$failures = 0;
$check = static function (string $label, bool $ok) use (&$failures): void {
    echo ($ok ? 'ok   ' : 'FAIL ') . $label . "\n";
    if (! $ok) {
        $failures++;
    }
};

$make = static fn (wpdb $db): SearchReplace =>
    new SearchReplace($db, new SerializedReplacer('https://old.example', 'https://new.example'));

$dryDb = new wpdb();
$dry   = $make($dryDb)->run(['wp_options'], true);
$check('dry-run counts the change', 1 === $dry['changes']);
$check('dry-run writes nothing', [] === $dryDb->updates);

$liveDb = new wpdb();
$live   = $make($liveDb)->run(['wp_options'], false);
$check('live run counts the change', 1 === $live['changes']);
$check('live run writes exactly once', 1 === count($liveDb->updates));

echo 0 === $failures ? "\nALL PASS\n" : "\n{$failures} FAILED\n";
exit(0 === $failures ? 0 : 1);
