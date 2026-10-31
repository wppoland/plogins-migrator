<?php
/**
 * Sub-daily intervals and a database-only schedule:  php tests/database-schedule.php
 *
 * The schedule offered only daily or weekly, for one combined job, so a store
 * could lose a day of orders. Checks the new intervals survive validation and
 * reach cron, that the database job is scheduled on its own hook, and that a
 * real database-only run writes no files and never prunes the full backups.
 * Runs against stand-ins for the database and the few WordPress functions used.
 *
 * @package Migrator
 */

declare(strict_types=1);

namespace Migrator\Engine\Db {
    // Stand-in dumper: one table, a few bytes of SQL.
    final class Dumper
    {
        public function __construct(mixed $db = null)
        {
        }

        /** @return list<string> */
        public function tables(): array { return ['wp_options']; }
        public function prefix(): string { return 'wp_'; }
        /** @return array<int, mixed> */
        public function routines(): array { return []; }
        /** @param resource $handle */
        public function dumpAll(array $tables, $handle, array $where = [], array $skip = []): void
        {
            fwrite($handle, "INSERT INTO wp_options VALUES (1);\n");
        }
    }
}

namespace {
    define('ABSPATH', __DIR__ . '/');
    define('Migrator\\VERSION', 'test');
    define('MINUTE_IN_SECONDS', 60);
    define('HOUR_IN_SECONDS', 3600);
    define('DAY_IN_SECONDS', 86400);
    define('WEEK_IN_SECONDS', 604800);
    $content = sys_get_temp_dir() . '/migrator-dbsched-' . getmypid();
    define('WP_CONTENT_DIR', $content);
    mkdir($content . '/uploads', 0777, true);
    file_put_contents($content . '/uploads/photo.jpg', 'pixels');

    $GLOBALS['opts']   = [];
    $GLOBALS['events'] = [];
    function get_option($k, $d = false) { return $GLOBALS['opts'][$k] ?? ('home' === $k || 'siteurl' === $k ? 'https://shop.test' : $d); }
    function update_option($k, $v, $a = null) { $GLOBALS['opts'][$k] = $v; $GLOBALS['writes'][] = [$k, $v]; return true; }
    function get_bloginfo($k) { return '6.9'; }
    function is_multisite() { return false; }
    function wp_get_upload_dir() { return ['basedir' => WP_CONTENT_DIR . '/uploads']; }
    function is_email($e) { return (bool) filter_var($e, FILTER_VALIDATE_EMAIL); }
    function __($s, $d = '') { return $s; }
    function esc_html($s) { return $s; }
    function wp_json_encode($d, $f = 0) { return json_encode($d, $f); }
    function untrailingslashit($s) { return rtrim($s, '/\\'); }
    function wp_mkdir_p($d) { return is_dir($d) || mkdir($d, 0777, true); }
    function wp_delete_file($f) { @unlink($f); }
    function wp_parse_url($u, $c = -1) { return parse_url($u, $c); }
    function wp_generate_password($n = 12, $s = true) { return substr(bin2hex(random_bytes(8)), 0, $n); }
    function size_format($b) { return $b . ' B'; }
    function apply_filters($h, $v) { return $v; }
    function do_action() {}
    function wp_clear_scheduled_hook($h) { unset($GLOBALS['events'][$h]); }
    function wp_schedule_event($t, $r, $h) { $GLOBALS['events'][$h] = $r; return true; }

    require __DIR__ . '/../autoload.php';

    $fail = 0;
    function ok(string $label, bool $cond): void
    {
        global $fail;
        echo ($cond ? '  ok   ' : '  FAIL ') . $label . "\n";
        $cond || $fail++;
    }

    use Migrator\Backup\BackupRunner;
    use Migrator\Backup\Schedule;
    use Migrator\Backup\Scheduler;
    use Migrator\Engine\Db\Dumper;
    use Migrator\Engine\Export\ExportOptions;
    use Migrator\Engine\Export\Exporter;
    use Migrator\Support\Workspace;

