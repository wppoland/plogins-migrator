<?php
/**
 * Proves the database dump does not lose rows when the site deletes one behind
 * the reader, against a real MySQL server.
 * Run: wp eval-file wp-content/plugins/migrator/tests/verify-dump-race.php
 *
 * tests/dump-paging.php covers the same ground with a fake connection and no
 * infrastructure. This one exists because the fake cannot answer the questions
 * only a server can: whether the prepared statement is valid SQL, whether the
 * `%` inside the transient WHERE filter survives wpdb::prepare, and whether a
 * DELETE committed between two batches really does move the rows that follow.
 *
 * @package Migrator
 */

use Migrator\Engine\Db\Dumper;

if (! class_exists(Dumper::class)) {
    require_once dirname(__DIR__) . '/src/Engine/Db/Dumper.php';
}

global $wpdb;

$GLOBALS['migrator_race_failures'] = 0;

function migrator_race_ok(string $label, bool $passed): void
{
    echo ($passed ? '  ok   ' : '  FAIL ') . $label . "\n";
    if (! $passed) {
        $GLOBALS['migrator_race_failures']++;
    }
}

function migrator_race_dump(\wpdb $db, string $table, int $batch, ?string $where = null): string
{
    $handle = fopen('php://memory', 'r+b');
    (new Dumper($db, $batch))->dumpTable($table, $handle, $where);
    rewind($handle);
    $sql = (string) stream_get_contents($handle);
    fclose($handle);

    return $sql;
}

/**
 * A connection that deletes a row the moment the first batch has been served,
 * which is exactly what an expiring transient or a closing session does.
 */
class Migrator_Race_Wpdb extends wpdb // phpcs:ignore
{
    public string $victimTable = '';

    public int $victimId = 0;

    public bool $fired = false;

    public function get_results($query = null, $output = OBJECT) // phpcs:ignore
    {
        $rows = parent::get_results($query, $output);

        if (! $this->fired && is_string($query) && str_contains($query, 'SELECT * FROM')) {
            $this->fired = true;
            $this->query("DELETE FROM {$this->victimTable} WHERE id = {$this->victimId}");
        }

        return $rows;
    }
}

$rowsTable = $wpdb->prefix . 'migrator_race_rows';
$wpdb->query("DROP TABLE IF EXISTS {$rowsTable}");
$wpdb->query(
    "CREATE TABLE {$rowsTable} (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,"
    . ' name VARCHAR(64) NOT NULL, PRIMARY KEY (id)) ENGINE=InnoDB'
);
for ($i = 1; $i <= 500; $i++) {
    $wpdb->query($wpdb->prepare("INSERT INTO {$rowsTable} (name) VALUES (%s)", 'row-' . $i));
}

echo "A quiet table, 500 rows read in batches of 50\n";
$sql     = migrator_race_dump($wpdb, $rowsTable, 50);
$missing = [];
for ($i = 1; $i <= 500; $i++) {
    if (! str_contains($sql, "'row-{$i}'")) {
        $missing[] = $i;
    }
}
migrator_race_ok('every row is in the dump (' . count($missing) . ' missing)', [] === $missing);
migrator_race_ok('the server reported no error', '' === $wpdb->last_error);

echo "\nA row deleted behind the reader, mid-dump\n";
$race              = new Migrator_Race_Wpdb(DB_USER, DB_PASSWORD, DB_NAME, DB_HOST);
$race->victimTable = $rowsTable;
$race->victimId    = 1;
$sql               = migrator_race_dump($race, $rowsTable, 50);
$missing           = [];
for ($i = 2; $i <= 500; $i++) {
    if (! str_contains($sql, "'row-{$i}'")) {
        $missing[] = $i;
    }
}
migrator_race_ok(
    'every surviving row is in the dump (' . count($missing) . ' missing: ' . implode(',', array_slice($missing, 0, 5)) . ')',
    [] === $missing
);
migrator_race_ok('the server reported no error', '' === $race->last_error);

