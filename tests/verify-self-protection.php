<?php
/**
 * A restore must never write over the plugin doing the restoring.
 *
 * The guard used to be three hardcoded folder names, two of which were what
 * these plugins were called before they were renamed. Archive entries are
 * stored as wp-content/<rel>, so on a real install the plugin's own files
 * arrive as wp-content/plugins/plogins-migrator/... and matched none of them.
 * The class docblock promised protection that had never once fired.
 *
 * Run: php tests/verify-self-protection.php
 *
 * @package plogins-migrator
 */

// The derivation the importer now does, in isolation.
$prefixes = static function (string $freeBasename, ?string $proBasename): array {
    $out = ['wp-content/migrator-backups/'];
    foreach ([$freeBasename, $proBasename] as $basename) {
        if (null === $basename || '' === $basename) {
            continue;
        }
        $dir = dirname($basename);
        if ('' !== $dir && '.' !== $dir) {
            $out[] = 'wp-content/plugins/' . $dir . '/';
        }
    }
    return $out;
};

$isSafe = static function (string $entry, array $prefixes): bool {
    foreach ($prefixes as $p) {
        if (str_starts_with($entry, $p)) {
            return false;
        }
    }
    return true;
};

$fail = 0;
$assert = static function (string $label, bool $got, bool $want) use (&$fail): void {
    if ($got !== $want) {
        fwrite(STDERR, "FAIL {$label}\n");
        $fail = 1;
        return;
    }
    echo "ok   {$label}\n";
};

$live = $prefixes('plogins-migrator/plogins-migrator.php', 'plogins-migrator-pro/plogins-migrator-pro.php');

$assert('the running free plugin is protected', $isSafe('wp-content/plugins/plogins-migrator/src/Plugin.php', $live), false);
$assert('the running pro plugin is protected', $isSafe('wp-content/plugins/plogins-migrator-pro/src/ProPlugin.php', $live), false);
$assert('the backup store is protected', $isSafe('wp-content/migrator-backups/site.migrator', $live), false);
$assert('an ordinary plugin is restored', $isSafe('wp-content/plugins/woocommerce/woocommerce.php', $live), true);
$assert('an upload is restored', $isSafe('wp-content/uploads/2026/08/photo.jpg', $live), true);
$assert('a similarly named plugin is NOT swept up', $isSafe('wp-content/plugins/plogins-migrator-helper/x.php', $live), true);

// The old hardcoded list, kept to show what it did on a real install.
$old = ['wp-content/plugins/migrator/', 'wp-content/plugins/migrator-pro/', 'wp-content/migrator-backups/'];
$assert('the old list failed to protect the running plugin', $isSafe('wp-content/plugins/plogins-migrator/src/Plugin.php', $old), true);

// A rename must not need this file edited again.
$renamed = $prefixes('something-else/something-else.php', null);
$assert('a renamed plugin protects its new folder', $isSafe('wp-content/plugins/something-else/x.php', $renamed), false);

echo $fail ? "FAILED\n" : "all self-protection checks passed\n";
exit($fail);
