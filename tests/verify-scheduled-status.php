<?php
/**
 * A scheduled run that is killed part way is visible. Run inside wp-env:
 *   wp eval-file wp-content/plugins/<dir>/tests/verify-scheduled-status.php
 *
 * The status was only written at the end, so a run the host killed left the
 * previous success on screen.
 *
 * @package Migrator
 */

use Migrator\Backup\BackupRunner;
use Migrator\Backup\Schedule;
use Migrator\Plugin;

$fail  = 0;
$check = static function (string $l, bool $c) use (&$fail): void {
    echo ($c ? '  ok   ' : '  FAIL ') . $l . "\n";
    $c || $fail++;
};

$runner = Plugin::instance()->container()->get(BackupRunner::class);
$seen   = null;
// Take the run over, as an add-on strategy may, and look at what was recorded
// while it was in progress: that is what a killed run leaves behind.
$probe = static function ($status) use ($runner, &$seen) {
    $seen = $runner->lastStatus();

    return ['time' => time(), 'ok' => true, 'path' => '', 'file' => '', 'bytes' => 0, 'message' => ''];
};
add_filter('migrator/backup_run', $probe);
$runner->run(Schedule::fromArray(['enabled' => true]));
remove_filter('migrator/backup_run', $probe);

$check('a run in progress is recorded as started', ! empty($seen['started']) && empty($seen['ok']));
$check('and the finished run replaces it', empty($runner->lastStatus()['started']) && ! empty($runner->lastStatus()['ok']));

echo 0 === $fail ? "scheduled status: OK\n" : "scheduled status: {$fail} failure(s)\n";
