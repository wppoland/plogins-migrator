<?php
/**
 * Live check for scheme-relative URLs. Run inside wp-env:
 *   wp eval-file wp-content/plugins/migrator/tests/verify-schemeless-rewrite.php
 *
 * The replacement pairs are built from home, siteurl, content and abspath, all
 * of which carry a scheme. A value stored as "//old.example/wp-content/..."
 * therefore matched nothing and survived a restore pointing at the source host.
 * PeepSo caches its reaction icons exactly that way, so a migrated site kept
 * loading assets from the machine it was moved off.
 *
 * Also covers the mixed-scheme case: a row holding "http://old" on an https
 * site, which the same scheme-less pair fixes without touching the scheme.
 *
 * @package Migrator
 */

use Migrator\Engine\Archive\Entry;
use Migrator\Engine\Archive\Manifest;
use Migrator\Engine\Archive\Writer;
use Migrator\Engine\Export\Exporter;
use Migrator\Engine\Import\Importer;
use Migrator\Support\Workspace;

global $wpdb;
$ws = new Workspace();
$ws->ensure();

$fail  = 0;
$check = static function (string $l, bool $c) use (&$fail): void {
    echo ($c ? '  ok   ' : '  FAIL ') . $l . "\n";
    $c || $fail++;
};

$home = untrailingslashit((string) get_option('home'));

// A source host that is unrelated to this site, so nothing here matches by luck.
$source          = 'https://old-host.example';
$sourceSchemeless = '//old-host.example';
$targetSchemeless = substr($home, (int) strpos($home, '://') + 1);

$table = $wpdb->prefix . 'migrator_schemetest';
$safe  = '`' . str_replace('`', '``', $table) . '`';

// A scratch table is the only thing in the dump, so the import cannot disturb the
// rest of this site.
$dump = "SET FOREIGN_KEY_CHECKS=0;\n"
    . "DROP TABLE IF EXISTS {$safe};\n"
    . "CREATE TABLE {$safe} (`id` int(11) NOT NULL AUTO_INCREMENT, `val` text, PRIMARY KEY (`id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;\n"
    . "INSERT INTO {$safe} (`id`, `val`) VALUES (1, '" . esc_sql($sourceSchemeless) . "/wp-content/plugins/peepso/heart.svg');\n"
    . "INSERT INTO {$safe} (`id`, `val`) VALUES (2, '" . esc_sql($source) . "/wp-content/uploads/x.png');\n"
    . "INSERT INTO {$safe} (`id`, `val`) VALUES (3, 'http://old-host.example/wp-content/uploads/y.png');\n"
    . "SET FOREIGN_KEY_CHECKS=1;\n";

$manifest = [
    'format'        => 'migrator',
    'formatVersion' => 1,
    'homeUrl'       => $source,
    'siteUrl'       => $source,
    'abspath'       => '/srv/old/public_html',
    'contentDir'    => '/srv/old/public_html/wp-content',
    'tablePrefix'   => $wpdb->prefix,
    'tables'        => [$table],
];

$path = $ws->path('schemetest.migrator');
$w    = new Writer($path);
$w->addString(Manifest::NAME, (string) wp_json_encode($manifest), Entry::TYPE_MANIFEST);
$w->addString(Exporter::DB_ENTRY, $dump);
$w->finish();

(new Importer($ws, $wpdb))->import($path, false);
@unlink($path);

$val = static function (int $id) use ($wpdb, $safe): string {
    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
    return (string) $wpdb->get_var("SELECT val FROM {$safe} WHERE id = {$id}");
};

$check(
    'scheme-relative URL is rewritten (got: ' . $val(1) . ')',
    $val(1) === $targetSchemeless . '/wp-content/plugins/peepso/heart.svg'
);

$check(
    'full URL still rewritten exactly once (got: ' . $val(2) . ')',
    $val(2) === $home . '/wp-content/uploads/x.png'
);

$check(
    'mixed-scheme URL gets the new host, keeps its scheme (got: ' . $val(3) . ')',
    $val(3) === 'http:' . $targetSchemeless . '/wp-content/uploads/y.png'
);

// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
$wpdb->query("DROP TABLE IF EXISTS {$safe}");

echo $fail === 0 ? "Scheme-relative rewrite: OK\n" : "Scheme-relative rewrite: {$fail} failure(s)\n";
