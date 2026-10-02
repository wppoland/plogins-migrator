<?php
/**
 * The Search and Replace screen searches for exactly what was typed. Run
 * inside wp-env:
 *   wp eval-file wp-content/plugins/<dir>/tests/verify-search-replace-input.php
 *
 * Both values went through sanitize_text_field(), which strips tags, %-octets,
 * line breaks and repeated spaces, so the search looked for a string that was
 * not in the database.
 *
 * @package Migrator
 */

use Migrator\Admin\Ajax;
use Migrator\Plugin;

global $wpdb;
$fail  = 0;
$check = static function (string $l, bool $c) use (&$fail): void {
    echo ($c ? '  ok   ' : '  FAIL ') . $l . "\n";
    $c || $fail++;
};

$table = $wpdb->prefix . 'migrator_srinput';
// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$wpdb->query("DROP TABLE IF EXISTS `{$table}`");
$wpdb->query("CREATE TABLE `{$table}` (`id` int NOT NULL, `val` text, PRIMARY KEY (`id`)) DEFAULT CHARSET=utf8mb4");
$search = "50%25  off <b>today</b>";
$wpdb->insert($table, ['id' => 1, 'val' => 'Sale: ' . $search . '!']);

wp_set_current_user(1);
add_filter('wp_doing_ajax', '__return_true');
add_filter('wp_die_ajax_handler', static fn () => static function (): void {
    throw new \RuntimeException('json sent');
});
$_POST    = ['nonce' => wp_create_nonce('migrator'), 'search' => wp_slash($search), 'replace' => wp_slash('<i>half</i> price')];
$_REQUEST = $_POST;
ob_start();
try {
    Plugin::instance()->container()->get(Ajax::class)->searchReplace();
} catch (\RuntimeException $e) {
    // wp_send_json ended the "request".
}
ob_end_clean();

$check('the exact typed value was found and replaced', 'Sale: <i>half</i> price!' === $wpdb->get_var("SELECT val FROM `{$table}` WHERE id = 1"));
$wpdb->query("DROP TABLE IF EXISTS `{$table}`");
// phpcs:enable

echo 0 === $fail ? "search-replace input: OK\n" : "search-replace input: {$fail} failure(s)\n";
