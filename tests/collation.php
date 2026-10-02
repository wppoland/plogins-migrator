<?php
/**
 * Standalone tests for Collation (no WordPress):  php tests/collation.php
 *
 * A dump from MySQL 8 names utf8mb4_0900_ai_ci and one from MariaDB 11 names
 * utf8mb4_uca1400_ai_ci. Restoring either onto a server without that
 * collation failed at the first CREATE TABLE.
 *
 * @package Migrator
 */

declare(strict_types=1);

define('ABSPATH', __DIR__);
require __DIR__ . '/../src/Engine/Db/Collation.php';

use Migrator\Engine\Db\Collation;

$failures = 0;
$ok       = static function (string $label, bool $cond) use (&$failures): void {
    echo ($cond ? '  ok   ' : '  FAIL ') . $label . "\n";
    $cond || $failures++;
};

$mysql57 = new Collation(['utf8mb4_general_ci', 'utf8mb4_unicode_ci', 'utf8mb4_unicode_520_ci', 'utf8_general_ci', 'utf8_unicode_ci', 'latin1_swedish_ci']);
$maria   = new Collation(['utf8mb4_general_ci', 'utf8mb4_unicode_ci', 'utf8mb3_general_ci', 'utf8mb3_unicode_ci', 'utf8mb4_uca1400_ai_ci']);

$create = 'CREATE TABLE `wp_posts` (`a` text COLLATE utf8mb4_0900_ai_ci) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci';
$ok('MySQL 8 collation becomes unicode_520 on 5.7', ! str_contains($mysql57->normalise($create), '0900') && str_contains($mysql57->normalise($create), 'utf8mb4_unicode_520_ci'));
$ok('MariaDB 11 collation becomes unicode_520 on 5.7', str_contains($mysql57->normalise('CREATE TABLE t (a text) COLLATE=utf8mb4_uca1400_ai_ci'), 'utf8mb4_unicode_520_ci'));
$ok('falls back to unicode_ci where 520 is missing', str_contains($maria->normalise('CREATE TABLE t (a text) COLLATE=utf8mb4_0900_ai_ci'), 'utf8mb4_unicode_ci'));
$ok('a supported collation is left alone', 'CREATE TABLE t (a text) COLLATE=utf8mb4_uca1400_ai_ci' === $maria->normalise('CREATE TABLE t (a text) COLLATE=utf8mb4_uca1400_ai_ci'));
$ok('utf8mb3_unicode_ci is known to 5.7 under its utf8 name', 'CREATE TABLE t (a text) COLLATE=utf8mb3_unicode_ci' === $mysql57->normalise('CREATE TABLE t (a text) COLLATE=utf8mb3_unicode_ci'));
$ok('unknown utf8mb3 variant falls back', str_contains($mysql57->normalise('CREATE TABLE t (a text) COLLATE=utf8mb3_0900_ai_ci'), 'utf8_unicode_ci'));
$ok('row data is never touched', "INSERT INTO t VALUES ('utf8mb4_0900_ai_ci')" === $mysql57->normalise("INSERT INTO t VALUES ('utf8mb4_0900_ai_ci')"));

echo 0 === $failures ? "collation: OK\n" : "collation: {$failures} failure(s)\n";
exit($failures > 0 ? 1 : 0);
