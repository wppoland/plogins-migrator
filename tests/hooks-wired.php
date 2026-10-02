<?php
/**
 * Every class with hooks to register must actually get them registered.
 *
 * Plugin::boot() resolves the services in config/hooks.php and calls
 * registerHooks() only on those that implement HasHooks. Scheduler had the
 * method and the config entry but not the interface, so from 1.3.0 the free
 * scheduling, retention, FTP and folder copies never ran and the Scheduled
 * Backups page could not be reached. Nothing errored: the instanceof check
 * just skipped it.
 *
 *   php tests/hooks-wired.php
 *
 * @package Migrator
 */

declare(strict_types=1);

$root     = dirname(__DIR__);
$failures = 0;
$ok       = static function (string $label, bool $cond) use (&$failures): void {
    echo ($cond ? '  ok   ' : '  FAIL ') . $label . "\n";
    $cond || $failures++;
};

// Classes named in config/hooks.php, read as source so no WordPress is needed.
$config = (string) file_get_contents($root . '/config/hooks.php');
preg_match_all('/\\\\Migrator\\\\([A-Za-z\\\\]+)::class/', $config, $m);
$booted = $m[1];
$ok('config/hooks.php lists services', [] !== $booted);

$src = '';
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/src', FilesystemIterator::SKIP_DOTS)) as $file) {
    if (str_ends_with($file->getFilename(), '.php')) {
        $src .= (string) file_get_contents($file->getPathname());
    }
}

foreach ($booted as $class) {
    $path = $root . '/src/' . str_replace('\\', '/', $class) . '.php';
    $code = is_file($path) ? (string) file_get_contents($path) : '';
    $ok("{$class} implements HasHooks, so boot() calls it", (bool) preg_match('/class\s+\w+\s+implements\s+[^{]*\bHasHooks\b/', $code));
}

// Any other class with a registerHooks() must be called explicitly somewhere.
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/src', FilesystemIterator::SKIP_DOTS)) as $file) {
    $code = (string) file_get_contents($file->getPathname());
    if (! str_contains($code, 'function registerHooks') || ! preg_match('/class\s+(\w+)/', $code, $c)) {
        continue;
    }
    $rel   = substr($file->getPathname(), strlen($root . '/src/'), -4);
    $class = str_replace('/', '\\', $rel);
    if (in_array($class, $booted, true) || 'Contract\\HasHooks' === $class) {
        continue;
    }
    $ok("{$class}::registerHooks() is called by something", (bool) preg_match('/' . $c[1] . '\(\)\)?->registerHooks\(\)|proUpsell\(\)->registerHooks\(\)/i', $src));
}

echo 0 === $failures ? "hooks-wired: OK\n" : "hooks-wired: {$failures} failure(s)\n";
exit($failures > 0 ? 1 : 0);
