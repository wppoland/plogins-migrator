<?php

declare(strict_types=1);

namespace Migrator\Reprint;

use Migrator\Contract\HasHooks;
use Migrator\Support\Access;

defined('ABSPATH') || exit;

/**
 * The source role: lets another site pull this one (and, if allowed, push back)
 * through the bundled Reprint Server, at `?reprint-api`.
 *
 * Off until an administrator switches it on. Even then nothing is reachable
 * without credentials: every request must be signed with an enrolled key or the
 * connection token, both set on the Reprint Server screen under Tools.
 *
 * When the standalone Reprint Server plugin is active it already owns that
 * endpoint and its option names, and loading a second copy of the same
 * functions would be a fatal error, so Migrator steps aside and says so.
 */
final class Source implements HasHooks
{
    public const OPTION      = 'migrator_reprint_source';
    public const PAGE_SLUG   = 'migrator-pull-push';
    public const SAVE_ACTION = 'migrator_reprint_source_save';

    private const SERVER_ENTRY = '/lib/reprint/reprint-server-wp/index.php';

    /**
     * Runs while WordPress includes plugin files, before plugins_loaded: the
     * Reprint endpoint answers and exits at that point, so it has to be in
     * place by then.
     */
    public static function load(): void
    {
        if (! self::enabled() || self::standalonePluginActive()) {
            return;
        }

        // A push must never overwrite Migrator itself (the bundled server only
        // protects its own lib/ subfolder) or the backups it keeps.
        add_filter('reprint_server_api_options', [self::class, 'protectOwnPaths'], 1);

        require_once \Migrator\PLUGIN_DIR . self::SERVER_ENTRY;
    }

    /**
     * @param mixed $options
     * @return mixed
     */
    public static function protectOwnPaths($options)
    {
        if (! is_array($options)) {
            return $options;
        }

        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- compared as a path, never output.
        $docroot = (string) ($options['docroot'] ?? ($_SERVER['DOCUMENT_ROOT'] ?? ''));
        $root    = $docroot !== '' ? realpath($docroot) : false;
        if ($root === false) {
            return $options;
        }

        $excluded = is_array($options['excluded_paths'] ?? null) ? $options['excluded_paths'] : [];
        foreach ([\Migrator\PLUGIN_DIR, WP_CONTENT_DIR . '/migrator-backups'] as $path) {
            $real = realpath($path);
            if ($real !== false && str_starts_with($real . '/', rtrim($root, '/') . '/')) {
                $excluded[] = ltrim(substr($real, strlen(rtrim($root, '/'))), '/');
            }
        }
        $options['excluded_paths'] = array_values(array_unique($excluded));


        return $options;
    }

    public static function enabled(): bool
    {
        return (bool) get_site_option(self::OPTION, false);
    }

    /**
     * The standalone plugin has shipped as reprint-exporter-wp and
     * reprint-server-wp; match the folder prefix so a renamed release is
     * still recognised.
     */
    public static function standalonePluginActive(): bool
    {
        $active = (array) get_option('active_plugins', []);
        if (is_multisite()) {
            $active = array_merge($active, array_keys((array) get_site_option('active_sitewide_plugins', [])));
        }

        foreach ($active as $plugin) {
            if (str_starts_with((string) $plugin, 'reprint-')) {
                return true;
            }
        }

        return false;
    }

    public function registerHooks(): void
    {
        add_action('admin_menu', [$this, 'registerMenu']);
        add_action('admin_post_' . self::SAVE_ACTION, [$this, 'handleSave']);
    }

    public function registerMenu(): void
    {
        $hook = add_submenu_page(
            'migrator',
            __('Pull and Push', 'plogins-migrator'),
            __('Pull and Push', 'plogins-migrator'),
            Access::capability(),
            self::PAGE_SLUG,
            [$this, 'render'],
        );

        // Same card layout as the Scheduled Backups screen.
        if (is_string($hook)) {
            add_action('admin_print_styles-' . $hook, static function (): void {
                wp_enqueue_style('migrator-schedule', plugins_url('assets/schedule.css', \Migrator\PLUGIN_FILE), [], \Migrator\VERSION);
            });
        }
    }

    public function render(): void
    {
        if (! Access::allowed()) {
            return;
        }

        $enabled    = self::enabled();
        $standalone = self::standalonePluginActive();
        $serverUrl  = admin_url('tools.php?page=reprint-server');
        $siteUrl    = home_url('/');

        require \Migrator\PLUGIN_DIR . '/templates/pull-push-page.php';
    }

    public function handleSave(): void
    {
        if (! Access::allowed()) {
            wp_die(esc_html__('You are not allowed to do this.', 'plogins-migrator'));
        }
        check_admin_referer(self::SAVE_ACTION);

        $enabled = ! empty($_POST['enabled']);
        update_site_option(self::OPTION, $enabled);

        wp_safe_redirect(add_query_arg(
            ['page' => self::PAGE_SLUG, 'updated' => '1'],
            admin_url('admin.php'),
        ));
        exit;
    }
}
