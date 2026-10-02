<?php
/**
 * Leftovers of interrupted runs are cleaned up. Run inside wp-env:
 *   wp eval-file wp-content/plugins/<dir>/tests/verify-workspace-sweep.php
 *
 * A failed import left its temporary SQL (a full copy of the archive's
 * database) in the workspace for good, and abandoned uploads, rollback dumps
 * and half-written archives were never removed either.
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
$fail  = 0;
$check = static function (string $l, bool $c) use (&$fail): void {
    echo ($c ? '  ok   ' : '  FAIL ') . $l . "\n";
    $c || $fail++;
};

$ws = new Workspace();
$ws->ensure();

$old   = ['upload-abc.migrator', 'rollback-20260101-000000-abc.sql', 'import-abc.sql', 'site-20260101.migrator.part', 'job-abc.list', 'decompress-abc.migrator'];
$fresh = 'upload-fresh.migrator';
$kept  = 'localhost-20260101-000000-keep.migrator';
foreach (array_merge($old, [$fresh, $kept]) as $name) {
    file_put_contents($ws->path($name), 'x');
}
foreach (array_merge($old, [$kept]) as $name) {
    touch($ws->path($name), time() - 2 * DAY_IN_SECONDS);
}
$ws->sweep();
foreach ($old as $name) {
    $check("{$name} older than a day is removed", ! file_exists($ws->path($name)));
}
$check('a fresh upload in progress is kept', file_exists($ws->path($fresh)));
$check('a finished backup is never swept, however old', file_exists($ws->path($kept)));
@unlink($ws->path($fresh));
@unlink($ws->path($kept));

// A dump that fails part way: the temporary SQL must not stay behind.
$manifest = [
    'format'        => 'migrator',
    'formatVersion' => 1,
    'homeUrl'       => get_option('home'),
    'siteUrl'       => get_option('siteurl'),
    'abspath'       => untrailingslashit((string) ABSPATH),
    'contentDir'    => untrailingslashit((string) WP_CONTENT_DIR),
    'tablePrefix'   => $wpdb->prefix,
    'tables'        => [],
];
$path = $ws->path('sweep-bad-sql.migrator');
$w    = new Writer($path);
$w->addString(Manifest::NAME, (string) wp_json_encode($manifest), Entry::TYPE_MANIFEST);
$w->addString(Exporter::DB_ENTRY, "THIS IS NOT SQL;\n");
$w->finish();
$wpdb->suppress_errors(true);
try {
    (new Importer($ws, $wpdb))->import($path, false);
} catch (\Throwable $e) {
    // Expected: the statement fails and the database is rolled back.
}
$wpdb->suppress_errors(false);
@unlink($path);
$check('a failed import leaves no import-*.sql behind', [] === (glob($ws->path('import-*.sql')) ?: []));
$check('and the successful rollback removed its dump', [] === (glob($ws->path('rollback-*.sql')) ?: []));

echo 0 === $fail ? "workspace sweep: OK\n" : "workspace sweep: {$fail} failure(s)\n";
