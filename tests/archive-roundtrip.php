<?php
/**
 * Standalone roundtrip check for the archive container (no WordPress needed).
 *
 *   php tests/archive-roundtrip.php
 *
 * Writes a mix of in-memory and on-disk entries, reads them back, and asserts
 * the paths, sizes and bytes survive intact, including a large entry that the
 * writer streams in 5 MiB chunks and a resumed extraction.
 *
 * @package Migrator
 */

declare(strict_types=1);

// Minimal WP shims so the engine classes load outside WordPress.
define('ABSPATH', __DIR__ . '/');
if (! function_exists('wp_json_encode')) {
    function wp_json_encode(mixed $data, int $flags = 0, int $depth = 512): string|false
    {
        return json_encode($data, $flags, $depth);
    }
}
if (! function_exists('esc_html')) {
    function esc_html(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES);
    }
}

require __DIR__ . '/../src/Engine/Archive/Entry.php';
require __DIR__ . '/../src/Engine/Archive/Writer.php';
require __DIR__ . '/../src/Engine/Archive/Reader.php';

use Migrator\Engine\Archive\Entry;
use Migrator\Engine\Archive\Reader;
use Migrator\Engine\Archive\Writer;

$failures = 0;
function check(string $label, bool $ok): void
{
    global $failures;
    echo ($ok ? "  ok   " : "  FAIL ") . $label . "\n";
    if (! $ok) {
        $failures++;
    }
}

$tmp     = sys_get_temp_dir() . '/migrator-test-' . uniqid();
mkdir($tmp);
$archive = $tmp . '/test.migrator';

// A large file (~12 MiB) to exercise multi-chunk streaming.
$bigSource = $tmp . '/big.bin';
$big       = random_bytes(12 * 1024 * 1024 + 777);
file_put_contents($bigSource, $big);

// A small file with awkward bytes (nulls, UTF-8, the 4-byte end marker shape).
$smallSource = $tmp . '/small.txt';
$small       = "héllo\x00\x00\x00\x00world, ąęś\n";
file_put_contents($smallSource, $small);

$manifestJson = '{"format":"migrator","siteUrl":"https://old.example"}';

// ---- write ----
$w = new Writer($archive);
$w->addString('migrator-manifest.json', $manifestJson, Entry::TYPE_MANIFEST);
$w->addFile('wp-content/uploads/small.txt', $smallSource);
$w->addFile('wp-content/uploads/big.bin', $bigSource);
$w->finish();

check('archive file exists and is non-trivial', is_file($archive) && filesize($archive) > 12 * 1024 * 1024);

// ---- read ----
$r        = new Reader($archive);
$entries  = [];
$contents = [];
$offsets  = [];
while (($entry = $r->nextEntry()) !== null) {
    $entries[$entry->path] = $entry;
    $offsets[$entry->path] = ['off' => $r->contentOffset(), 'size' => $entry->size];
    $contents[$entry->path] = $r->readContents();
}
$r->close();

check('three entries read back', count($entries) === 3);
check('manifest entry typed as manifest', isset($entries['migrator-manifest.json']) && $entries['migrator-manifest.json']->isManifest());
check('manifest content intact', ($contents['migrator-manifest.json'] ?? '') === $manifestJson);
check('small file bytes intact (nulls + utf8)', ($contents['wp-content/uploads/small.txt'] ?? '') === $small);
check('small file size correct', ($entries['wp-content/uploads/small.txt']->size ?? -1) === strlen($small));
check('big file bytes intact', ($contents['wp-content/uploads/big.bin'] ?? '') === $big);
check('big file size correct', ($entries['wp-content/uploads/big.bin']->size ?? -1) === strlen($big));

// ---- resumed extraction of the big entry ----
$r2  = new Reader($archive);
while (($e = $r2->nextEntry()) !== null) {
    if ('wp-content/uploads/big.bin' === $e->path) {
        break;
    }
    $r2->skip();
}
$start = $offsets['wp-content/uploads/big.bin']['off'];
$size  = $offsets['wp-content/uploads/big.bin']['size'];
$half  = intdiv($size, 2);
// First slice: read half from the start.
$r2->resumeAt($start, $size);
$part1 = '';
$got   = 0;
$r2->streamTo(function (string $c) use (&$part1) {
    $part1 .= $c;
});
// Re-open and resume from the recorded offset for the remaining bytes.
$r3 = new Reader($archive);
$r3->resumeAt($start + $half, $size - $half);
$part2 = '';
$r3->streamTo(function (string $c) use (&$part2) {
    $part2 .= $c;
});
$r3->close();
$resumed = substr($part1, 0, $half) . $part2;
check('resumed extraction reconstructs big file', $resumed === $big);

// ---- checksum detects corruption ----
$corruptPath = $tmp . '/corrupt.migrator';
copy($archive, $corruptPath);
// Flip one byte inside the small file's content region.
$smallOffset = $offsets['wp-content/uploads/small.txt']['off'];
$fh = fopen($corruptPath, 'r+b');
fseek($fh, $smallOffset + 2);
fwrite($fh, '!'); // overwrite a content byte
fclose($fh);

$detected = false;
try {
    $rc = new Reader($corruptPath);
    while (($e = $rc->nextEntry()) !== null) {
        $rc->readContents(); // verifies CRC on full read
    }
    $rc->close();
} catch (\RuntimeException $ex) {
    $detected = str_contains($ex->getMessage(), 'checksum mismatch');
}
check('corrupted entry is caught by checksum verification', $detected);

// ---- a truncated archive is not mistaken for a shorter one ----
// A run killed mid-write leaves the file with no end marker. Read to the end and
// the reader must say so, rather than reporting the entries it did manage to
// reach as the whole backup.
foreach ([64, 4096, filesize($archive) - 4] as $cut) {
    $cutPath = $tmp . '/cut-' . $cut . '.migrator';
    copy($archive, $cutPath);
    $fh = fopen($cutPath, 'r+b');
    ftruncate($fh, (int) $cut);
    fclose($fh);

    $caught = false;
    try {
        $rt = new Reader($cutPath);
        while (($e = $rt->nextEntry()) !== null) {
            $rt->skip();
        }
        $rt->close();
    } catch (\RuntimeException $ex) {
        $caught = str_contains($ex->getMessage(), 'ends part way through');
    }
    check("an archive truncated at {$cut} bytes is reported, not read as complete", $caught);
}

// ---- a clean archive passes full verification ----
$clean = true;
try {
    $rv = new Reader($archive);
    while (($e = $rv->nextEntry()) !== null) {
        $rv->readContents();
    }
    $rv->close();
} catch (\RuntimeException $ex) {
    $clean = false;
}
check('clean archive passes full checksum verification', $clean);

// cleanup
array_map('unlink', glob($tmp . '/*') ?: []);
@rmdir($tmp);

echo $failures === 0 ? "\nALL PASSED\n" : "\n{$failures} FAILED\n";
exit($failures === 0 ? 0 : 1);
