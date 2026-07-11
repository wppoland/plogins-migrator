<?php

declare(strict_types=1);

namespace Migrator\Engine\Archive;

defined('ABSPATH') || exit;

/**
 * Reads an archive's manifest without restoring it, so the admin screen can show
 * what a backup contains (source URL, WordPress/PHP versions, table and file
 * counts) before anyone overwrites a live site with it.
 *
 * The manifest is always the first entry, read sequentially, so a compressed
 * archive is streamed through the zlib wrapper and stops after the manifest
 * rather than decompressing the whole file.
 */
final class Inspector
{
    /**
     * @throws \RuntimeException When the file is missing or is not a Migrator archive.
     */
    public static function manifest(string $path, bool $compressed): Manifest
    {
        if (! is_file($path)) {
            throw new \RuntimeException('Migrator: backup file not found.');
        }

        $open   = $compressed ? 'compress.zlib://' . $path : $path;
        $reader = new Reader($open);
        try {
            $first = $reader->nextEntry();
            if (null === $first || ! $first->isManifest()) {
                throw new \RuntimeException('Migrator: archive has no manifest (is this a Migrator archive?).');
            }

            return Manifest::fromJson($reader->readContents());
        } finally {
            $reader->close();
        }
    }

    /**
     * Human-facing summary rows for the manifest, ready to render.
     *
     * @return array<int, array{label: string, value: string}>
     */
    public static function summary(Manifest $manifest): array
    {
        $tables = (array) $manifest->get('tables', []);
        $rows   = [
            [__('Source site', 'plogins-migrator'), (string) $manifest->get('siteUrl')],
            [__('Made with', 'plogins-migrator'), 'Migrator ' . (string) $manifest->get('generatorVersion')],
            [__('WordPress', 'plogins-migrator'), (string) $manifest->get('wpVersion')],
            [__('PHP', 'plogins-migrator'), (string) $manifest->get('phpVersion')],
            [__('Table prefix', 'plogins-migrator'), (string) $manifest->get('tablePrefix')],
            [__('Tables', 'plogins-migrator'), (string) count($tables)],
            [__('Multisite', 'plogins-migrator'), $manifest->get('multisite') ? __('Yes', 'plogins-migrator') : __('No', 'plogins-migrator')],
        ];
        if ($manifest->get('wooActive')) {
            $rows[] = [__('WooCommerce', 'plogins-migrator'), $manifest->get('wooHpos') ? __('Yes (HPOS)', 'plogins-migrator') : __('Yes', 'plogins-migrator')];
        }

        return array_map(
            static fn (array $r): array => ['label' => (string) $r[0], 'value' => (string) $r[1]],
            $rows
        );
    }
}
