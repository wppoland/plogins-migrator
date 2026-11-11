<?php
/**
 * A restore onto another address keeps this site's own schedule and off-site
 * settings. Run inside wp-env:
 *   wp eval-file wp-content/plugins/<dir>/tests/site-bound-options.php
 *
 * @package Migrator
 */

use Migrator\Engine\Import\Importer;
use Migrator\Support\Workspace;

global $wpdb;
$fail  = 0;
$check = static function (string $label, bool $ok) use (&$fail): void {
    echo ($ok ? '  ok   ' : '  FAIL ') . $label . "\n";
    $fail += $ok ? 0 : 1;
};

$saved = [get_option('migrator_schedule', null), get_option('migrator_offsite', null)];

update_option('migrator_schedule', ['enabled' => true, 'marker' => 'target']);
delete_option('migrator_offsite');

$importer = new Importer(new Workspace(), $wpdb);
$capture  = new \ReflectionMethod(Importer::class, 'siteBoundOptions');
$put      = new \ReflectionMethod(Importer::class, 'putSiteBoundOptions');
$rows     = $capture->invoke($importer);

// What an imported source database would leave behind.
update_option('migrator_schedule', ['enabled' => true, 'marker' => 'source']);
update_option('migrator_offsite', ['enabled' => true, 'host' => 'ftp.source.example']);

$put->invoke($importer, $rows);
wp_cache_flush();

$schedule = get_option('migrator_schedule');
$check('the target keeps its own schedule', is_array($schedule) && 'target' === ($schedule['marker'] ?? ''));
$check('the source off-site storage is not inherited', false === get_option('migrator_offsite'));

foreach (['migrator_schedule', 'migrator_offsite'] as $i => $name) {
    null === $saved[$i] ? delete_option($name) : update_option($name, $saved[$i]);
}

echo 0 === $fail ? "site-bound options: OK\n" : "site-bound options: {$fail} failure(s)\n";
