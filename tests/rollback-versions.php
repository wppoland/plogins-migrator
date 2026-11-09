<?php
/**
 * Rollback picker: only earlier wp.org releases, newest first, never trunk, never
 * a package URL that is not on downloads.wordpress.org.
 *
 *   php tests/rollback-versions.php
 *
 * @package Migrator
 */

declare(strict_types=1);

define('ABSPATH', __DIR__);
require dirname(__DIR__) . '/autoload.php';

use Migrator\Admin\Rollback;

$dl  = static fn (string $v): string => "https://downloads.wordpress.org/plugin/x.{$v}.zip";
$got = Rollback::olderReleases([
    '1.2.0'  => $dl('1.2.0'),
    '1.10.0' => $dl('1.10.0'),
    '1.9.1'  => $dl('1.9.1'),
    'trunk'  => $dl('trunk'),
    '2.0.0'  => $dl('2.0.0'),
    '1.9.0'  => 'https://evil.example/x.zip',
    100      => $dl('100'),
], '2.0.0');

$want = ['1.10.0' => $dl('1.10.0'), '1.9.1' => $dl('1.9.1'), '1.2.0' => $dl('1.2.0')];
if ($got !== $want) {
    fwrite(STDERR, "rollback-versions: FAIL\n" . var_export($got, true) . "\n");
    exit(1);
}
echo "rollback-versions: OK\n";
