<?php
/**
 * Table prefix remap on restore. Run inside wp-env:
 *   wp eval-file wp-content/plugins/migrator/tests/verify-prefix-remap.php
 *
 * existing-checked: tests/verify-import-guards.php - that file restores whole
 * archives; this one checks the statement rewrite and the key move on their own.
 *
 * @package Migrator
 */

use Migrator\Engine\Import\Importer;
use Migrator\Support\Workspace;

global $wpdb;

$fail  = 0;
$check = static function (string $l, bool $c) use (&$fail): void {
    echo ($c ? '  ok   ' : '  FAIL ') . $l . "\n";
    $c || $fail++;
};

// 1. Statements, wp_ to wp_2_: one prefix starts with the other.
$map = Importer::prefixMap(['wp_posts', 'wp_2_posts', 'wp_wc_orders', 'other_table'], 'wp_', 'wp_2_');
$check('prefixMap maps exact names and skips other prefixes', ['wp_posts' => 'wp_2_posts', 'wp_2_posts' => 'wp_2_2_posts', 'wp_wc_orders' => 'wp_2_wc_orders'] === $map);
$r = static fn (string $sql): string => Importer::renameTables($sql, $map, 'wp_', 'wp_2_');

$check('DROP TABLE', 'DROP TABLE IF EXISTS `wp_2_posts`' === $r('DROP TABLE IF EXISTS `wp_posts`'));
$check('a table already named wp_2_ is moved once, not twice', 'DROP TABLE IF EXISTS `wp_2_2_posts`' === $r('DROP TABLE IF EXISTS `wp_2_posts`'));
$check(
    'CREATE TABLE renames its foreign key target, not a column that starts with the prefix',
    "CREATE TABLE `wp_2_wc_orders` (\n  `id` int NOT NULL,\n  `wp_posts_id` int,\n  CONSTRAINT `fk` FOREIGN KEY (`id`) REFERENCES `wp_2_posts` (`ID`)\n)"
        === $r("CREATE TABLE `wp_wc_orders` (\n  `id` int NOT NULL,\n  `wp_posts_id` int,\n  CONSTRAINT `fk` FOREIGN KEY (`id`) REFERENCES `wp_posts` (`ID`)\n)")
);
$insert = "INSERT INTO `wp_posts` VALUES (1,'see `wp_posts` and wp_capabilities','a:1:{s:15:\"wp_capabilities\";b:1;}')";
$check('INSERT renames its table and keeps the row data byte for byte', "INSERT INTO `wp_2_posts` VALUES (1,'see `wp_posts` and wp_capabilities','a:1:{s:15:\"wp_capabilities\";b:1;}')" === $r($insert));
$check(
    'a view is renamed with the tables it reads',
    'CREATE ALGORITHM=UNDEFINED SQL SECURITY INVOKER VIEW `wp_2_v` AS select `wp_2_posts`.`ID` AS `ID` from `wp_2_posts`'
        === $r('CREATE ALGORITHM=UNDEFINED SQL SECURITY INVOKER VIEW `wp_v` AS select `wp_posts`.`ID` AS `ID` from `wp_posts`')
);
$check('DROP VIEW', 'DROP VIEW IF EXISTS `wp_2_v`' === $r('DROP VIEW IF EXISTS `wp_v`'));
$check('a trigger body is renamed', 'CREATE TRIGGER `t` AFTER INSERT ON `wp_2_posts` FOR EACH ROW DELETE FROM `wp_2_wc_orders`' === $r('CREATE TRIGGER `t` AFTER INSERT ON `wp_posts` FOR EACH ROW DELETE FROM `wp_wc_orders`'));
$check('SET lines pass untouched', 'SET FOREIGN_KEY_CHECKS=0' === $r('SET FOREIGN_KEY_CHECKS=0'));
$refused = false;
try {
    $r('DROP TABLE IF EXISTS `someone_elses_posts`');
} catch (\RuntimeException $e) {
    $refused = true;
}
$check('a statement on a table outside the archive prefix is refused', $refused);

// 2. The other way, wp_2_ to wp_.
$back = Importer::prefixMap(['wp_2_posts'], 'wp_2_', 'wp_');
$check('wp_2_ to wp_', 'INSERT INTO `wp_posts` VALUES (1)' === Importer::renameTables('INSERT INTO `wp_2_posts` VALUES (1)', $back, 'wp_2_', 'wp_'));

