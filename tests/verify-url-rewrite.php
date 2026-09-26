<?php
/**
 * Live check for the URL rewrite pairs. Run inside wp-env:
 *   wp eval-file wp-content/plugins/migrator/tests/verify-url-rewrite.php
 *
 * A site's home and siteurl hold the same string almost everywhere. The pairs are
 * applied in order by str_replace, so listing that string twice rewrites a value
 * that was already rewritten: importing into a subdirectory used to land on
 * https://new/sub/sub. This imports an archive whose source URL is a prefix of
 * this site's URL, which is the shape that triggers it.
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

// The source URL has to be a prefix of this site's URL for the double rewrite to
// show up, exactly like moving a site into a subdirectory.
$source = substr($home, 0, max(1, strlen($home) - 3));
if ($source === $home || ! str_starts_with($home, $source)) {
    echo "SKIP: this site's home URL is too short to build a prefix from.\n";

    return;
}

$table = $wpdb->prefix . 'migrator_urltest';
$safe  = '`' . str_replace('`', '``', $table) . '`';

// A scratch table is the only thing in the dump, so the import cannot disturb the
// rest of this site.
$dump = "SET FOREIGN_KEY_CHECKS=0;\n"
    . "DROP TABLE IF EXISTS {$safe};\n"
    . "CREATE TABLE {$safe} (`id` int(11) NOT NULL AUTO_INCREMENT, `val` text, PRIMARY KEY (`id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;\n"
    . "INSERT INTO {$safe} (`id`, `val`) VALUES (1, '" . esc_sql($source) . "/wp-content/uploads/x.png');\n"
    . "SET FOREIGN_KEY_CHECKS=1;\n";

$manifest = [
    'format'        => 'migrator',
    'formatVersion' => 1,
    'homeUrl'       => $source,
    'siteUrl'       => $source, // The duplicate that used to be applied twice.
    'abspath'       => untrailingslashit((string) ABSPATH),
    'contentDir'    => untrailingslashit((string) WP_CONTENT_DIR),
    'tablePrefix'   => $wpdb->prefix,
    'tables'        => [$table],
];

$path = $ws->path('urltest.migrator');
$w    = new Writer($path);
$w->addString(Manifest::NAME, (string) wp_json_encode($manifest), Entry::TYPE_MANIFEST);
$w->addString(Exporter::DB_ENTRY, $dump);
$w->finish();

(new Importer($ws, $wpdb))->import($path, false);
@unlink($path);

// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
$value = (string) $wpdb->get_var("SELECT val FROM {$safe} WHERE id = 1");

$check(
    'the source URL is rewritten exactly once (got: ' . $value . ')',
    $value === $home . '/wp-content/uploads/x.png'
);

// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
$wpdb->query("DROP TABLE IF EXISTS {$safe}");

echo $fail === 0 ? "URL rewrite: OK\n" : "URL rewrite: {$fail} failure(s)\n";
