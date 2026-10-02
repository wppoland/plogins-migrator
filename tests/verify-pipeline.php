<?php
/**
 * Exercises the resumable ExportPipeline (start + step loop) and checks the
 * archive reads back with SET NAMES in the dump. Run inside wp-env:
 *   wp eval-file wp-content/plugins/migrator/tests/verify-pipeline.php
 *
 * @package Migrator
 */

use Migrator\Engine\Archive\Reader;
use Migrator\Engine\Db\Dumper;
use Migrator\Engine\Export\ExportOptions;
use Migrator\Engine\Export\ExportPipeline;
use Migrator\Engine\Export\Exporter;
use Migrator\Support\Workspace;

global $wpdb;
$fail = 0;
$check = static function (string $l, bool $c) use (&$fail): void {
    echo ($c ? '  ok   ' : '  FAIL ') . $l . "\n";
    $c || $fail++;
};

$ws = new Workspace();
$ws->ensure();
$pipe = new ExportPipeline($ws, new Dumper($wpdb));

// Exclude media to keep the run quick; files (plugins/themes) still exercise the
// byte-offset streaming + truncate guard.
$job = $pipe->start(ExportOptions::fromArray(['no_media' => true]));
$check('an export in progress is written under a .part name', str_ends_with((string) $job['dest'], ExportPipeline::PART));
$listed = array_map('basename', array_merge(glob($ws->path('*.migrator')) ?: [], glob($ws->path('*.migrator.gz')) ?: []));
$check('so the backups list cannot offer it', ! in_array(basename((string) $job['dest']), $listed, true));
$check('a second export is refused while this one runs', $pipe->isRunning());

// One file the web server cannot read must be skipped with a warning, not fail
// the whole export.
$locked = WP_CONTENT_DIR . '/migrator-unreadable.txt';
file_put_contents($locked, 'secret');
chmod($locked, 0000);
$unreadableTestable = ! is_readable($locked); // root reads anything; then this part is skipped.

$steps = 0;
while (($job['status'] ?? '') === 'running' && $steps < 200) {
    $job = $pipe->step();
    $steps++;
}
$check('pipeline completed', ($job['status'] ?? '') === 'done');
$check('the finished archive has its final name', str_ends_with((string) $job['dest'], '.migrator') && is_file((string) $job['dest']));
$check('and the .part is gone', ! is_file((string) $job['dest'] . ExportPipeline::PART));
$check('a finished export no longer blocks a new one', ! $pipe->isRunning());
if ($unreadableTestable) {
    $check('an unreadable file is reported, not fatal', str_contains(implode(' ', (array) ($job['warnings'] ?? [])), 'migrator-unreadable.txt'));
} else {
    echo "  skip unreadable-file check (running as a user that reads everything)\n";
}
chmod($locked, 0644);
@unlink($locked);
$check('took at least one step', $steps >= 1);

$archive = (string) ($job['dest'] ?? '');
$sql = '';
$files = 0;
$crcOk = true;
try {
    $r = new Reader($archive);
    while (($e = $r->nextEntry()) !== null) {
        if (Exporter::DB_ENTRY === $e->path) {
            $sql = $r->readContents();
        } elseif (str_starts_with($e->path, 'wp-content/')) {
            $files++;
            $r->skip();
        } else {
            $r->skip();
        }
    }
    $r->close();
} catch (\RuntimeException $ex) {
    $crcOk = false;
    echo '  (exception: ' . $ex->getMessage() . ")\n";
}

$check('archive read back, CRC verified', $crcOk);
$check('dump has SET NAMES (charset pinned)', (bool) preg_match('/SET NAMES \w+;/', $sql));
$check('files were archived', $files > 0);

if ($archive !== '') {
    wp_delete_file($archive);
}
$pipe->clear();

echo $fail === 0 ? "\nPIPELINE OK\n" : "\n{$fail} FAILED\n";