// 3. Keys, on scratch copies of the options and usermeta tables.
$opt  = $wpdb->prefix . 'zz_remap_options';
$meta = $wpdb->prefix . 'zz_remap_usermeta';
$reset = static function () use ($wpdb, $opt, $meta): void {
    // phpcs:disable WordPress.DB.DirectDatabaseQuery
    $wpdb->query("DROP TABLE IF EXISTS `{$opt}`, `{$meta}`");
    $wpdb->query("CREATE TABLE `{$opt}` LIKE `{$wpdb->options}`");
    $wpdb->query("CREATE TABLE `{$meta}` LIKE `{$wpdb->usermeta}`");
    // phpcs:enable WordPress.DB.DirectDatabaseQuery
};
$opt_value  = static fn (string $name) => $wpdb->get_var($wpdb->prepare('SELECT option_value FROM %i WHERE option_name = %s', $opt, $name));
$meta_value = static fn (int $user, string $key) => $wpdb->get_col($wpdb->prepare('SELECT meta_value FROM %i WHERE user_id = %d AND meta_key = %s', $meta, $user, $key));

$roles = serialize(['administrator' => ['name' => 'Administrator', 'capabilities' => ['manage_options' => true]]]);
$caps  = serialize(['administrator' => true]);
$importer = new Importer(new Workspace(), $wpdb);

// 3a. wp_ to wp_2_.
$reset();
$wpdb->insert($opt, ['option_name' => 'wp_user_roles', 'option_value' => $roles, 'autoload' => 'on']);
$wpdb->insert($opt, ['option_name' => 'wp_page_for_privacy_policy', 'option_value' => '3', 'autoload' => 'on']);
$wpdb->insert($meta, ['user_id' => 1, 'meta_key' => 'wp_capabilities', 'meta_value' => $caps]);
$wpdb->insert($meta, ['user_id' => 1, 'meta_key' => 'wp_user_level', 'meta_value' => '10']);
$wpdb->insert($meta, ['user_id' => 1, 'meta_key' => 'wp_2_capabilities', 'meta_value' => 'old-subsite']);
$wpdb->insert($meta, ['user_id' => 1, 'meta_key' => 'nickname', 'meta_value' => 'admin']);
$importer->remapPrefixKeys('wp_', 'wp_2_', $opt, $meta);
$check('user_roles moves with its serialized value intact', $roles === $opt_value('wp_2_user_roles') && is_array(unserialize((string) $opt_value('wp_2_user_roles'))));
$check('core option wp_page_for_privacy_policy keeps its name', '3' === $opt_value('wp_page_for_privacy_policy'));
$check('wp_capabilities becomes wp_2_capabilities, serialized value intact', [$caps] === $meta_value(1, 'wp_2_capabilities'));
$check('wp_user_level becomes wp_2_user_level', ['10'] === $meta_value(1, 'wp_2_user_level'));
$check('an existing wp_2_ key is moved once, to wp_2_2_', ['old-subsite'] === $meta_value(1, 'wp_2_2_capabilities'));
$check('keys without the prefix stay', ['admin'] === $meta_value(1, 'nickname'));

// 3b. wp_2_ to wp_, with rows already under the new name.
$reset();
$wpdb->insert($opt, ['option_name' => 'wp_2_user_roles', 'option_value' => $roles, 'autoload' => 'on']);
$wpdb->insert($opt, ['option_name' => 'wp_user_roles', 'option_value' => 'stale', 'autoload' => 'on']);
$wpdb->insert($meta, ['user_id' => 1, 'meta_key' => 'wp_2_capabilities', 'meta_value' => $caps]);
$wpdb->insert($meta, ['user_id' => 1, 'meta_key' => 'wp_capabilities', 'meta_value' => 'stale']);
$wpdb->insert($meta, ['user_id' => 2, 'meta_key' => 'wp_capabilities', 'meta_value' => 'kept']);
$importer->remapPrefixKeys('wp_2_', 'wp_', $opt, $meta);
$check('the archive\'s roles replace a stale row under the new name', $roles === $opt_value('wp_user_roles') && null === $opt_value('wp_2_user_roles'));
$check('the archive\'s capabilities replace a stale row, one row left', [$caps] === $meta_value(1, 'wp_capabilities'));
$check('a row with no archive counterpart is left alone', ['kept'] === $meta_value(2, 'wp_capabilities'));

$wpdb->query("DROP TABLE IF EXISTS `{$opt}`, `{$meta}`"); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

echo 0 === $fail ? "verify-prefix-remap: OK\n" : "verify-prefix-remap: {$fail} failure(s)\n";
if ($fail > 0) {
    exit(1);
}
