<?php

declare(strict_types=1);

namespace Migrator\Admin;

use Migrator\Backup\BackupRunner;
use Migrator\Backup\Schedule;
use Migrator\Backup\Scheduler;
use Migrator\Contract\HasHooks;

use const Migrator\PLUGIN_FILE;

defined('ABSPATH') || exit;

/**
 * "Roll back" on the Plugins screen: reinstall an earlier WordPress.org release of
 * a plugin, after a full backup of the site. Plugins only.
 *
 * The backup is a precondition, not best effort. An update is never blocked by a
 * failed backup (Scheduler::backupBeforeUpdate); a rollback is a deliberate
 * downgrade the owner can simply retry, so without an archive it does not start.
 */
final class Rollback implements HasHooks
{
    public const PAGE_SLUG = 'migrator-rollback';

    private const NONCE = 'migrator_rollback';

    /** Releases offered in the picker, newest first. */
    private const MAX_VERSIONS = 30;

    public function __construct(private BackupRunner $runner)
    {
    }

    public function registerHooks(): void
    {
        $hook = is_multisite() ? 'network_admin_' : '';
        add_filter($hook . 'plugin_action_links', [$this, 'actionLink'], 10, 2);
        add_action($hook . 'admin_menu', [$this, 'registerPage']);
    }

    /**
     * @param array<int|string, string> $links
     *
     * @return array<int|string, string>
     */
    public function actionLink(array $links, string $file): array
    {
        if (! current_user_can('update_plugins') || null === self::wpOrgSlug($file)) {
            return $links;
        }

        $links['migrator_rollback'] = sprintf(
            '<a href="%s">%s</a>',
            esc_url(self::pageUrl($file)),
            esc_html__('Roll back', 'plogins-migrator'),
        );

        return $links;
    }

    public function registerPage(): void
    {
        // No parent: reached only from the Plugins screen link.
        add_submenu_page(
            '',
            __('Roll back a plugin', 'plogins-migrator'),
            '',
            'update_plugins',
            self::PAGE_SLUG,
            [$this, 'render'],
        );
    }

    public function render(): void
    {
        if (! current_user_can('update_plugins')) {
            wp_die(esc_html__('You are not allowed to update plugins.', 'plogins-migrator'));
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only lookup; the change itself checks the nonce below.
        $file    = sanitize_text_field(wp_unslash((string) ($_REQUEST['plugin'] ?? '')));
        $plugins = get_plugins();
        $slug    = self::wpOrgSlug($file);

        echo '<div class="wrap"><h1>' . esc_html__('Roll back a plugin', 'plogins-migrator') . '</h1>';

        if (! isset($plugins[$file]) || null === $slug) {
            echo '<p>' . esc_html__('Only plugins from WordPress.org can be rolled back.', 'plogins-migrator') . '</p></div>';

            return;
        }

        $current  = (string) $plugins[$file]['Version'];
        $versions = self::versions($slug, $current);

        if (is_wp_error($versions)) {
            echo '<div class="notice notice-error"><p>' . esc_html($versions->get_error_message()) . '</p></div></div>';

            return;
        }

        if (isset($_POST['version'])) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- the nonce is checked on the next line.
            check_admin_referer(self::NONCE);
            $version = sanitize_text_field(wp_unslash((string) ($_POST['version'] ?? '')));

            if (! isset($versions[$version])) {
                echo '<div class="notice notice-error"><p>' . esc_html__('Pick a version from the list.', 'plogins-migrator') . '</p></div>';
            } else {
                $this->rollBack($file, (string) $plugins[$file]['Name'], $version, $versions[$version]);
                echo '</div>';

                return;
            }
        }

        $this->renderForm($file, (string) $plugins[$file]['Name'], $current, $versions);
        echo '</div>';
    }

    /**
     * @param array<string, string> $versions
     */
    private function renderForm(string $file, string $name, string $current, array $versions): void
    {
        printf(
            '<p>%s</p>',
            esc_html(sprintf(
                /* translators: 1: plugin name, 2: installed version */
                __('%1$s %2$s is installed. Pick the release to go back to.', 'plogins-migrator'),
                $name,
                $current,
            )),
        );

        if ([] === $versions) {
            echo '<p>' . esc_html__('WordPress.org lists no earlier release of this plugin.', 'plogins-migrator') . '</p>';

            return;
        }

        echo '<form method="post" action="' . esc_url(self::pageUrl($file)) . '">';
        wp_nonce_field(self::NONCE);
        echo '<p><label for="migrator-rollback-version">' . esc_html__('Version', 'plogins-migrator') . '</label> ';
        echo '<select id="migrator-rollback-version" name="version">';
        foreach (array_keys($versions) as $version) {
            printf('<option value="%1$s">%1$s</option>', esc_attr((string) $version));
        }
        echo '</select></p>';
        echo '<p class="description">' . esc_html__('Migrator first saves a full backup of the site (files and database) to your backup folder. If the backup fails, nothing is changed. The settings a newer version saved stay in the database, and an older version may not read them.', 'plogins-migrator') . '</p>';
        submit_button(__('Back up the site and roll back', 'plogins-migrator'));
        echo '</form>';
    }

