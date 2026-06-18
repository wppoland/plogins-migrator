<?php

declare(strict_types=1);

namespace Migrator\Engine\Export;

defined('ABSPATH') || exit;

/**
 * What to leave out of an export. Mirrors All-in-One WP Migration's exclusions and
 * adds WooCommerce-aware ones (sessions, completed scheduled actions, transients)
 * so a backup can be lean and free of disposable data.
 *
 * The object turns toggles into the concrete artefacts the engine needs:
 *  - table names to skip entirely and per-table WHERE clauses (for the dumper);
 *  - absolute directory paths to prune (for the file scanner).
 */
final class ExportOptions
{
    /** @var array<string, bool> */
    private array $flags;

    private const KEYS = [
        'no_database',
        'no_media',
        'no_themes',
        'no_inactive_themes',
        'no_muplugins',
        'no_plugins',
        'no_inactive_plugins',
        'no_cache',
        'no_spam_comments',
        'no_post_revisions',
        'no_transients',
        'no_sessions',
        'no_action_scheduler',
    ];

    /**
     * @param array<string, bool> $flags
     */
    public function __construct(array $flags = [])
    {
        $this->flags = $flags;
    }

    /**
     * Build from a raw request/CLI array, coercing values to booleans and
     * ignoring unknown keys.
     *
     * @param array<string, mixed> $raw
     */
    public static function fromArray(array $raw): self
    {
        $flags = [];
        foreach (self::KEYS as $key) {
            $flags[$key] = ! empty($raw[$key]);
        }

        return new self($flags);
    }

    /**
     * @return string[]
     */
    public static function keys(): array
    {
        return self::KEYS;
    }

    public function is(string $key): bool
    {
        return ! empty($this->flags[$key]);
    }

    /**
     * @return array<string, bool>
     */
    public function toArray(): array
    {
        return $this->flags;
    }

    public function excludeDatabase(): bool
    {
        return $this->is('no_database');
    }

    /**
     * Tables to skip entirely (structure + data), given the site prefix.
     *
     * @return string[]
     */
    public function tablesToSkip(string $prefix): array
    {
        $skip = [];
        if ($this->is('no_sessions')) {
            $skip[] = $prefix . 'woocommerce_sessions';
        }
        if ($this->is('no_action_scheduler')) {
            $skip[] = $prefix . 'actionscheduler_logs';
            $skip[] = $prefix . 'actionscheduler_actions';
            $skip[] = $prefix . 'actionscheduler_claims';
            $skip[] = $prefix . 'actionscheduler_groups';
        }

        return $skip;
    }

    /**
     * Per-table WHERE clauses that filter out disposable rows but keep the table.
     *
     * @return array<string, string> table name => WHERE clause (no "WHERE")
     */
    public function whereFilters(string $prefix): array
    {
        $where = [];
        if ($this->is('no_spam_comments')) {
            $where[$prefix . 'comments'] = "comment_approved != 'spam'";
        }
        if ($this->is('no_post_revisions')) {
            $where[$prefix . 'posts'] = "post_type != 'revision'";
        }
        if ($this->is('no_transients')) {
            $where[$prefix . 'options'] = "option_name NOT LIKE '\\_transient\\_%' AND option_name NOT LIKE '\\_site\\_transient\\_%'";
        }

        return $where;
    }

    /**
     * Absolute directory paths to prune from the file scan, resolved against the
     * live site (uploads dir, theme/plugin roots, active vs inactive, cache).
     *
     * @return string[]
     */
    public function fileExcludePaths(): array
    {
        $paths = [];

        if ($this->is('no_media')) {
            $uploads = wp_get_upload_dir();
            if (! empty($uploads['basedir'])) {
                $paths[] = (string) $uploads['basedir'];
            }
        }

        if ($this->is('no_themes')) {
            $paths[] = (string) get_theme_root();
        } elseif ($this->is('no_inactive_themes')) {
            $paths = array_merge($paths, $this->inactiveThemeDirs());
        }

        if ($this->is('no_muplugins') && defined('WPMU_PLUGIN_DIR')) {
            $paths[] = (string) WPMU_PLUGIN_DIR;
        }

        if ($this->is('no_plugins') && defined('WP_PLUGIN_DIR')) {
            $paths[] = (string) WP_PLUGIN_DIR;
        } elseif ($this->is('no_inactive_plugins')) {
            $paths = array_merge($paths, $this->inactivePluginDirs());
        }

        if ($this->is('no_cache')) {
            $paths[] = untrailingslashit((string) WP_CONTENT_DIR) . '/cache';
        }

        return array_values(array_unique(array_filter($paths)));
    }

    /**
     * @return string[]
     */
    private function inactiveThemeDirs(): array
    {
        $active = [
            (string) get_template_directory(),   // parent theme
            (string) get_stylesheet_directory(), // active (child) theme
        ];

        $root  = untrailingslashit((string) get_theme_root());
        $dirs  = glob($root . '/*', GLOB_ONLYDIR) ?: [];
        $skip  = [];
        foreach ($dirs as $dir) {
            if (! in_array($dir, $active, true)) {
                $skip[] = $dir;
            }
        }

        return $skip;
    }

    /**
     * @return string[]
     */
    private function inactivePluginDirs(): array
    {
        if (! defined('WP_PLUGIN_DIR')) {
            return [];
        }
        $root = untrailingslashit((string) WP_PLUGIN_DIR);

        $active = [];
        foreach ((array) get_option('active_plugins', []) as $plugin) {
            $active[] = $root . '/' . strtok((string) $plugin, '/');
        }
        // Never prune Migrator itself.
        $active[] = $root . '/migrator';
        $active[] = $root . '/migrator-pro';

        $dirs = glob($root . '/*', GLOB_ONLYDIR) ?: [];
        $skip = [];
        foreach ($dirs as $dir) {
            if (! in_array($dir, $active, true)) {
                $skip[] = $dir;
            }
        }

        return $skip;
    }
}
