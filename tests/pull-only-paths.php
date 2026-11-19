<?php
/**
 * wp migrator pull --only / --exclude:  php tests/pull-only-paths.php
 *
 * A pull with --skip-database used to copy every wp-content file whose size
 * differed, so plugins updated on staging went back to the production
 * versions. --only=uploads must bring new uploads and touch nothing else.
 *
 * @package Migrator
 */

declare(strict_types=1);

namespace Migrator {
    const PLUGIN_DIR = '/tmp/plugins/plogins-migrator/';
}

namespace {
    define('ABSPATH', __DIR__ . '/');

    function untrailingslashit(string $value): string
    {
        return rtrim($value, '/\\');
    }

    function wp_mkdir_p(string $dir): bool
    {
        return is_dir($dir) || mkdir($dir, 0777, true);
    }

    require __DIR__ . '/../src/Support/Workspace.php';
    require __DIR__ . '/../src/Reprint/Mirror.php';

    use Migrator\Reprint\Mirror;

    $failures = 0;
    function ok(string $label, bool $cond): void
    {
        global $failures;
        echo ($cond ? '  ok   ' : '  FAIL ') . $label . "\n";
        $cond || $failures++;
    }

    function put(string $file, string $body): void
    {
        wp_mkdir_p(dirname($file));
        file_put_contents($file, $body);
    }

    function rmtree(string $dir): void
    {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $i) {
            $i->isDir() ? rmdir($i->getPathname()) : unlink($i->getPathname());
        }
        rmdir($dir);
    }

    /** Source tree (prod) and destination tree (staging, plugin updated here). */
    function fixture(): array
    {
        $base = sys_get_temp_dir() . '/migrator-only-' . uniqid();
        $from = $base . '/from';
        $to   = $base . '/to';
        put($from . '/uploads/2026/11/new.jpg', 'new upload');
        put($from . '/uploads-old/legacy.jpg', 'legacy');
        put($from . '/uploads/cache/page.html', 'cache');
        put($from . '/plugins/shop/shop.php', 'old 1.0');
        put($from . '/themes/site/style.css', 'prod theme');
        put($from . '/plugins/only-on-prod/x.php', 'x');
        put($from . '/index.php', '<?php');
        put($to . '/plugins/shop/shop.php', 'new version 2.0');
        put($to . '/themes/site/style.css', 'staging theme, longer');
        put($to . '/plugins/only-on-staging/y.php', 'y');

        return [$base, $from, $to];
    }

    echo "parsing\n";
    ok('roots parsed, trimmed and deduplicated', Mirror::roots(' uploads, /themes/ ,uploads,') === ['uploads', 'themes']);
    ok('empty --only gives no filter', Mirror::roots('') === []);
    $threw = false;
    try {
        Mirror::roots('uploads,wp-admin');
    } catch (InvalidArgumentException $e) {
        $threw = str_contains($e->getMessage(), 'wp-admin');
    }
    ok('unknown root rejected by name', $threw);
    ok('exclude paths normalised', Mirror::paths('/uploads/cache/, plugins/a') === ['uploads/cache', 'plugins/a']);
    $threw = false;
    try {
        Mirror::paths('uploads/../plugins');
    } catch (InvalidArgumentException $e) {
        $threw = true;
    }
    ok('exclude with .. rejected', $threw);

    echo "--only=uploads\n";
    [$base, $from, $to] = fixture();
    $copied = Mirror::copy($from, $to, ['uploads']);
    ok('new upload copied', 'new upload' === @file_get_contents($to . '/uploads/2026/11/new.jpg'));
    ok('plugin updated on the destination kept', 'new version 2.0' === file_get_contents($to . '/plugins/shop/shop.php'));
    ok('theme on the destination kept', 'staging theme, longer' === file_get_contents($to . '/themes/site/style.css'));
    ok('plugin only on the source not created', ! file_exists($to . '/plugins/only-on-prod'));
    ok('sibling folder uploads-old not matched', ! file_exists($to . '/uploads-old'));
    ok('top-level file outside --only not copied', ! file_exists($to . '/index.php'));
    ok('file only on the destination left alone', is_file($to . '/plugins/only-on-staging/y.php'));
    ok('copied count covers uploads only', 2 === $copied);
    rmtree($base);

    echo "--only=uploads --exclude=uploads/cache\n";
    [$base, $from, $to] = fixture();
    Mirror::copy($from, $to, ['uploads'], ['uploads/cache']);
    ok('excluded path not copied', ! file_exists($to . '/uploads/cache'));
    ok('rest of uploads copied', is_file($to . '/uploads/2026/11/new.jpg'));
    rmtree($base);

    echo "--only=plugins keeps Migrator protected\n";
    [$base, $from, $to] = fixture();
    put($from . '/plugins/plogins-migrator/plugin.php', 'source migrator');
    Mirror::copy($from, $to, ['plugins']);
    ok('Migrator itself never copied', ! file_exists($to . '/plugins/plogins-migrator'));
    ok('other plugins copied', 'old 1.0' === file_get_contents($to . '/plugins/shop/shop.php'));
    ok('uploads left out', ! file_exists($to . '/uploads'));
    rmtree($base);

    echo "no filter\n";
    [$base, $from, $to] = fixture();
    Mirror::copy($from, $to);
    ok('everything copied as before', 'old 1.0' === file_get_contents($to . '/plugins/shop/shop.php') && is_file($to . '/index.php') && is_file($to . '/uploads-old/legacy.jpg'));
    rmtree($base);

    echo 0 === $failures ? "\nALL PASSED\n" : "\n{$failures} FAILED\n";
    exit(0 === $failures ? 0 : 1);
}
