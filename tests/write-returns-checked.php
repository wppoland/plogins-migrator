<?php
/**
 * A discarded write return is how data loss becomes silent.
 *
 * Importer::importDatabase() streamed the archive to a temp SQL file with a
 * bare fwrite($handle, $chunk). On a disk that fills mid-stream fwrite writes
 * what fits and RETURNS the short count; nothing threw, the truncated file
 * executed cleanly up to its last complete statement, and the merchant was
 * told the restore had succeeded while the tail of their database was never
 * written. The same shape anywhere else in the engine would fail the same way.
 *
 * Framework-free on purpose: the release script runs every tests/*-check.php
 * by glob, with no WordPress and no bootstrap.
 *
 *   php tests/write-returns-checked.php
 */

declare(strict_types=1);

$root = dirname(__DIR__);
$failures = [];
$checked = 0;

/** Functions whose return value reports a PARTIAL success, not just failure. */
$writers = ['fwrite', 'fputs', 'file_put_contents', 'gzwrite'];

$rii = new RecursiveIteratorIterator(
    new RecursiveCallbackFilterIterator(
        new RecursiveDirectoryIterator($root . '/src', FilesystemIterator::SKIP_DOTS),
        static fn ($f) => $f->isDir() || str_ends_with($f->getFilename(), '.php')
    )
);

foreach ($rii as $file) {
    if ($file->isDir()) {
        continue;
    }
    $lines = file($file->getPathname(), FILE_IGNORE_NEW_LINES);
    foreach ($lines as $i => $line) {
        foreach ($writers as $fn) {
            if (! preg_match('/(^|[^A-Za-z0-9_$>])' . $fn . '\s*\(/', $line)) {
                continue;
            }
            $trimmed = ltrim($line);
            // A mention inside a comment is not a call. Without this the check
            // fails on its own explanation of the defect it exists to find.
            if (str_starts_with($trimmed, '*') || str_starts_with($trimmed, '//')
                || str_starts_with($trimmed, '/*') || str_starts_with($trimmed, '#')) {
                continue;
            }
            $checked++;
            // Used if the call is assigned, returned, compared, or wrapped in a
            // condition. Bare "fwrite(...)" as a whole statement is the defect.
            $used = (bool) preg_match(
                '/(=\s*|return\s+|if\s*\(|while\s*\(|\)\s*(===|!==|==|!=|<|>)|assert\s*\(|throw\b)/',
                $trimmed
            );
            // An explicit, commented decision to ignore it is allowed; the point
            // is that somebody decided, not that the return is always used.
            $prev = $i > 0 ? ltrim($lines[$i - 1]) : '';
            $excused = str_starts_with($prev, '//') && stripos($prev, 'ignore') !== false;
            if (! $used && ! $excused) {
                $failures[] = sprintf(
                    '%s:%d  %s() return is discarded: %s',
                    str_replace($root . '/', '', $file->getPathname()),
                    $i + 1,
                    $fn,
                    trim($line)
                );
            }
        }
    }
}

if ($failures !== []) {
    fwrite(STDERR, "write-returns-checked: FAIL\n\n");
    foreach ($failures as $f) {
        fwrite(STDERR, "  $f\n");
    }
    fwrite(
        STDERR,
        "\nA short write is not an error, it is a PARTIAL success. Check the return,\n"
        . "or put a // comment above saying why it is safe to ignore here.\n"
    );
    exit(1);
}

printf("write-returns-checked: OK (%d write call(s) in src/, all inspected)\n", $checked);
