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
$steps = 0;
while (($job['status'] ?? '') === 'running' && $steps < 200) {
    $job = $pipe->step();
    $steps++;
}
$check('pipeline completed', ($job['status'] ?? '') === 'done');
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