    // 1. Sub-daily intervals survive validation and reach cron.
    ok('every 4 hours is kept, not reset to daily', 'every4hours' === Schedule::fromArray(['frequency' => 'every4hours'])->frequency);
    ok('an unknown interval still falls back to daily', 'daily' === Schedule::fromArray(['frequency' => 'hourly'])->frequency);
    $rec = Schedule::registerRecurrences([]);
    ok('2, 4 and 12 hour recurrences are registered', 7200 === ($rec['migrator_every2hours']['interval'] ?? 0)
        && 14400 === ($rec['migrator_every4hours']['interval'] ?? 0)
        && 43200 === ($rec['migrator_every12hours']['interval'] ?? 0));

    // 2. The database job has its own settings and its own cron event.
    $schedule = Schedule::fromArray([
        'enabled'      => true,
        'frequency'    => 'weekly',
        'retention'    => 2,
        'db_enabled'   => true,
        'db_frequency' => 'every2hours',
        'db_retention' => 3,
    ]);
    $schedule->save();
    $loaded = Schedule::load();
    ok('database job settings are saved', $loaded->dbEnabled && 'every2hours' === $loaded->dbFrequency && 3 === $loaded->dbRetention);
    $db = $schedule->databaseOnly();
    ok('database-only settings leave files out and keep their own retention', ! empty($db->exclude['no_files']) && empty($db->exclude['no_database']) && 3 === $db->retention);

    $runner = new BackupRunner(new Workspace());
    (new Scheduler($runner))->reschedule($schedule);
    ok('full and database jobs are two cron events on their own intervals', 'migrator_weekly' === ($GLOBALS['events'][Schedule::HOOK] ?? '')
        && 'migrator_every2hours' === ($GLOBALS['events'][Schedule::DB_HOOK] ?? ''));

    // 3. The two jobs' archive names never overlap, so neither prunes the other.
    $full   = Schedule::archivePrefix();
    $dbOnly = Schedule::withMarker(Schedule::DB_MARKER, static fn (): string => Schedule::archivePrefix());
    ok('archive prefixes differ and neither starts the other', $full !== $dbOnly && ! str_starts_with($dbOnly, $full) && ! str_starts_with($full, $dbOnly));
    ok('the prefix is put back after the database run', $full === Schedule::archivePrefix());

    // 4. A database-only export writes the database and no files.
    $ws  = new Workspace();
    $out = $ws->path('dbonly-test.migrator');
    $res = (new Exporter($ws, new Dumper()))->export($out, null, new ExportOptions(['no_files' => true]));
    ok('no_files exports the database and zero files', 1 === $res['tables'] && 0 === $res['files']);
    wp_delete_file($out);

    // 5. Frequent database runs never push out the full backups.
    $fullOld = $ws->path($full . '20260101-000000-aaaaaaaa.migrator');
    file_put_contents($fullOld, 'full');
    for ($i = 0; $i < 5; $i++) {
        $status = $runner->runDatabase($schedule);
        // Retention orders by mtime; five runs inside one second would tie.
        touch((string) $status['path'], time() - 100 + $i);
    }
    $dbFiles = glob($ws->path($dbOnly . '*.migrator')) ?: [];
    ok('database runs succeed', ! empty($status['ok']) && str_starts_with((string) $status['file'], $dbOnly));
    ok('database backups are pruned to their own count', 3 === count($dbFiles));
    ok('the full backup is still there', is_file($fullOld));
    ok('the full job retention does not count database backups', 1 === count($runner->archives()));
    ok('the list shows both kinds', 4 === count($runner->listArchives()));
    $dbWrites = array_values(array_filter($GLOBALS['writes'], static fn (array $w): bool => BackupRunner::DB_STATUS_OPTION === $w[0]));
    ok('a database run is recorded as started before it works', ! empty($dbWrites[0][1]['started']) && empty($dbWrites[1][1]['started']));
    ok('the database run has its own status', is_array($runner->lastDatabaseStatus()) && ! isset($GLOBALS['opts'][BackupRunner::STATUS_OPTION]));

    exec('rm -rf ' . escapeshellarg($content));

    echo $fail ? "\n$fail check(s) failed\n" : "\nall checks passed\n";
    exit($fail ? 1 : 0);
}
