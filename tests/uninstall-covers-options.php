<?php
/**
 * Every option the plugin writes is deleted on uninstall:
 *   php tests/uninstall-covers-options.php
 *
 * uninstall.php listed two options nothing ever wrote and missed the six that
 * were written (schedule, off-site settings with an FTP password, last backup,
 * export state), so they stayed in wp_options after the plugin was deleted.
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

$src = '';
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/src', FilesystemIterator::SKIP_DOTS)) as $file) {
    if (str_ends_with($file->getFilename(), '.php')) {
        $src .= (string) file_get_contents($file->getPathname());
    }
}

// Option keys are always named by a class constant here; collect the constants
// whose value is a migrator_ option name, then keep those that are written.
preg_match_all("/const\\s+(\\w+)\\s*=\\s*'(migrator_[a-z_]+)'/", $src, $m, PREG_SET_ORDER);
$written = [];
foreach ($m as [, $const, $name]) {
    if (preg_match('/(update_option|add_option)\(\s*(self|static|\w+)::' . $const . '\b/', $src)
        || preg_match('/get_option\(\s*self::' . $const . '\b/', $src)) {
        $written[$name] = true;
    }
}

$uninstall = (string) file_get_contents($root . '/uninstall.php');
$ok('found the options the plugin uses', count($written) >= 6);
foreach (array_keys($written) as $name) {
    $ok("{$name} is deleted on uninstall", str_contains($uninstall, "'{$name}'"));
}
foreach (['migrator_run_scheduled_backup', 'migrator_pro_run_scheduled_backup'] as $hook) {
    $ok("cron hook {$hook} is cleared on uninstall", str_contains($uninstall, "wp_clear_scheduled_hook('{$hook}')"));
}

echo 0 === $failures ? "uninstall-covers-options: OK\n" : "uninstall-covers-options: {$failures} failure(s)\n";
exit($failures > 0 ? 1 : 0);
