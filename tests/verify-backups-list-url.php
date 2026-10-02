<?php
/**
 * The Download links in the stored-backups list work. Run inside wp-env:
 *   wp eval-file wp-content/plugins/<dir>/tests/verify-backups-list-url.php
 *
 * The URL came from wp_nonce_url(), which escapes & as &amp; for HTML. The
 * script puts it straight into href, so the browser asked for "amp;nonce" and
 * the download handler refused every one.
 *
 * @package Migrator
 */

use Migrator\Admin\Ajax;
use Migrator\Plugin;
use Migrator\Support\Workspace;

$fail  = 0;
$check = static function (string $l, bool $c) use (&$fail): void {
    echo ($c ? '  ok   ' : '  FAIL ') . $l . "\n";
    $c || $fail++;
};

$ws   = new Workspace();
$file = $ws->path('localhost-20260101-000000-listurl.migrator');
file_put_contents($file, 'x');

wp_set_current_user(1);
add_filter('wp_doing_ajax', '__return_true');
add_filter('wp_die_ajax_handler', static fn () => static function (): void {
    throw new \RuntimeException('json sent');
});
$_POST    = ['nonce' => wp_create_nonce('migrator')];
$_REQUEST = $_POST;
ob_start();
try {
    Plugin::instance()->container()->get(Ajax::class)->backupsList();
} catch (\RuntimeException $e) {
    // wp_send_json ended the "request".
}
$json = (string) ob_get_clean();
@unlink($file);

$url = '';
foreach ((array) (json_decode($json, true)['data']['backups'] ?? []) as $backup) {
    if ('localhost-20260101-000000-listurl.migrator' === $backup['file']) {
        $url = (string) $backup['downloadUrl'];
    }
}
parse_str((string) wp_parse_url($url, PHP_URL_QUERY), $query);
$check('the list offers a download URL', '' !== $url);
$check('its query carries file and nonce as real parameters', isset($query['file'], $query['nonce']));
$check('and the nonce is valid for the download handler', false !== wp_verify_nonce((string) ($query['nonce'] ?? ''), 'migrator_download'));

echo 0 === $fail ? "backups list url: OK\n" : "backups list url: {$fail} failure(s)\n";