echo "\nA composite primary key is paged as one ordered tuple\n";
$compositeTable = $wpdb->prefix . 'migrator_race_composite';
$wpdb->query("DROP TABLE IF EXISTS {$compositeTable}");
$wpdb->query(
    "CREATE TABLE {$compositeTable} (blog_id BIGINT NOT NULL, meta_key VARCHAR(64) NOT NULL,"
    . ' name VARCHAR(64) NOT NULL, PRIMARY KEY (blog_id, meta_key)) ENGINE=InnoDB'
);
for ($blog = 1; $blog <= 10; $blog++) {
    for ($key = 1; $key <= 10; $key++) {
        $wpdb->query($wpdb->prepare("INSERT INTO {$compositeTable} VALUES (%d, %s, %s)", $blog, 'k' . $key, "row-{$blog}-{$key}"));
    }
}
$sql     = migrator_race_dump($wpdb, $compositeTable, 7);
$missing = [];
for ($blog = 1; $blog <= 10; $blog++) {
    for ($key = 1; $key <= 10; $key++) {
        if (! str_contains($sql, "'row-{$blog}-{$key}'")) {
            $missing[] = "{$blog}-{$key}";
        }
    }
}
migrator_race_ok('all 100 rows are in the dump (' . count($missing) . ' missing)', [] === $missing);
migrator_race_ok('the server reported no error', '' === $wpdb->last_error);

echo "\nA table with no primary key\n";
$noKeyTable = $wpdb->prefix . 'migrator_race_nokey';
$wpdb->query("DROP TABLE IF EXISTS {$noKeyTable}");
$wpdb->query("CREATE TABLE {$noKeyTable} (name VARCHAR(64) NOT NULL) ENGINE=InnoDB");
for ($i = 1; $i <= 100; $i++) {
    $wpdb->query($wpdb->prepare("INSERT INTO {$noKeyTable} VALUES (%s)", 'row-' . $i));
}
$sql     = migrator_race_dump($wpdb, $noKeyTable, 10);
$missing = [];
for ($i = 1; $i <= 100; $i++) {
    if (! str_contains($sql, "'row-{$i}'")) {
        $missing[] = $i;
    }
}
migrator_race_ok('it still dumps in full (' . count($missing) . ' missing)', [] === $missing);
migrator_race_ok('the server reported no error', '' === $wpdb->last_error);

echo "\nThe options table, filtered by the no-transients option\n";
$wpdb->query(
    $wpdb->prepare(
        "INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
        '_transient_migrator_race',
        'x'
    )
);
$where    = "option_name NOT LIKE '\\_transient\\_%' AND option_name NOT LIKE '\\_site\\_transient\\_%'";
$expected = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->options} WHERE {$where}");
$sql      = migrator_race_dump($wpdb, $wpdb->options, 25, $where);
migrator_race_ok('the % in the filter survived wpdb::prepare, no error', '' === $wpdb->last_error);
migrator_race_ok('the transient row is not in the dump', ! str_contains($sql, "'_transient_migrator_race'"));
migrator_race_ok('an ordinary option is in the dump', str_contains($sql, "'siteurl'"));
preg_match_all("/\('[0-9]+'/", $sql, $matches);
migrator_race_ok(
    'the dump holds exactly the rows the same WHERE returns (' . count($matches[0]) . " vs {$expected})",
    count($matches[0]) === $expected
);
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name = '_transient_migrator_race'");

$wpdb->query("DROP TABLE IF EXISTS {$rowsTable}");
$wpdb->query("DROP TABLE IF EXISTS {$compositeTable}");
$wpdb->query("DROP TABLE IF EXISTS {$noKeyTable}");

echo 0 === $GLOBALS['migrator_race_failures']
    ? "\nALL VERIFIED\n"
    : "\n{$GLOBALS['migrator_race_failures']} FAILED\n";
