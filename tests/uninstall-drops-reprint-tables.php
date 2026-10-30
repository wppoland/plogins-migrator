<?php
/**
 * Uninstall drops the tables the bundled Reprint code creates, and keeps the
 * data another installed plugin still uses:
 *   php tests/uninstall-drops-reprint-tables.php [path/to/uninstall.php]
 *
 * Until 1.6.5 uninstall.php dropped no table at all: an interrupted pull left
 * its cursor table, a push left its receipts and staging tables, a network
 * pull left a reprint_users table per site. It also deleted the three legacy
 * schedule options the paid add-on still reads while that add-on was on disk.
 *
 * Runs uninstall.php against a fake $wpdb three times: alone, with a
 * reprint-* plugin folder (server tables must stay), and with the paid add-on
 * installed (legacy options must stay).
 *
 * @package Migrator
 */

declare(strict_types=1);

$uninstall = $argv[1] ?? dirname(__DIR__) . '/uninstall.php';
$tmp       = sys_get_temp_dir() . '/migrator-uninstall-' . getmypid();

define('ABSPATH', $tmp . '/');
define('WP_UNINSTALL_PLUGIN', 'plogins-migrator/plogins-migrator.php');
define('WP_CONTENT_DIR', $tmp . '/wp-content');
define('WP_PLUGIN_DIR', $tmp . '/wp-content/plugins');

$GLOBALS['paid']    = false;
$GLOBALS['deleted'] = [];

function get_plugins(): array
{
    $plugins = ['plogins-migrator/plogins-migrator.php' => ['TextDomain' => 'plogins-migrator']];
    if ($GLOBALS['paid']) {
        $plugins['renamed/plogins-migrator-pro.php'] = ['TextDomain' => 'plogins-migrator-pro'];
    }

    return $plugins;
}
function is_multisite(): bool
{
    return false;
}
function delete_option($name): bool
{
    $GLOBALS['deleted'][] = $name;

    return true;
}
function delete_site_option($name): bool
{
    return true;
}
function delete_metadata(...$args): bool
{
    return true;
}
function wp_clear_scheduled_hook($hook): int
{
    return 0;
}
function apply_filters($hook, $value)
{
    return $value;
}

final class FakeWpdb
{
    public string $prefix = 'wp_';
    /** @var list<string> */
    public array $tables = [
        'wp_posts',
        'wp_reprint_users',
        '__reprint_db_change_receipts',
        '__reprint_db_0123456789abcdef0123456789abcdef_state',
        '__reprint_db_pull_progress_49acb118-a97a-45c7-814d-8e670db7f6b4',
        '__reprint_db_pull_progress_spatial',
    ];
    /** @var list<string> */
    public array $dropped = [];

    public function esc_like(string $text): string
    {
        return addcslashes($text, '_%\\');
    }

    public function prepare(string $sql, ...$args): string
    {
        $i = 0;

        return (string) preg_replace_callback('/%[is]/', static function (array $m) use (&$i, $args): string {
            $v = (string) $args[$i++];

            return $m[0] === '%i' ? "`{$v}`" : "'" . $v . "'";
        }, $sql);
    }

    /** @return list<string> */
    public function get_col(string $sql): array
    {
        if (! preg_match("/^SHOW TABLES LIKE '(.*)'$/", $sql, $m)) {
            return [];
        }
        $re = '';
        foreach (preg_split('/(\\\\.|%|_)/', $m[1], -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) as $part) {
            $re .= match (true) {
                $part === '%' => '.*',
                $part === '_' => '.',
                $part[0] === '\\' => preg_quote($part[1], '/'),
                default => preg_quote($part, '/'),
            };
        }

        return array_values(preg_grep('/^' . $re . '$/', $this->tables));
    }

    public function query(string $sql): int
    {
        if (preg_match('/^DROP TABLE IF EXISTS `(.+)`$/', $sql, $m)) {
            $this->dropped[] = $m[1];
        }

        return 0;
    }
}

$failures = 0;
$ok       = static function (string $label, bool $cond) use (&$failures): void {
    echo ($cond ? '  ok   ' : '  FAIL ') . $label . "\n";
    $cond || $failures++;
};

$run = static function () use ($uninstall): array {
    $GLOBALS['wpdb']    = new FakeWpdb();
    $GLOBALS['deleted'] = [];
    (static function () use ($uninstall): void {
        include $uninstall;
    })();

    return [$GLOBALS['wpdb']->dropped, $GLOBALS['deleted']];
};

$server = [
    'wp_reprint_users',
    '__reprint_db_change_receipts',
    '__reprint_db_0123456789abcdef0123456789abcdef_state',
];
$pull   = ['__reprint_db_pull_progress_49acb118-a97a-45c7-814d-8e670db7f6b4', '__reprint_db_pull_progress_spatial'];
$legacy = ['migrator_pro_schedule', 'migrator_pro_offsite', 'migrator_pro_last_backup'];

mkdir(WP_PLUGIN_DIR, 0777, true);

echo "Migrator alone:\n";
[$dropped, $deleted] = $run();
foreach (array_merge($server, $pull) as $table) {
    $ok("drops {$table}", in_array($table, $dropped, true));
}
$ok('leaves wp_posts alone', ! in_array('wp_posts', $dropped, true));
$ok('deletes the legacy options', [] === array_diff($legacy, $deleted));

echo "With a standalone Reprint Server on disk:\n";
mkdir(WP_PLUGIN_DIR . '/reprint-server-wp');
[$dropped] = $run();
foreach ($server as $table) {
    $ok("keeps {$table}", ! in_array($table, $dropped, true));
}
foreach ($pull as $table) {
    $ok("still drops {$table}", in_array($table, $dropped, true));
}
rmdir(WP_PLUGIN_DIR . '/reprint-server-wp');

echo "With the paid add-on on disk:\n";
$GLOBALS['paid'] = true;
[, $deleted] = $run();
$ok('keeps the legacy options', [] === array_intersect($legacy, $deleted));
$ok('still deletes its own options', in_array('migrator_schedule', $deleted, true));

rmdir(WP_PLUGIN_DIR);
rmdir(WP_CONTENT_DIR);
rmdir($tmp);

echo 0 === $failures ? "uninstall-drops-reprint-tables: OK\n" : "uninstall-drops-reprint-tables: {$failures} failure(s)\n";
exit($failures > 0 ? 1 : 0);
