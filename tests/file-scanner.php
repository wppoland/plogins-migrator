<?php
/**
 * Standalone tests for FileScanner:  php tests/file-scanner.php
 *
 * @package Migrator
 */

declare(strict_types=1);

define('ABSPATH', __DIR__ . '/');

require __DIR__ . '/../src/Engine/Files/FileScanner.php';

use Migrator\Engine\Files\FileScanner;

$failures = 0;
function ok(string $label, bool $cond): void
{
    global $failures;
    echo ($cond ? "  ok   " : "  FAIL ") . $label . "\n";
    if (! $cond) {
        $failures++;
    }
}

$root = sys_get_temp_dir() . '/migrator-scan-' . uniqid();
mkdir($root . '/uploads/2026', 0777, true);
mkdir($root . '/node_modules/pkg', 0777, true);
mkdir($root . '/themes/x', 0777, true);
mkdir($root . '/migrator-backups', 0777, true);

file_put_contents($root . '/uploads/2026/a.jpg', str_repeat('a', 100));
file_put_contents($root . '/uploads/b.txt', 'bb');
file_put_contents($root . '/themes/x/style.css', 'css');
file_put_contents($root . '/node_modules/pkg/index.js', 'should be excluded');
file_put_contents($root . '/migrator-backups/old.migrator', 'should be excluded');
file_put_contents($root . '/.DS_Store', 'junk');

$scanner = new FileScanner(
    ['node_modules', '.DS_Store'],
    [$root . '/migrator-backups'],
);

$found = [];
foreach ($scanner->scan($root) as $f) {
    $found[$f['rel']] = $f['size'];
}
ksort($found);

ok('included files present with correct rel paths', array_keys($found) === [
    'themes/x/style.css',
    'uploads/2026/a.jpg',
    'uploads/b.txt',
]);
ok('sizes reported correctly', ($found['uploads/2026/a.jpg'] ?? -1) === 100 && ($found['uploads/b.txt'] ?? -1) === 2);
ok('node_modules pruned by name', ! isset($found['node_modules/pkg/index.js']));
ok('workspace pruned by absolute path', empty(array_filter(array_keys($found), static fn ($p) => str_contains($p, 'migrator-backups'))));
ok('.DS_Store pruned by name', ! isset($found['.DS_Store']));

// Symlink skipping (avoid loops / escaping the root).
$outside = sys_get_temp_dir() . '/migrator-outside-' . uniqid();
mkdir($outside);
file_put_contents($outside . '/secret.txt', 'x');
@symlink($outside, $root . '/themes/linked');
$found2 = [];
foreach ($scanner->scan($root) as $f) {
    $found2[$f['rel']] = true;
}
ok('symlinked directory not followed', empty(array_filter(array_keys($found2), static fn ($p) => str_contains($p, 'linked'))));

// cleanup
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
foreach ($it as $i) {
    $i->isDir() && ! $i->isLink() ? @rmdir($i->getPathname()) : @unlink($i->getPathname());
}
@rmdir($root);
@unlink($outside . '/secret.txt');
@rmdir($outside);

// A directory that cannot be opened (or vanished mid-scan) is skipped, not fatal.
$locked = sys_get_temp_dir() . '/migrator-locked-' . uniqid();
mkdir($locked . '/cache', 0777, true);
file_put_contents($locked . '/keep.txt', 'k');
file_put_contents($locked . '/cache/x.tmp', 'x');
chmod($locked . '/cache', 0000);
$got = [];
$err = '';
try {
    foreach ((new FileScanner([], []))->scan($locked) as $f) {
        $got[] = $f['rel'];
    }
} catch (\Throwable $e) {
    $err = $e->getMessage();
}
chmod($locked . '/cache', 0777);
ok('an unreadable directory does not stop the scan' . ('' !== $err ? " ({$err})" : ''), '' === $err && in_array('keep.txt', $got, true));
@unlink($locked . '/cache/x.tmp');
@rmdir($locked . '/cache');
@unlink($locked . '/keep.txt');
@rmdir($locked);

echo $failures === 0 ? "\nALL PASSED\n" : "\n{$failures} FAILED\n";
exit($failures === 0 ? 0 : 1);