    private function rollBack(string $file, string $name, string $version, string $package): void
    {
        echo '<p>' . esc_html__('Saving a full backup of the site first. This can take a few minutes.', 'plogins-migrator') . '</p>';
        flush();

        // A plain full archive, as before an update: an incremental link or an
        // encrypted file is no use as the way back.
        $status = $this->runner->run(Schedule::load(), false);

        if (empty($status['ok'])) {
            $error = (string) ($status['message'] ?? '');
            printf(
                '<div class="notice notice-error"><p>%s %s</p></div>',
                esc_html__('The backup failed, so the plugin was not changed.', 'plogins-migrator'),
                esc_html($error),
            );

            return;
        }

        // The archive just written is the rollback point; the upgrader's
        // pre-download hook must not take a second one.
        set_transient(Scheduler::UPDATE_LOCK, time(), 10 * MINUTE_IN_SECONDS);

        $wasActive        = is_plugin_active($file);
        $wasNetworkActive = is_multisite() && is_plugin_active_for_network($file);

        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

        // The generic skin: Plugin_Upgrader_Skin adds a reactivation iframe, done below instead.
        $upgrader = new \Plugin_Upgrader(new \WP_Upgrader_Skin([
            /* translators: 1: plugin name, 2: version */
            'title' => sprintf(__('Rolling back %1$s to %2$s', 'plogins-migrator'), $name, $version),
        ]));
        // run() skips what upgrade() sets up: the skin's link back and the progress strings.
        $upgrader->init();
        $upgrader->upgrade_strings();
        $upgrader->strings['installing_package'] = __('Installing the chosen version&#8230;', 'plogins-migrator');
        $upgrader->strings['process_success']    = __('Plugin rolled back successfully.', 'plogins-migrator');

        $result = $upgrader->run([
            'package'           => $package,
            'destination'       => WP_PLUGIN_DIR,
            'clear_destination' => true,
            'clear_working'     => true,
            'hook_extra'        => ['plugin' => $file, 'type' => 'plugin', 'action' => 'update'],
        ]);

        wp_clean_plugins_cache();

        if (true !== $result && ! is_array($result)) {
            return; // The skin has printed why.
        }

        // run() replaces the files but leaves activation to the caller.
        if ($wasActive) {
            $activated = activate_plugin($file, '', $wasNetworkActive, true);
            if (is_wp_error($activated)) {
                echo '<div class="notice notice-error"><p>' . esc_html($activated->get_error_message()) . '</p></div>';
            }
        }

        printf(
            '<p><a href="%s">%s</a></p>',
            esc_url(self_admin_url('plugins.php')),
            esc_html__('Back to Plugins', 'plogins-migrator'),
        );
    }

    /**
     * Earlier releases, newest first, each mapped to the package WordPress.org
     * serves. The download URL always comes from here, never from the request.
     *
     * @return array<string, string>|\WP_Error
     */
    public static function versions(string $slug, string $current)
    {
        require_once ABSPATH . 'wp-admin/includes/plugin-install.php';

        $info = plugins_api('plugin_information', ['slug' => $slug, 'fields' => ['versions' => true, 'sections' => false]]);
        if (is_wp_error($info)) {
            return $info;
        }

        return self::olderReleases((array) ($info->versions ?? []), $current);
    }

    /**
     * @param array<int|string, mixed> $versions version => package URL, as the API returns them.
     *
     * @return array<string, string>
     */
    public static function olderReleases(array $versions, string $current): array
    {
        $older = [];
        foreach ($versions as $version => $url) {
            $version = (string) $version;
            if ('trunk' !== $version && is_string($url) && str_starts_with($url, 'https://downloads.wordpress.org/') && version_compare($version, $current, '<')) {
                $older[$version] = $url;
            }
        }

        uksort($older, static fn (string $a, string $b): int => version_compare($b, $a));

        return array_slice($older, 0, self::MAX_VERSIONS, true);
    }

    /**
     * The WordPress.org slug of an installed plugin, or null when it is not
     * hosted there (premium, custom) or is Migrator itself, which would be
     * replacing its own code mid-request.
     */
    private static function wpOrgSlug(string $file): ?string
    {
        if ('' === $file || plugin_basename(PLUGIN_FILE) === $file) {
            return null;
        }

        // WordPress records the wp.org listing of every plugin it checks for updates.
        $updates = get_site_transient('update_plugins');
        $item    = $updates->response[$file] ?? $updates->no_update[$file] ?? null;
        $slug    = is_object($item) ? (string) ($item->slug ?? '') : '';

        return '' !== $slug && str_contains((string) ($item->url ?? ''), 'wordpress.org/plugins/') ? $slug : null;
    }

    private static function pageUrl(string $file): string
    {
        return add_query_arg(['page' => self::PAGE_SLUG, 'plugin' => rawurlencode($file)], self_admin_url('admin.php'));
    }
}
