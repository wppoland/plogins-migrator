<?php
/**
 * Backup before updates, and the failure email:  php tests/before-update-and-failure-mail.php
 *
 * Runs Scheduler against a stand-in BackupRunner and the few WordPress
 * functions it calls, so it needs no WordPress install.
 *
 * @package Migrator
 */

declare(strict_types=1);

namespace Migrator\Backup {
    // Stand-in for the real runner: records each run and returns a set outcome.
    final class BackupRunner
    {
        /** @var list<bool> allowStrategies of each run. */
        public array $runs = [];

        /** @var array<string, mixed> */
        public array $status = ['ok' => true, 'message' => ''];

        /** @return array<string, mixed> */
        public function run(Schedule $schedule, bool $allowStrategies = true): array
        {
            $this->runs[] = $allowStrategies;

            return $this->status;
        }
    }
}

namespace {
    define('ABSPATH', __DIR__ . '/');
    define('MINUTE_IN_SECONDS', 60);
    define('DAY_IN_SECONDS', 86400);
    define('WEEK_IN_SECONDS', 604800);

    $GLOBALS['opts']  = [];
    $GLOBALS['trans'] = [];
    $GLOBALS['mails'] = [];
    function get_option($k, $d = false) { return $GLOBALS['opts'][$k] ?? $d; }
    function get_transient($k) { return $GLOBALS['trans'][$k] ?? false; }
    function set_transient($k, $v, $t = 0) { $GLOBALS['trans'][$k] = $v; return true; }
    function wp_mail($to, $subject, $body) { $GLOBALS['mails'][] = compact('to', 'subject', 'body'); return true; }
    function is_email($e) { return (bool) filter_var($e, FILTER_VALIDATE_EMAIL); }
    function __($s, $d = '') { return $s; }
    function wp_specialchars_decode($s, $q = 0) { return $s; }
    function home_url($p = '') { return 'https://example.test' . $p; }
    function admin_url($p = '') { return 'https://example.test/wp-admin/' . $p; }
    $GLOBALS['hooks'] = [];
    function add_filter($h, $cb, $p = 10, $n = 1) { $GLOBALS['hooks'][$h] = $n; return true; }
    function add_action($h, $cb, $p = 10, $n = 1) { $GLOBALS['hooks'][$h] = $n; return true; }

    class Core_Upgrader {}
    class Plugin_Upgrader {}

    require __DIR__ . '/../src/Contract/HasHooks.php';
    require __DIR__ . '/../src/Engine/Export/ExportOptions.php';
    require __DIR__ . '/../src/Backup/Schedule.php';
    require __DIR__ . '/../src/Backup/Scheduler.php';

    use Migrator\Backup\BackupRunner;
    use Migrator\Backup\Schedule;
    use Migrator\Backup\Scheduler;

    $failures = 0;
    function ok(string $label, bool $cond): void
    {
        global $failures;
        echo ($cond ? '  ok   ' : '  FAIL ') . $label . "\n";
        $cond || $failures++;
    }

    $runner    = new BackupRunner();
    $scheduler = new Scheduler($runner);
    $has       = method_exists($scheduler, 'backupBeforeUpdate');
    $pre       = static fn (...$a) => $has ? $scheduler->backupBeforeUpdate(...$a) : false;

    $scheduler->registerHooks();
    ok('Scheduler hooks upgrader_pre_download with all four arguments', 4 === ($GLOBALS['hooks']['upgrader_pre_download'] ?? 0) && $has);

    // Off by default: an update runs no backup.
    $GLOBALS['opts'][Schedule::OPTION] = ['enabled' => false];
    $pre(false, 'pkg.zip', new Plugin_Upgrader(), ['plugin' => 'a/a.php', 'type' => 'plugin', 'action' => 'update']);
    ok('no backup when the setting is off', [] === $runner->runs);

    // On, even with scheduled backups off.
    $GLOBALS['opts'][Schedule::OPTION] = ['enabled' => false, 'before_update' => true];
    $pre(false, 'pkg.zip', new Plugin_Upgrader(), ['type' => 'plugin', 'action' => 'install']);
    ok('installing a new plugin takes no backup', [] === $runner->runs);

    $reply = $pre(false, 'pkg.zip', new Plugin_Upgrader(), ['plugin' => 'a/a.php', 'type' => 'plugin', 'action' => 'update']);
    ok('a plugin update takes one plain full backup', [false] === $runner->runs);
    ok('and the update itself is not blocked', false === $reply);

    $pre(false, 'pkg.zip', new Plugin_Upgrader(), ['plugin' => 'b/b.php']);
    ok('the next update in the burst shares that backup', 1 === count($runner->runs));

    $GLOBALS['trans'] = [];
    $pre(false, 'core.zip', new Core_Upgrader(), []);
    ok('a core update takes a backup', 2 === count($runner->runs));

    $GLOBALS['trans'] = [];
    $pre(false, 'theme.zip', new Plugin_Upgrader(), ['theme' => 'twentyx']);
    ok('a theme update takes a backup', 3 === count($runner->runs));

    // Failure email on a scheduled run.
    $runner->status = ['ok' => false, 'message' => 'Disk full'];
    $GLOBALS['opts'][Schedule::OPTION] = ['enabled' => true];
    $scheduler->runScheduledBackup();
    ok('no email while the failure email is off', [] === $GLOBALS['mails']);

    $GLOBALS['opts'][Schedule::OPTION]  = ['enabled' => true, 'notify_failure' => true];
    $GLOBALS['opts']['admin_email']     = 'owner@example.test';
    $GLOBALS['opts']['blogname']        = 'Shop';
    $scheduler->runScheduledBackup();
    $mail = $GLOBALS['mails'][0] ?? [];
    ok('a failed scheduled backup is mailed to the admin email', 'owner@example.test' === ($mail['to'] ?? ''));
    ok('the email carries the error', str_contains((string) ($mail['body'] ?? ''), 'Disk full'));

    $GLOBALS['mails'] = [];
    $GLOBALS['opts'][Schedule::OPTION] = ['enabled' => true, 'notify_failure' => true, 'notify_email' => 'ops@example.test'];
    $scheduler->runScheduledBackup();
    ok('a chosen address gets it instead', 'ops@example.test' === ($GLOBALS['mails'][0]['to'] ?? ''));

    $GLOBALS['mails'] = [];
    $runner->status   = ['ok' => true, 'message' => ''];
    $scheduler->runScheduledBackup();
    ok('a backup that succeeds sends nothing', [] === $GLOBALS['mails']);

    echo 0 === $failures ? "\nALL PASSED\n" : "\n{$failures} FAILED\n";
    exit(0 === $failures ? 0 : 1);
}
