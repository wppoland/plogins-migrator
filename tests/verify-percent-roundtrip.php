<?php
/**
 * Live round trip for values carrying a percent sign. Run inside wp-env, as two
 * separate requests on purpose:
 *
 *   wp eval-file wp-content/plugins/<dir>/tests/verify-percent-roundtrip.php export
 *   wp eval-file wp-content/plugins/<dir>/tests/verify-percent-roundtrip.php import
 *
 * $wpdb->_real_escape() swaps every % for a per-request placeholder hash. The
 * dumper never swapped it back, so a backup wrote the hash into the SQL, and the
 * restore (a different request, a different hash) put it into the database.
 * permalink_structure, "50% off" and a serialized %1$s (whose byte length no
 * longer matched, so the whole option was lost) all came back wrong.
 *
 * The export and the import run in two requests so the hash really differs.
 *
 * @package Migrator
 */

declare(strict_types=1);

use Migrator\Engine\Db\Dumper;
use Migrator\Engine\Export\ExportOptions;
use Migrator\Engine\Export\Exporter;
use Migrator\Engine\Import\Importer;
use Migrator\Support\Workspace;

global $wpdb;

$phase = $args[0] ?? '';
$ws    = new Workspace();
$ws->ensure();
$state = $ws->path('percent-roundtrip.json');

$values = [
    'permalink_structure'       => '/%postname%/',
    'migrator_pct_text'         => '50% off, 100%% sure',
    'migrator_pct_serialized'   => ['fmt' => '%1$s of %2$s', 'url' => 'https://example.test/?a=%20b'],
];

if ('export' === $phase) {
    foreach ($values as $key => $value) {
        update_option($key, $value);
    }
    $archive = $ws->path('percent-roundtrip.migrator');
    $options = ExportOptions::fromArray(['no_media' => true, 'no_themes' => true, 'no_plugins' => true, 'no_muplugins' => true]);
    (new Exporter($ws, new Dumper($wpdb)))->export($archive, null, $options);

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    $raw = $wpdb->get_results("SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name IN ('permalink_structure','migrator_pct_text','migrator_pct_serialized')", ARRAY_A);
    file_put_contents($state, wp_json_encode(['archive' => $archive, 'raw' => $raw]));

    // Damage the live values so only the restore can bring them back.
    foreach (array_keys($values) as $key) {
        update_option($key, 'overwritten');
    }
    echo "exported to {$archive}\n";

    return;
}

if ('import' !== $phase) {
    echo "Pass export or import.\n";

    return;
}

$saved = json_decode((string) file_get_contents($state), true);
(new Importer($ws, $wpdb))->import((string) $saved['archive'], false);
wp_cache_flush();

$fail  = 0;
$check = static function (string $l, bool $c) use (&$fail): void {
    echo ($c ? '  ok   ' : '  FAIL ') . $l . "\n";
    $c || $fail++;
};

// phpcs:ignore WordPress.DB.DirectDatabaseQuery
$after = $wpdb->get_results("SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name IN ('permalink_structure','migrator_pct_text','migrator_pct_serialized')", ARRAY_A);
$before = [];
foreach ((array) $saved['raw'] as $row) {
    $before[$row['option_name']] = $row['option_value'];
}
foreach ((array) $after as $row) {
    $check($row['option_name'] . ' is byte-identical (' . $row['option_value'] . ')', $before[$row['option_name']] === $row['option_value']);
}
$check('three rows compared', 3 === count((array) $after));
$check('the serialized option unserializes', get_option('migrator_pct_serialized') === $values['migrator_pct_serialized']);

delete_option('migrator_pct_text');
delete_option('migrator_pct_serialized');
@unlink((string) $saved['archive']);
@unlink($state);

echo 0 === $fail ? "percent round trip: OK\n" : "percent round trip: {$fail} failure(s)\n";
