<?php
/**
 * DISALLOW_FILE_MODS: wp-admin may restore the database and uploads but not
 * plugins, themes or other code files.  php tests/file-mods-lock.php
 *
 * @package Migrator
 */

declare(strict_types=1);

define('ABSPATH', __DIR__);
define('DISALLOW_FILE_MODS', true);
function __($s, $d = '') { return $s; }
class wpdb {}
require dirname(__DIR__) . '/autoload.php';

use Migrator\Engine\Import\Importer;
use Migrator\Support\Workspace;

$fail = 0;
$ok   = static function (string $label, bool $cond) use (&$fail): void {
    echo ($cond ? '  ok   ' : '  FAIL ') . $label . "\n";
    $fail += $cond ? 0 : 1;
};

$ok('plugins, themes and other are locked', Importer::lockedComponents() === ['plugins', 'themes', 'other']);

$importer = (new ReflectionClass(Importer::class))->newInstanceWithoutConstructor();
$thrown   = '';
try {
    $importer->import('/nonexistent.migrator', ['database', 'plugins']);
} catch (RuntimeException $e) {
    $thrown = $e->getMessage();
}
$ok('a restore that includes plugins is refused before any work', str_contains($thrown, 'DISALLOW_FILE_MODS') && str_contains($thrown, 'plugins'));

$thrown = '';
try {
    @$importer->import('/nonexistent.migrator', ['database', 'uploads']);
} catch (Throwable $e) {
    $thrown = $e->getMessage();
}
$ok('database and uploads are not refused by the lock', ! str_contains($thrown, 'DISALLOW_FILE_MODS'));

echo $fail ? "file-mods-lock: FAIL\n" : "file-mods-lock: OK\n";
exit($fail ? 1 : 0);
