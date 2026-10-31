<?php
/**
 * A stored backup re-packed as .tar.gz opens with plain `tar`, no Migrator.
 *
 *   php tests/tar-export.php
 *
 * Writes a .migrator (and a gzip-wrapped copy), streams each through
 * TarExport, then lists and extracts the result with the system tar and
 * compares every byte, including a path over 100 bytes (pax header) and a
 * multi-chunk binary file.
 *
 * @package Migrator
 */

declare(strict_types=1);

define('ABSPATH', __DIR__ . '/');
function wp_json_encode(mixed $data, int $flags = 0, int $depth = 512): string|false
{
    return json_encode($data, $flags, $depth);
}
function esc_html(string $text): string
{
    return htmlspecialchars($text, ENT_QUOTES);
}
function wp_delete_file(string $file): void
{
    @unlink($file);
}

$failures = 0;
function check(string $label, bool $ok): void
{
    global $failures;
    echo ($ok ? '  ok   ' : '  FAIL ') . $label . "\n";
    if (! $ok) {
        $failures++;
    }
}

$src = __DIR__ . '/../src/Engine/Archive/';
foreach (['Entry', 'Writer', 'Reader', 'Compressor'] as $class) {
    require $src . $class . '.php';
}
if (! is_file($src . 'TarExport.php')) {
    check('TarExport exists', false);
    exit(1);
}
require $src . 'TarExport.php';

use Migrator\Engine\Archive\Compressor;
use Migrator\Engine\Archive\Entry;
use Migrator\Engine\Archive\TarExport;
use Migrator\Engine\Archive\Writer;

$tmp = sys_get_temp_dir() . '/migrator-tar-' . uniqid();
mkdir($tmp);

$long  = 'wp-content/uploads/' . str_repeat('very-long-folder-name/', 6) . 'photo.jpg';
$files = [
    'migrator-manifest.json' => '{"format":"migrator"}',
    'database.sql'           => "CREATE TABLE t (id int);\nINSERT INTO t VALUES (1);\n",
    'wp-content/uploads/big.bin' => random_bytes(6 * 1024 * 1024 + 333),
    'wp-content/plugins/a/exactly512.txt' => str_repeat('x', 512),
    $long                    => "\x00\xffbinary\x00",
    'wp-content/empty.txt'   => '',
];

$archive = $tmp . '/site.migrator';
$w       = new Writer($archive);
foreach ($files as $rel => $bytes) {
    if ('migrator-manifest.json' === $rel) {
        $w->addString($rel, $bytes, Entry::TYPE_MANIFEST);
        continue;
    }
    file_put_contents($tmp . '/in.bin', $bytes);
    $w->addFile($rel, $tmp . '/in.bin');
}
$w->finish();
(new Compressor())->compress($archive, $archive . Compressor::EXT);

check('path over 100 bytes in the fixture', strlen($long) > 100);

foreach (['plain' => $archive, 'gzip' => $archive . Compressor::EXT] as $label => $path) {
    $out = $tmp . "/$label.tar.gz";
    $fh  = fopen($out, 'wb');
    TarExport::stream($path, static function (string $b) use ($fh): void {
        fwrite($fh, $b);
    });
    fclose($fh);

    exec('tar -tzf ' . escapeshellarg($out) . ' 2>&1', $list, $rc);
    check("$label: tar lists it (rc=$rc)", 0 === $rc);
    sort($list);
    $want = array_keys($files);
    sort($want);
    check("$label: every entry listed under its full path", $want === $list);

    $dir = $tmp . "/x-$label";
    mkdir($dir);
    exec('tar -xzf ' . escapeshellarg($out) . ' -C ' . escapeshellarg($dir) . ' 2>&1', $o, $rc);
    check("$label: tar extracts it (rc=$rc)", 0 === $rc);
    foreach ($files as $rel => $bytes) {
        check("$label: $rel byte-identical", is_file("$dir/$rel") && file_get_contents("$dir/$rel") === $bytes);
    }
    $list = [];
}

// Not a backup: must throw before a single byte reaches the sink.
file_put_contents($tmp . '/foreign.migrator', 'ENCRYPTEDxxxxxxxx');
$sent = 0;
try {
    TarExport::stream($tmp . '/foreign.migrator', static function (string $b) use (&$sent): void {
        $sent += strlen($b);
    });
    check('foreign file throws', false);
} catch (\RuntimeException $e) {
    check('foreign file throws before any output', 0 === $sent);
}

exec('rm -rf ' . escapeshellarg($tmp));
echo $failures ? "\n$failures failure(s)\n" : "\nAll checks passed.\n";
exit($failures ? 1 : 0);
