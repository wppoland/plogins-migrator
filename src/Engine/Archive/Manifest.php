<?php

declare(strict_types=1);

namespace Migrator\Engine\Archive;

defined('ABSPATH') || exit;

/**
 * The first entry of every archive: a JSON record describing the site the
 * archive was made from. The importer reads it before touching anything, both
 * to validate the archive and to know what to search-and-replace (the source
 * URLs and paths) when the archive lands on a different site.
 *
 * Kept deliberately small and explicit, no nested plugin state, just the facts
 * an import needs to be safe.
 */
final class Manifest
{
    public const NAME          = 'migrator-manifest.json';
    public const FORMAT        = 'migrator';
    public const FORMAT_VERSION = 1;

    /**
     * @param array<string, mixed> $data
     */
    public function __construct(private array $data = [])
    {
    }

    /**
     * Build a manifest describing the current site.
     *
     * @param array<string, mixed> $extra Additional facts (table list, HPOS flag…).
     */
    public static function forThisSite(string $generatorVersion, array $extra = []): self
    {
        $data = array_merge([
            'format'         => self::FORMAT,
            'formatVersion'  => self::FORMAT_VERSION,
            'generator'      => 'Migrator',
            'generatorVersion' => $generatorVersion,
            'siteUrl'        => get_option('siteurl'),
            'homeUrl'        => get_option('home'),
            'abspath'        => untrailingslashit((string) ABSPATH),
            'contentDir'     => untrailingslashit((string) WP_CONTENT_DIR),
            'uploadsDir'     => self::uploadsBaseDir(),
            'tablePrefix'    => self::tablePrefix(),
            // The charset the dump's bytes are in. Archives before 1.4.0 lack
            // it, and their SET NAMES line cannot be trusted (see Importer).
            'dbCharset'      => self::dbCharset(),
            'wpVersion'      => get_bloginfo('version'),
            'phpVersion'     => PHP_VERSION,
            'multisite'      => is_multisite(),
            'wooActive'      => self::wooActive(),
            'wooHpos'        => self::wooHpos(),
        ], $extra);

        return new self($data);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return $this->data;
    }

    public function toJson(): string
    {
        return (string) wp_json_encode($this->data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    public static function fromJson(string $json): self
    {
        $decoded = json_decode($json, true);

        return new self(is_array($decoded) ? $decoded : []);
    }

    public function isSupported(): bool
    {
        return self::FORMAT === $this->get('format')
            && (int) $this->get('formatVersion', 0) <= self::FORMAT_VERSION;
    }

    private static function uploadsBaseDir(): string
    {
        $uploads = wp_get_upload_dir();

        return untrailingslashit((string) ($uploads['basedir'] ?? ''));
    }

    private static function tablePrefix(): string
    {
        global $wpdb;

        return isset($wpdb) ? (string) $wpdb->prefix : '';
    }

    private static function dbCharset(): string
    {
        global $wpdb;

        return isset($wpdb) ? (string) $wpdb->charset : '';
    }

    private static function wooActive(): bool
    {
        return class_exists('WooCommerce');
    }

    private static function wooHpos(): bool
    {
        if (! class_exists(\Automattic\WooCommerce\Utilities\OrderUtil::class)) {
            return false;
        }

        return \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
    }
}
