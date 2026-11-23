<?php
// existing-checked: tests/hooks-wired.php - it reads source as text; these helpers need the stub loaded.
/**
 * The standalone installer's pure helpers: prefix remap and URL pairs.
 *
 *   php tests/installer-stub.php
 *
 * @package Migrator
 */

declare(strict_types=1);

define('MIG_LIBRARY', true);
require dirname(__DIR__) . '/templates/installer-stub.txt';

$failures = 0;
$ok       = static function (string $label, bool $cond) use (&$failures): void {
    echo ($cond ? '  ok   ' : '  FAIL ') . $label . "\n";
    $cond || $failures++;
};

$map = ['`wp_posts`' => '`nx_posts`', '`wp_users`' => '`nx_users`'];

$ok('INSERT renames the table only', "INSERT INTO `nx_posts` (`a`) VALUES ('`wp_posts`')" === mig_remap($map, "INSERT INTO `wp_posts` (`a`) VALUES ('`wp_posts`')"));
$ok('CREATE renames REFERENCES too', 'CREATE TABLE `nx_posts` (x int, FOREIGN KEY (x) REFERENCES `nx_users` (ID))' === mig_remap($map, 'CREATE TABLE `wp_posts` (x int, FOREIGN KEY (x) REFERENCES `wp_users` (ID))'));
$ok('unmapped table untouched', 'DROP TABLE IF EXISTS `other`' === mig_remap($map, 'DROP TABLE IF EXISTS `other`'));
$ok('no map, no change', 'INSERT INTO `wp_posts` VALUES (1)' === mig_remap([], 'INSERT INTO `wp_posts` VALUES (1)'));

[$from, $to] = mig_pairs(['homeUrl' => 'https://old.test', 'siteUrl' => 'https://old.test', 'abspath' => '/var/old'], 'https://new.test/sub', '/srv/new');
$ok('home and site URL give one pair', 1 === count(array_keys($from, 'https://old.test', true)));
$ok('scheme-relative pair added', in_array('//old.test', $from, true));
$ok('url-encoded pair added', in_array(rawurlencode('https://old.test'), $from, true));
$ok('a subfolder move is not applied twice', 'https://new.test/sub/x' === str_replace($from, $to, 'https://old.test/x'));

$stub = (string) file_get_contents(dirname(__DIR__) . '/templates/installer-stub.txt');
$ok('key placeholder is present once', 1 === substr_count($stub, '__MIGRATOR_ACCESS_KEY_HASH__'));

echo $failures > 0 ? "installer-stub: {$failures} FAILED\n" : "installer-stub: OK\n";
exit($failures > 0 ? 1 : 0);
