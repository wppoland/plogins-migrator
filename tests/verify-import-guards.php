<?php
/**
 * Live checks for the import safety guards. Run inside wp-env:
 *   wp eval-file wp-content/plugins/migrator/tests/verify-import-guards.php
 *
 * @package Migrator
 */

use Migrator\Engine\Archive\Entry;
use Migrator\Engine\Archive\Manifest;
use Migrator\Engine\Archive\Writer;
use Migrator\Engine\Import\Importer;
use Migrator\Support\Workspace;

global $wpdb;
$ws = new Workspace();
$ws->ensure();

$fail = 0;
$check = static function (string $l, bool $c) use (&$fail): void {
    echo ($c ? '  ok   ' : '  FAIL ') . $l . "\n";
    $c || $fail++;
};

$baseManifest = [
    'format'        => 'migrator',
    'formatVersion' => 1,
    'homeUrl'       => get_option('home'),
    'siteUrl'       => get_option('siteurl'),
    'abspath'       => untrailingslashit((string) ABSPATH),
    'contentDir'    => untrailingslashit((string) WP_CONTENT_DIR),
    'tablePrefix'   => $wpdb->prefix,
    'tables'        => [],
];

// 1. Prefix mismatch must hard-fail before touching the DB.
$mPath = $ws->path('guard-prefix.migrator');
$w = new Writer($mPath);
$w->addString(Manifest::NAME, (string) wp_json_encode(array_merge($baseManifest, ['tablePrefix' => 'zz_'])), Entry::TYPE_MANIFEST);
$w->finish();
$rejected = false;
try {
    (new Importer($ws, $wpdb))->import($mPath, false);
} catch (\Throwable $e) {
    $rejected = str_contains($e->getMessage(), 'prefix mismatch');
}
$check('prefix mismatch is rejected (no silent broken site)', $rejected);
@unlink($mPath);

// 2. Zip-slip: an entry path escaping wp-content must NOT be written.
$zPath = $ws->path('guard-zip.migrator');
$w = new Writer($zPath);
$w->addString(Manifest::NAME, (string) wp_json_encode($baseManifest), Entry::TYPE_MANIFEST);
$w->addString('wp-content/../../zz-evil.txt', 'pwned'); // malicious path
$w->finish();
$evil = dirname(dirname(untrailingslashit((string) WP_CONTENT_DIR))) . '/zz-evil.txt';
@unlink($evil);
(new Importer($ws, $wpdb))->import($zPath, true); // files enabled
$check('zip-slip entry is blocked (no write outside wp-content)', ! file_exists($evil));
@unlink($evil);
@unlink($zPath);

// 3. An archive cut short must be refused BEFORE the database is replaced.
// The database entry comes before the files, so finding the cut during the read
// finds it too late: the site is already standing on the archive's database.
$tPath = $ws->path('guard-truncated.migrator');
$w = new Writer($tPath);
$w->addString(Manifest::NAME, (string) wp_json_encode($baseManifest), Entry::TYPE_MANIFEST);
$w->addString('wp-content/uploads/guard-cut.txt', 'content that never finished being written');
$w->finish();
file_put_contents($tPath, substr((string) file_get_contents($tPath), 0, -9)); // Cut the end marker off.
$stale = glob($ws->path('rollback-*.sql')) ?: [];
$refused = false;
try {
    (new Importer($ws, $wpdb))->import($tPath, true);
} catch (\Throwable $e) {
    $refused = str_contains($e->getMessage(), 'never finished');
}
$check('an archive cut short is refused, not restored half way', $refused);
$check('and nothing was touched, so no safety dump was taken', (glob($ws->path('rollback-*.sql')) ?: []) === $stale);
$check('and the cut archive extracted no files', ! file_exists(WP_CONTENT_DIR . '/uploads/guard-cut.txt'));
@unlink($tPath);

// 4. Safety backup is created and cleaned up on a successful DB import.
$before = glob($ws->path('rollback-*.sql')) ?: [];
$check('no stale rollback files before', count($before) === 0);

echo $fail === 0 ? "\nGUARDS OK\n" : "\n{$fail} FAILED\n";
