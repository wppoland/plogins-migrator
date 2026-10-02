<?php
/**
 * The export password reaches an add-on exactly as typed. Run inside wp-env:
 *
 *   wp eval-file wp-content/plugins/<dir>/tests/verify-password-passthrough.php
 *
 * The whole request went through sanitize_text_field before the
 * migrator/postprocess_request filter, which strips tags, %-octets and runs of
 * whitespace. The paid add-on then encrypted the archive with that mangled
 * password, and the one the merchant typed could never open it.
 *
 * @package Migrator
 */

use Migrator\Admin\Ajax;
use Migrator\Plugin;

$fail  = 0;
$check = static function (string $l, bool $c) use (&$fail): void {
    echo ($c ? '  ok   ' : '  FAIL ') . $l . "\n";
    $c || $fail++;
};

wp_set_current_user(1);
$typed = "p<a>ss  %41 wo\\rd\t!";
$seen  = null;
$probe = static function (array $default, $request) use (&$seen): array {
    $seen = $request;

    return [];
};
add_filter('migrator/postprocess_request', $probe, 10, 2);
add_filter('wp_doing_ajax', '__return_true');
add_filter('wp_die_ajax_handler', static fn () => static function (): void {
    throw new \RuntimeException('json sent');
});

$_POST = [
    'nonce'    => wp_create_nonce('migrator'),
    'encrypt'  => '1',
    'password' => wp_slash($typed),
    'options'  => ['no_media' => '1', 'no_themes' => '1', 'no_plugins' => '1'],
    'label'    => '<b>tagged</b>',
];
$_REQUEST = $_POST;

ob_start();
try {
    Plugin::instance()->container()->get(Ajax::class)->exportStart();
} catch (\RuntimeException $e) {
    // wp_send_json ended the "request".
}
ob_end_clean();

$check('the filter saw the request', is_array($seen));
$check('the password arrives byte for byte', $typed === ($seen['password'] ?? null));
$check('other fields are still sanitised', 'tagged' === ($seen['label'] ?? null));

$pipe = Plugin::instance()->container()->get(\Migrator\Engine\Export\ExportPipeline::class);
$job  = $pipe->current();
$pipe->clear();
if (! empty($job['dest']) && is_file((string) $job['dest'])) {
    wp_delete_file((string) $job['dest']);
}

echo 0 === $fail ? "password passthrough: OK\n" : "password passthrough: {$fail} failure(s)\n";
