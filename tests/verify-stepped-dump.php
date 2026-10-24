<?php
/**
 * The stepped export dump (one chunk per request, cursor saved in an option in
 * between) must hold exactly what the one-pass dump holds. Run inside wp-env:
 *   wp eval-file wp-content/plugins/plogins-migrator/tests/verify-stepped-dump.php
 *
 * Fixtures cover a table without a primary key (duplicate rows), a composite
 * key with a binary column, blob bytes 0x00 to 0xFF, utf8mb4 text, NULLs, a row
 * count that is an exact multiple of the batch size, and an empty table.
 *
 * The two dumps differ only in where an INSERT is cut (a chunk flushes its
 * statement at the end of every step), so consecutive INSERTs into the same
 * table are merged before the bytes are compared.
 *
 * @package Migrator
 */

use Migrator\Engine\Db\Dumper;
use Migrator\Engine\Db\SqlExecutor;
use Migrator\Engine\Pipeline\TimeBudget;

global $wpdb;
$fail  = 0;
$check = static function (string $l, bool $c) use (&$fail): void {
    echo ($c ? '  ok   ' : '  FAIL ') . $l . "\n";
    $c || $fail++;
};

$p      = $wpdb->prefix . 'mgrstep_';
$tables = [$p . 'nopk', $p . 'composite', $p . 'blob', $p . 'exact', $p . 'empty'];
foreach ($tables as $t) {
    $wpdb->query("DROP TABLE IF EXISTS `{$t}`");
}
$wpdb->query("CREATE TABLE `{$p}nopk` (a INT NULL, b VARCHAR(20) NULL, KEY a (a)) DEFAULT CHARSET=utf8mb4");
$wpdb->query("CREATE TABLE `{$p}composite` (g INT NOT NULL, k VARBINARY(16) NOT NULL, v TEXT, PRIMARY KEY (g, k)) DEFAULT CHARSET=utf8mb4");
$wpdb->query("CREATE TABLE `{$p}blob` (id INT NOT NULL AUTO_INCREMENT, data LONGBLOB, txt TEXT, PRIMARY KEY (id)) DEFAULT CHARSET=utf8mb4");
$wpdb->query("CREATE TABLE `{$p}exact` (id INT NOT NULL, PRIMARY KEY (id))");
$wpdb->query("CREATE TABLE `{$p}empty` (id INT NOT NULL, PRIMARY KEY (id))");

for ($i = 0; $i < 7; $i++) {
    $wpdb->insert($p . 'nopk', ['a' => $i % 3, 'b' => 0 === $i % 4 ? null : 'dup']);
}
for ($g = 1; $g <= 3; $g++) {
    foreach (["\x00\x01", "\xff\xfe\x80", 'abc', "\x00'\\"] as $k) {
        $wpdb->query($wpdb->prepare("INSERT INTO `{$p}composite` (g, k, v) VALUES (%d, %s, %s)", $g, $k, "g{$g} zażółć"));
    }
}
$bytes = '';
for ($b = 0; $b < 256; $b++) {
    $bytes .= chr($b);
}
$texts = ["Zażółć gęślą jaźń \u{1F600}", "50% off /%postname%/ ');\nINSERT INTO x VALUES (1);", 'a:1:{s:3:"url";s:18:"http://example.com";}', null];
foreach ($texts as $n => $txt) {
    $wpdb->query($wpdb->prepare("INSERT INTO `{$p}blob` (data, txt) VALUES (%s, %s)", str_repeat($bytes, $n + 1), $txt));
}
for ($i = 1; $i <= 6; $i++) {
    $wpdb->insert($p . 'exact', ['id' => $i]);
}

$batch = 3;

$onePass = static function (string $table) use ($wpdb, $batch): string {
    $h = fopen('php://memory', 'w+b');
    (new Dumper($wpdb, $batch))->dumpTable($table, $h);
    rewind($h);
    $out = (string) stream_get_contents($h);
    fclose($h);

    return $out;
};

// Production shape: a fresh Dumper per request, a spent budget so each call
// does one batch, and the cursor stored in and read back from an option.
$stepped = static function (string $table, int &$chunks) use ($wpdb, $batch): string {
    $h      = fopen('php://memory', 'w+b');
    $cursor = null;
    $chunks = 0;
    do {
        $cursor = (new Dumper($wpdb, $batch))->dumpTableChunk($table, $h, null, $cursor, new TimeBudget(0.0));
        update_option('migrator_stepped_check', ['c' => $cursor], false);
        wp_cache_delete('migrator_stepped_check', 'options');
        $saved  = get_option('migrator_stepped_check');
        $cursor = is_array($saved) ? $saved['c'] : null;
        $chunks++;
    } while (null !== $cursor && $chunks < 100);
    rewind($h);
    $out = (string) stream_get_contents($h);
    fclose($h);

    return $out;
};

// Merge "...);\nINSERT INTO `t` (cols) VALUES (..." back into one statement.
$normalize = static function (string $sql): string {
    return (string) preg_replace('/\);\n(INSERT INTO `[^`]+` \([^)]*\) VALUES )(?=\()/', '),', $sql);
};
// The cuts above only merge if both sides used the same prefix; check that too.
$normalizeStrict = static function (string $sql) use ($normalize): string {
    $prev = null;
    while ($prev !== $sql) {
        $prev = $sql;
        $sql  = $normalize($sql);
    }

    return $sql;
};

$expectedRows = [$p . 'nopk' => 7, $p . 'composite' => 12, $p . 'blob' => 4, $p . 'exact' => 6, $p . 'empty' => 0];
foreach ($tables as $t) {
    $chunks = 0;
    $a      = $onePass($t);
    $b      = $stepped($t, $chunks);
    $short  = substr($t, strlen($p));
    $check("{$short}: stepped dump equals one-pass dump ({$chunks} chunks)", $normalizeStrict($a) === $normalizeStrict($b));
    $check("{$short}: DROP and CREATE written once", 1 === substr_count($b, "DROP TABLE IF EXISTS `{$t}`") && 1 === substr_count($b, "CREATE TABLE `{$t}`"));
    if ($expectedRows[$t] > $batch) {
        $check("{$short}: really took more than one chunk", $chunks > 1);
    }

    // And the importer's own executor restores the same rows from it.
    $wpdb->query("CREATE TABLE `{$t}_orig` LIKE `{$t}`");
    $wpdb->query("INSERT INTO `{$t}_orig` SELECT * FROM `{$t}`");
    (new SqlExecutor($wpdb))->run($b);
    $restored = (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$t}`");
    $same     = $wpdb->get_var("CHECKSUM TABLE `{$t}`", 1) === $wpdb->get_var("CHECKSUM TABLE `{$t}_orig`", 1);
    $check("{$short}: stepped SQL restores {$expectedRows[$t]} rows, same checksum", $expectedRows[$t] === $restored && $same);
    $wpdb->query("DROP TABLE IF EXISTS `{$t}_orig`");
}

foreach ($tables as $t) {
    $wpdb->query("DROP TABLE IF EXISTS `{$t}`");
}
delete_option('migrator_stepped_check');

echo $fail ? "FAILED: {$fail}\n" : "All stepped dump checks passed.\n";
