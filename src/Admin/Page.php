<?php

declare(strict_types=1);

namespace Migrator\Admin;

use Migrator\Contract\HasHooks;

defined('ABSPATH') || exit;

/**
 * Registers the Migrator admin screen and loads its assets only on that screen.
 */
final class Page implements HasHooks
{
    public const SLUG = 'migrator';

    private string $hookSuffix = '';

    public function registerHooks(): void
    {
        add_action('admin_menu', [$this, 'registerMenu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue']);
        add_filter('plugin_action_links_' . plugin_basename(\Migrator\PLUGIN_FILE), [$this, 'actionLinks']);
    }

    /**
     * Add a quick "Settings" link to the plugin's row on the Plugins screen.
     *
     * @param array<int|string, string> $links Existing action links.
     * @return array<int|string, string>
     */
    public function actionLinks(array $links): array
    {
        array_unshift($links, sprintf(
            '<a href="%s">%s</a>',
            esc_url(admin_url('admin.php?page=' . self::SLUG)),
            esc_html__('Settings', 'migrator'),
        ));

        return $links;
    }

    public function registerMenu(): void
    {
        /**
         * Filters the admin menu label and page title. A companion add-on uses
         * this to white-label the plugin under an agency's own name.
         *
         * @param string $label Default "Migrator".
         */
        $label = (string) apply_filters('migrator/menu_label', __('Migrator', 'migrator'));

        $hook = add_menu_page(
            $label,
            $label,
            'manage_options',
            self::SLUG,
            [$this, 'render'],
            'dashicons-migrate',
            81,
        );

        $this->hookSuffix = is_string($hook) ? $hook : '';
    }

    public function render(): void
    {
        require \Migrator\PLUGIN_DIR . '/templates/admin-page.php';
    }

    public function enqueue(string $hook): void
    {
        if ($hook !== $this->hookSuffix) {
            return;
        }

        $version = (string) \Migrator\VERSION;
        $file    = \Migrator\PLUGIN_FILE;

        wp_enqueue_style('migrator-admin', plugins_url('assets/admin.css', $file), [], $version);
        wp_enqueue_script('migrator-admin', plugins_url('assets/admin.js', $file), [], $version, true);
        wp_localize_script('migrator-admin', 'migratorData', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('migrator'),
            'i18n'    => [
                'preparing'     => __('Preparing…', 'migrator'),
                'archiving'     => __('Archiving files…', 'migrator'),
                'done'          => __('Backup ready.', 'migrator'),
                'failed'        => __('Export failed.', 'migrator'),
                'uploading'     => __('Uploading…', 'migrator'),
                'restoring'     => __('Restoring… do not close this tab.', 'migrator'),
                'restoreDone'   => __('Restore complete.', 'migrator'),
                'restoreFailed' => __('Restore failed.', 'migrator'),
                'confirmRestore' => __('This overwrites the current site with the backup. A safety copy of the database is taken first. Continue?', 'migrator'),
                'scanning'      => __('Scanning…', 'migrator'),
                'scanFailed'    => __('Scan failed.', 'migrator'),
                'filesWord'     => __('files', 'migrator'),
            ],
        ]);
    }
}
