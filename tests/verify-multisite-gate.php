<?php
/**
 * On a network, Migrator belongs to super admins only. Run on a MULTISITE
 * install (the wp-env tests site after `wp core multisite-convert`):
 *
 *   wp eval-file wp-content/plugins/<dir>/tests/verify-multisite-gate.php
 *
 * Every subsite administrator has manage_options, which was the only check, so
 * any of them could download a backup holding the network's users table and
 * every site's files, or restore over them.
 *
 * @package Migrator
 */

use Migrator\Support\Access;

if (! is_multisite()) {
    echo "SKIP: not a multisite install.\n";

    return;
}

$fail  = 0;
$check = static function (string $l, bool $c) use (&$fail): void {
    echo ($c ? '  ok   ' : '  FAIL ') . $l . "\n";
    $c || $fail++;
};

$login = 'migrator_site_admin';
$user  = get_user_by('login', $login);
$id    = $user ? $user->ID : wp_insert_user(['user_login' => $login, 'user_pass' => wp_generate_password(), 'user_email' => 'siteadmin@example.test', 'role' => 'administrator']);

wp_set_current_user((int) $id);
$check('a subsite administrator has manage_options', current_user_can('manage_options'));
$check('but is not allowed into Migrator', ! Access::allowed());

$supers = get_super_admins();
wp_set_current_user((int) get_user_by('login', $supers[0])->ID);
$check('a super admin is allowed', Access::allowed());
$check('the capability asked for is manage_network_options', 'manage_network_options' === Access::capability());

echo 0 === $fail ? "multisite gate: OK\n" : "multisite gate: {$fail} failure(s)\n";
