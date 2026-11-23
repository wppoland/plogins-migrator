<?php
/**
 * Standalone tests for the pre-export file warnings:  php tests/export-scan-warnings.php
 *
 * existing-checked: tests/file-scanner.php - it covers the walk and the
 * exclusions; this covers FileScanner::concerns() and findings().
 *
 * @package Migrator
 */

declare(strict_types=1);

define('ABSPATH', __DIR__ . '/');

require __DIR__ . '/../src/Engine/Files/FileScanner.php';

use Migrator\Engine\Files\FileScanner;

$fail = 0;
$ok   = static function (string $label, bool $cond) use (&$fail): void {
    echo ($cond ? '  ok   ' : '  FAIL ') . $label . "\n";
    $cond || $fail++;
};
$is = static fn (string $rel, string $kind, int $size = 10, bool $readable = true): bool => in_array($kind, FileScanner::concerns($rel, $size, $readable), true);

foreach (['.env', 'uploads/.env.production', '.htpasswd', 'id_rsa', 'uploads/id_ed25519', 'wp-config.php', 'wp-config.php.bak', 'wp-config-old.php', 'uploads/dump.sql', 'uploads/db.sql.gz', 'server.pem', 'uploads/private.key', 'backup-2026.zip', 'uploads/Backup_site.zip', 'uploads/repo/.git/config'] as $rel) {
    $ok("{$rel} is sensitive", $is($rel, 'sensitive'));
}
foreach (['.env.example', 'uploads/.env.sample', 'wp-config-sample.php', 'plugins/woocommerce/cacert.pem', 'plugins/x/schema.sql', 'themes/t/key.key', 'uploads/photo.jpg', 'uploads/my-backup.zip', 'plugins/x/environment.php'] as $rel) {
    $ok("{$rel} is not sensitive", ! $is($rel, 'sensitive'));
}

$ok('a 150 MB file is large', $is('uploads/video.mp4', 'large', 150 * 1024 * 1024));
$ok('a 100 MB file is not', ! $is('uploads/video.mp4', 'large', 100 * 1024 * 1024));
$ok('a path over 250 characters is long', $is('uploads/' . str_repeat('a', 240) . '.txt', 'long'));
$ok('a path of 250 characters is not', ! $is(str_repeat('a', 250 - strlen('wp-content/')), 'long'));
$ok('a Latin-1 name is flagged as not UTF-8', $is("uploads/caf\xE9.jpg", 'encoding'));
$ok('a UTF-8 name with diacritics is fine', ! $is('uploads/zażółć.jpg', 'encoding'));
$ok('an unreadable file is flagged', $is('uploads/a.txt', 'unreadable', 10, false));
$ok('an ordinary file has no concerns', [] === FileScanner::concerns('uploads/2026/11/a.jpg', 2048, true));
$ok('printable() turns bytes that are not UTF-8 into ?', 'uploads/caf?.jpg' === FileScanner::printable("uploads/caf\xE9.jpg"));

// findings() walks the export set and reports a .git folder once.
$dir = sys_get_temp_dir() . '/migrator-scan-' . bin2hex(random_bytes(4));
mkdir($dir . '/uploads/repo/.git/objects', 0777, true);
file_put_contents($dir . '/.env', 'DB_PASSWORD=x');
file_put_contents($dir . '/uploads/repo/.git/config', '[core]');
file_put_contents($dir . '/uploads/repo/.git/objects/a', 'x');
file_put_contents($dir . '/uploads/ok.txt', 'x');
$found = (new FileScanner())->findings($dir);
$paths = array_column($found, 'path');
$ok('findings() lists the .env', in_array('.env', $paths, true));
$ok('findings() reports the .git folder once, not each file', 1 === count(array_keys($paths, 'uploads/repo/.git', true)) && 2 === count($found));
$ok('with .git pruned by name it is not reported at all', ['.env'] === array_column((new FileScanner(['.git']))->findings($dir), 'path'));
exec('rm -rf ' . escapeshellarg($dir));

echo 0 === $fail ? "export-scan-warnings: OK\n" : "export-scan-warnings: {$fail} failure(s)\n";
exit($fail > 0 ? 1 : 0);
