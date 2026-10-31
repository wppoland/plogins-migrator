<?php
/**
 * Exclude files by name pattern and by size:  php tests/file-exclude-patterns.php
 *
 * The folder tree only prunes whole paths, so *.log files and 500 MB videos
 * scattered through uploads went into every archive.
 *
 * @package Migrator
 */

declare(strict_types=1);

define('ABSPATH', __DIR__ . '/');

require __DIR__ . '/../src/Engine/Files/FileScanner.php';
require __DIR__ . '/../src/Engine/Export/ExportOptions.php';

use Migrator\Engine\Export\ExportOptions;
use Migrator\Engine\Files\FileScanner;

$failures = 0;
function ok(string $label, bool $cond): void
{
    global $failures;
    echo ($cond ? '  ok   ' : '  FAIL ') . $label . "\n";
    $cond || $failures++;
}

$root = sys_get_temp_dir() . '/migrator-pattern-' . uniqid();
mkdir($root . '/uploads/2026', 0777, true);
file_put_contents($root . '/uploads/2026/photo.jpg', 'small');
file_put_contents($root . '/uploads/2026/clip.mp4', str_repeat('v', 2 * 1024 * 1024 + 1));
file_put_contents($root . '/uploads/error.LOG', 'log');
file_put_contents($root . '/uploads/old-backup.zip', 'zip');
file_put_contents($root . '/style.css', 'css');

$options = ExportOptions::fromArray(['exclude_patterns' => "*.log, *.zip\nuploads/*.jpg", 'max_file_mb' => '2']);

ok('patterns parsed from a comma/newline string', method_exists($options, 'fileExcludePatterns') && $options->fileExcludePatterns() === ['*.log', '*.zip']);
ok('size limit converted to bytes', method_exists($options, 'maxFileBytes') && $options->maxFileBytes() === 2 * 1024 * 1024);

$scanner = new FileScanner(
    [],
    [],
    method_exists($options, 'fileExcludePatterns') ? $options->fileExcludePatterns() : ['*.log', '*.zip'],
    method_exists($options, 'maxFileBytes') ? $options->maxFileBytes() : 2 * 1024 * 1024,
);
$found = [];
foreach ($scanner->scan($root) as $f) {
    $found[] = $f['rel'];
}
sort($found);

ok('*.log left out, case-insensitively', ! in_array('uploads/error.LOG', $found, true));
ok('*.zip left out', ! in_array('uploads/old-backup.zip', $found, true));
ok('file over the size limit left out', ! in_array('uploads/2026/clip.mp4', $found, true));
ok('everything else kept', $found === ['style.css', 'uploads/2026/photo.jpg']);

$plain = [];
foreach ((new FileScanner())->scan($root) as $f) {
    $plain[] = $f['rel'];
}
ok('no patterns and no limit keeps every file', count($plain) === 5);

$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
foreach ($it as $i) {
    $i->isDir() ? @rmdir($i->getPathname()) : @unlink($i->getPathname());
}
@rmdir($root);

echo 0 === $failures ? "\nALL PASSED\n" : "\n{$failures} FAILED\n";
exit(0 === $failures ? 0 : 1);
