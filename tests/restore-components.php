<?php
/**
 * Restore chosen parts of a backup:  php tests/restore-components.php
 *
 * A restore used to bring the database back every time and the files all or
 * nothing, so rolling back a plugin also rolled back the day's orders. Builds
 * an archive with a database entry and files in plugins, themes, uploads and
 * languages, then restores subsets into a temp wp-content. The database stub
 * throws on any use, so a run that touches the database fails loudly.
 *
 * @package Migrator
 */

declare(strict_types=1);

define('ABSPATH', __DIR__ . '/');
$content = sys_get_temp_dir() . '/migrator-parts-' . getmypid();
define('WP_CONTENT_DIR', $content);

function __($s, $d = '') { return $s; }
function esc_html($s) { return $s; }
function wp_json_encode($d, $f = 0) { return json_encode($d, $f); }
function untrailingslashit($s) { return rtrim($s, '/\\'); }
function wp_mkdir_p($d) { return is_dir($d) || mkdir($d, 0777, true); }
function wp_delete_file($f) { @unlink($f); }
function get_option($k, $d = false) { return 'https://example.test'; }
function is_multisite() { return false; }
function apply_filters($h, $v) { return $v; }
function wp_cache_flush() { return true; }

// Any database work on a files-only restore is the bug this test is for.
class wpdb
{
    public string $prefix = 'wp_';

    public function __call($name, $args)
    {
        throw new \LogicException('database touched: ' . $name);
    }
}

require __DIR__ . '/../autoload.php';

use Migrator\Engine\Archive\Entry;
use Migrator\Engine\Archive\Manifest;
use Migrator\Engine\Archive\Writer;
use Migrator\Engine\Export\Exporter;
use Migrator\Engine\Import\Importer;
use Migrator\Support\Workspace;

$fail = 0;
function ok(string $label, bool $cond): void
{
    global $fail;
    echo ($cond ? '  ok   ' : '  FAIL ') . $label . "\n";
    $cond || $fail++;
}

$files = [
    'plugins/shop/shop.php'       => 'plugin',
    'themes/shop/style.css'       => 'theme',
    'uploads/2026/10/photo.jpg'   => 'upload',
    'languages/pl_PL.mo'          => 'other',
];

$archive = sys_get_temp_dir() . '/migrator-parts-' . getmypid() . '.migrator';
$writer  = new Writer($archive);
$writer->addString(Manifest::NAME, (string) json_encode([
    'format'        => Manifest::FORMAT,
    'formatVersion' => Manifest::FORMAT_VERSION,
    'tablePrefix'   => 'wp_',
    'multisite'     => false,
]), Entry::TYPE_MANIFEST);
$writer->addString(Exporter::DB_ENTRY, "CREATE TABLE wp_x (id int);\n");
$writer->addString(Exporter::ROUTINES_ENTRY, '[]');
foreach ($files as $rel => $body) {
    $writer->addString('wp-content/' . $rel, $body);
}
$writer->finish();

$present = static function () use ($content, $files): array {
    $out = [];
    foreach (array_keys($files) as $rel) {
        if (is_file($content . '/' . $rel)) {
            $out[] = $rel;
        }
    }

    return $out;
};
$reset = static function () use ($content): void {
    exec('rm -rf ' . escapeshellarg($content));
    mkdir($content, 0777, true);
};

ok('entries map to their component', 'plugins' === Importer::componentOf('wp-content/plugins/a/a.php')
    && 'themes' === Importer::componentOf('wp-content/themes/t/style.css')
    && 'uploads' === Importer::componentOf('wp-content/uploads/x.jpg')
    && 'other' === Importer::componentOf('wp-content/languages/pl_PL.mo')
    && 'other' === Importer::componentOf('wp-content/plugins-old/a.php'));

ok('true keeps every part, false the database only', Importer::COMPONENTS === Importer::components(true)
    && ['database'] === Importer::components(false));
ok('unknown names are dropped', ['themes'] === Importer::components(['themes', 'evil', '']));

$importer = new Importer(new Workspace(), new wpdb());

$reset();
try {
    $r = $importer->import($archive, ['plugins', 'themes', 'uploads', 'other']);
    ok('files without the database leave the database alone', 0 === $r['statements']);
    ok('files without the database restore every file', 4 === $r['files'] && 4 === count($present()));
} catch (\Throwable $e) {
    ok('files without the database: ' . $e->getMessage(), false);
}

$reset();
try {
    $r = $importer->import($archive, ['plugins']);
    ok('plugins only restores the plugin and nothing else', ['plugins/shop/shop.php'] === $present() && 1 === $r['files']);
} catch (\Throwable $e) {
    ok('plugins only: ' . $e->getMessage(), false);
}

$reset();
try {
    $importer->import($archive, ['uploads', 'themes']);
    ok('uploads and themes restore just those two', ['themes/shop/style.css', 'uploads/2026/10/photo.jpg'] === $present());
} catch (\Throwable $e) {
    ok('uploads and themes: ' . $e->getMessage(), false);
}

try {
    $importer->import($archive, []);
    ok('an empty choice is refused', false);
} catch (\Throwable $e) {
    ok('an empty choice is refused', str_contains($e->getMessage(), 'nothing was chosen'));
}

exec('rm -rf ' . escapeshellarg($content));
@unlink($archive);

echo $fail ? "\n$fail check(s) failed\n" : "\nall checks passed\n";
exit($fail ? 1 : 0);
