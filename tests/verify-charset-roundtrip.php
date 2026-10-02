<?php
/**
 * Non-ASCII text must survive a dump and restore on a database whose default
 * charset is latin1 or utf8mb3. Run inside wp-env (needs CREATE DATABASE):
 *
 *   wp eval-file wp-content/plugins/<dir>/tests/verify-charset-roundtrip.php
 *
 * WordPress creates its tables as utf8mb4 and reads them over a utf8mb4
 * connection whatever the database default is. The dump used to declare SET
 * NAMES from @@character_set_database, so on a latin1-default database it
 * announced latin1 over UTF-8 bytes and every Polish letter and emoji came
 * back double-encoded, in the safety rollback too.
 *
 * @package Migrator
 */

use Migrator\Engine\Archive\Entry;
use Migrator\Engine\Archive\Manifest;
use Migrator\Engine\Archive\Writer;
use Migrator\Engine\Db\Dumper;
use Migrator\Engine\Db\SqlExecutor;
use Migrator\Engine\Export\Exporter;
use Migrator\Engine\Import\Importer;
use Migrator\Support\Workspace;

global $wpdb;

$fail  = 0;
$check = static function (string $l, bool $c) use (&$fail): void {
    echo ($c ? '  ok   ' : '  FAIL ') . $l . "\n";
    $c || $fail++;
};

$text = 'Zażółć gęślą jaźń, Grüße, 🚀🦄';
$ws   = new Workspace();
$ws->ensure();

foreach (['latin1', 'utf8mb3'] as $default) {
    echo "Database default charset {$default}\n";
    $name = 'migrator_cs_' . $default;
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    $wpdb->query("DROP DATABASE IF EXISTS `{$name}`");
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    $wpdb->query("CREATE DATABASE `{$name}` DEFAULT CHARACTER SET {$default}");

    $db = new wpdb(DB_USER, DB_PASSWORD, $name, DB_HOST);
    $db->set_prefix('mx_');
    $check("the database default really is {$default}", str_starts_with((string) $db->get_var('SELECT @@character_set_database'), 'latin1' === $default ? 'latin1' : 'utf8'));
    $check('wpdb itself talks utf8mb4', 'utf8mb4' === $db->charset);

    $db->query('CREATE TABLE mx_options (option_id bigint unsigned NOT NULL AUTO_INCREMENT, option_value longtext NOT NULL, PRIMARY KEY (option_id)) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    $db->insert('mx_options', ['option_value' => $text]);

    $dumpPath = $ws->path('charset-' . $default . '.sql');
    $handle   = fopen($dumpPath, 'wb');
    $dumper   = new Dumper($db);
    $dumper->dumpAll(['mx_options'], $handle);
    fclose($handle);
    $check('the dump declares utf8mb4', str_contains((string) file_get_contents($dumpPath), "SET NAMES utf8mb4;\n"));

    $db->query('DROP TABLE mx_options');
    (new SqlExecutor($db))->runFile($dumpPath);
    $check('the connection is back on utf8mb4 afterwards', 'utf8mb4' === $db->get_var('SELECT @@character_set_client'));
    // Read back over a fresh connection, the way the next page load will. The
    // restoring connection can hide the damage: it decodes with the same wrong
    // charset it encoded with.
    $fresh = new wpdb(DB_USER, DB_PASSWORD, $name, DB_HOST);
    $check('the text is byte-identical after restore', $text === $fresh->get_var('SELECT option_value FROM mx_options WHERE option_id = 1'));
    $fresh->close();

    @unlink($dumpPath);
    $db->close();
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    $wpdb->query("DROP DATABASE IF EXISTS `{$name}`");
}

// An archive made before 1.4.0 on a latin1-default database: SET NAMES latin1
// over UTF-8 bytes, and no dbCharset in the manifest. The importer reads it as
// utf8mb4, which is what the bytes are.
echo "Archive from before 1.4.0 that says SET NAMES latin1\n";
$table = $wpdb->prefix . 'migrator_cstest';
$dump  = "SET NAMES latin1;\nSET FOREIGN_KEY_CHECKS=0;\n"
    . "DROP TABLE IF EXISTS `{$table}`;\n"
    . "CREATE TABLE `{$table}` (`id` int NOT NULL, `val` text, PRIMARY KEY (`id`)) DEFAULT CHARSET=utf8mb4;\n"
    . "INSERT INTO `{$table}` (`id`, `val`) VALUES (1, '" . esc_sql($text) . "');\n"
    . "SET FOREIGN_KEY_CHECKS=1;\n";
$path = $ws->path('cstest.migrator');
$w    = new Writer($path);
$w->addString(Manifest::NAME, (string) wp_json_encode([
    'format'        => 'migrator',
    'formatVersion' => 1,
    'homeUrl'       => get_option('home'),
    'siteUrl'       => get_option('siteurl'),
    'abspath'       => untrailingslashit((string) ABSPATH),
    'contentDir'    => untrailingslashit((string) WP_CONTENT_DIR),
    'tablePrefix'   => $wpdb->prefix,
    'tables'        => [$table],
]), Entry::TYPE_MANIFEST);
$w->addString(Exporter::DB_ENTRY, $dump);
$w->finish();
(new Importer($ws, $wpdb))->import($path, false);
@unlink($path);
$fresh = new wpdb(DB_USER, DB_PASSWORD, DB_NAME, DB_HOST);
// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$check('the legacy archive restores the text intact', $text === $fresh->get_var("SELECT val FROM `{$table}` WHERE id = 1"));
$fresh->close();
$check('the site connection is back on utf8mb4', 'utf8mb4' === $wpdb->get_var('SELECT @@character_set_client'));
// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$wpdb->query("DROP TABLE IF EXISTS `{$table}`");

echo 0 === $fail ? "charset round trip: OK\n" : "charset round trip: {$fail} failure(s)\n";
