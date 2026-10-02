<?php
/**
 * Media moved outside wp-content is reported, not silently missing. Run inside
 * wp-env:  wp eval-file wp-content/plugins/<dir>/tests/verify-uploads-outside.php
 *
 * Archive entries are wp-content-relative, so with the UPLOADS constant
 * pointing elsewhere the media library was never in the backup and nothing
 * said so.
 *
 * @package Migrator
 */

use Migrator\Engine\Archive\Manifest;
use Migrator\Engine\Db\Dumper;
use Migrator\Engine\Export\ExportOptions;
use Migrator\Engine\Export\Exporter;
use Migrator\Engine\Import\Preflight;
use Migrator\Support\Workspace;

global $wpdb;
$fail  = 0;
$check = static function (string $l, bool $c) use (&$fail): void {
    echo ($c ? '  ok   ' : '  FAIL ') . $l . "\n";
    $c || $fail++;
};

$check('uploads inside wp-content are not flagged', null === Exporter::uploadsOutsideContent());

// The same thing UPLOADS = 'media' does: the folder moves under ABSPATH.
$moved = static function (array $dirs): array {
    $dirs['basedir'] = untrailingslashit(ABSPATH) . '/media';

    return $dirs;
};
add_filter('upload_dir', $moved);
$check('uploads outside wp-content are flagged', untrailingslashit(ABSPATH) . '/media' === Exporter::uploadsOutsideContent());

$ws      = new Workspace();
$archive = $ws->path('uploads-outside.migrator');
$result  = (new Exporter($ws, new Dumper($wpdb)))->export($archive, null, ExportOptions::fromArray(['no_database' => true, 'no_plugins' => true, 'no_themes' => true]));
$check('the export result warns about it', str_contains(implode(' ', $result['warnings']), 'outside wp-content'));
@unlink($archive);

$manifest = Manifest::forThisSite('test');
$levels   = array_column(Preflight::check($manifest, 1000, false), 'level', 'label');
$check('preflight warns before a restore', 'warn' === ($levels['Media library'] ?? ''));
remove_filter('upload_dir', $moved);

echo 0 === $fail ? "uploads outside: OK\n" : "uploads outside: {$fail} failure(s)\n";
