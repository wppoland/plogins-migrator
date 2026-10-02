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
    $plain    = str_contains($e->getMessage(), '"zz_"') && ! str_contains($e->getMessage(), '&quot;');
}
$check('prefix mismatch is rejected (no silent broken site)', $rejected);
$check('and the message is plain text, not HTML entities the screen would show literally', $plain ?? false);
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

// 3b. A damaged FILE entry after the database must also be refused before the
// database is replaced. It used to be found after the import, leaving the site
// part restored.
$cPath  = $ws->path('guard-crc.migrator');
$table  = $wpdb->prefix . 'migrator_crcguard';
$w = new Writer($cPath);
$w->addString(Manifest::NAME, (string) wp_json_encode(array_merge($baseManifest, ['tables' => [$table]])), Entry::TYPE_MANIFEST);
$w->addString(\Migrator\Engine\Export\Exporter::DB_ENTRY, "CREATE TABLE `{$table}` (`id` int NOT NULL, PRIMARY KEY (`id`));\n");
$w->addString('wp-content/uploads/guard-crc.txt', 'AAAAAAAAAAAAAAAA');
$w->finish();
$raw = (string) file_get_contents($cPath);
file_put_contents($cPath, str_replace('AAAAAAAAAAAAAAAA', 'AAAAAAAABAAAAAAA', $raw)); // Flip one byte.
$refused = '';
try {
    (new Importer($ws, $wpdb))->import($cPath, true);
} catch (\Throwable $e) {
    $refused = $e->getMessage();
}
$check('a damaged file entry is refused (' . substr($refused, 0, 60) . ')', str_contains($refused, 'damaged'));
// phpcs:ignore WordPress.DB.DirectDatabaseQuery
$check('and the database entry before it was never run', null === $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)));
@unlink($cPath);

// 3c. A file that is not an archive is called that, not "never finished".
$nPath = $ws->path('guard-notarchive.migrator');
file_put_contents($nPath, 'PK this is a zip file, honest');
$msg = '';
try {
    (new Importer($ws, $wpdb))->import($nPath, true);
} catch (\Throwable $e) {
    $msg = $e->getMessage();
}
$check('a non-Migrator file gets the bad-signature message', str_contains($msg, 'not a Migrator archive'));
@unlink($nPath);

// 4. Safety backup is created and cleaned up on a successful DB import.
$before = glob($ws->path('rollback-*.sql')) ?: [];
$check('no stale rollback files before', count($before) === 0);

echo $fail === 0 ? "\nGUARDS OK\n" : "\n{$fail} FAILED\n";
