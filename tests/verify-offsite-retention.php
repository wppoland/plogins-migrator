<?php
/**
 * Off-site retention deletes only this site's scheduled archives. Run inside
 * wp-env:
 *
 *   wp eval-file wp-content/plugins/<dir>/tests/verify-offsite-retention.php
 *
 * The FTP half runs when ext-ftp is loaded and an FTP server answers at the
 * host given as the first argument (default migrator-ftp, user bk, password
 * secret), for example pyftpdlib in a container on the wp-env network.
 *
 * Both destinations pruned every *.migrator* file in the target, newest first
 * by modification time: a folder or FTP directory shared with another site,
 * or holding a manual backup, lost those files to this site's retention. FTP
 * without MDTM sorted on 0 for every file and deleted an arbitrary set.
 *
 * @package Migrator
 */

use Migrator\Backup\Schedule;
use Migrator\Storage\FtpDestination;
use Migrator\Storage\LocalFolderDestination;
use Migrator\Support\Workspace;

$fail  = 0;
$check = static function (string $l, bool $c) use (&$fail): void {
    echo ($c ? '  ok   ' : '  FAIL ') . $l . "\n";
    $c || $fail++;
};

$prefix = Schedule::archivePrefix();
$ours   = [];
for ($i = 1; $i <= 4; $i++) {
    $ours[] = sprintf('%s2026010%d-120000-abc%d.migrator', $prefix, $i, $i);
}
$foreign = ['other.example-scheduled-20260101-120000-zzz.migrator', 'manual-upload.migrator', str_replace('-scheduled-', '-', $prefix) . '20260101-120000-man.migrator'];

echo "Folder destination\n";
$dir = '/tmp/migrator-offsite-' . wp_generate_password(6, false);
wp_mkdir_p($dir);
foreach (array_merge($ours, $foreign) as $n => $file) {
    file_put_contents($dir . '/' . $file, 'x');
    touch($dir . '/' . $file, time() - 1000 + ($n * 10));
}
$folder = new LocalFolderDestination($dir);
$check('a folder outside the site is accepted', $folder->isConfigured());
$kept = $folder->prune(2);
$left = array_map('basename', glob($dir . '/*') ?: []);
$check('two of ours are kept', 2 === $kept && 2 === count(array_intersect($ours, $left)));
$check('the two newest of ours are the ones kept', in_array($ours[2], $left, true) && in_array($ours[3], $left, true));
$check('nothing that is not ours was deleted', [] === array_diff($foreign, $left));
array_map('unlink', glob($dir . '/*') ?: []);
rmdir($dir);

echo "Folder validation\n";
$check('the workspace is refused', null !== (new LocalFolderDestination((new Workspace())->path()))->problem());
$check('a folder inside the workspace is refused', null !== (new LocalFolderDestination((new Workspace())->path() . '/copies'))->problem());
$check('a folder inside the web root is refused', null !== (new LocalFolderDestination(WP_CONTENT_DIR . '/uploads/backups'))->problem());
$check('a relative path is refused', null !== (new LocalFolderDestination('backups'))->problem());
$check('a path that escapes back into the site with .. is refused', null !== (new LocalFolderDestination('/tmp/../' . ltrim((string) ABSPATH, '/')))->problem());

$host = $args[0] ?? 'migrator-ftp';
if (! FtpDestination::available() || ! @fsockopen($host, 21, $errno, $errstr, 2)) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
    echo "  skip FTP: ext-ftp missing or no server at {$host}:21\n";
} else {
    echo "FTP destination ({$host})\n";
    $remote = '/retention-' . wp_generate_password(6, false);
    $ftp    = new FtpDestination($host, 21, 'bk', 'secret', $remote);
    $src    = tempnam(sys_get_temp_dir(), 'mgr');
    file_put_contents($src, 'archive');
    foreach (array_merge($ours, $foreign) as $file) {
        $named = dirname($src) . '/' . $file;
        copy($src, $named);
        $ftp->store($named);
        unlink($named);
        sleep(1); // MDTM has one-second resolution.
    }
    $kept = $ftp->prune(2);
    $left = array_column($ftp->archives(), 'file');
    $check('two of ours are kept on the FTP server', 2 === $kept && 2 === count(array_intersect($ours, $left)));
    $check('the two newest are the ones kept', in_array($ours[2], $left, true) && in_array($ours[3], $left, true));
    $check('nothing that is not ours was deleted on the FTP server', [] === array_diff($foreign, $left));
    unlink($src);
}

echo 0 === $fail ? "offsite retention: OK\n" : "offsite retention: {$fail} failure(s)\n";
