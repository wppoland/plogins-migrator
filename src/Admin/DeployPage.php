<?php
// existing-checked: src/Admin/Page.php (render) - that class owns the main screen only; Deploy is its own submenu with a download action, as Scheduler and Rollback are.

declare(strict_types=1);

namespace Migrator\Admin;

use Migrator\Contract\HasHooks;
use Migrator\Support\Access;
use Migrator\Support\Workspace;

defined('ABSPATH') || exit;

/**
 * The "Deploy to a new server" screen. Explains the empty-server workflow and
 * hands out the standalone installer.
 *
 * The installer ships as a non-executable .txt stub so it can never run from
 * inside this plugin; it is served for download as installer.php with a fresh
 * access key's hash written in. The admin uploads it to the new server next to
 * a backup archive; it needs no WordPress or plugin code there.
 */
final class DeployPage implements HasHooks
{
    public const PAGE_SLUG = 'migrator-deploy';

    private const DOWNLOAD_ACTION = 'migrator_download_installer';

    private const KEY_PLACEHOLDER = '__MIGRATOR_ACCESS_KEY_HASH__';

    private string $hookSuffix = '';

    public function __construct(private Workspace $workspace)
    {
    }

    public function registerHooks(): void
    {
        // A PRO release from before the move still registers its own Deploy screen.
        if (class_exists('Migrator\Pro\Deploy\DeployPage')) {
            return;
        }
        add_action('admin_menu', [$this, 'registerMenu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue']);
        add_action('admin_post_' . self::DOWNLOAD_ACTION, [$this, 'handleDownload']);
    }

    public function registerMenu(): void
    {
        $hook = add_submenu_page(
            Page::SLUG,
            __('Deploy to a new server', 'plogins-migrator'),
            __('Deploy', 'plogins-migrator'),
            Access::capability(),
            self::PAGE_SLUG,
            [$this, 'render'],
        );

        if (is_string($hook)) {
            $this->hookSuffix = $hook;
        }
    }

    public function enqueue(string $hook): void
    {
        if ($hook !== $this->hookSuffix) {
            return;
        }

        wp_enqueue_style(
            'migrator-schedule',
            plugins_url('assets/schedule.css', \Migrator\PLUGIN_FILE),
            [],
            \Migrator\VERSION,
        );
    }

    public function render(): void
    {
        if (! Access::allowed()) {
            return;
        }

        $backups   = $this->backups();
        $accessKey = wp_generate_password(32, false);

        require \Migrator\PLUGIN_DIR . '/templates/deploy-page.php';
    }

    /**
     * Backups the installer can restore, newest first.
     *
     * @return array<int, array{file: string, bytes: int, time: int}>
     */
    public function backups(): array
    {
        $paths = array_merge(
            glob($this->workspace->path('*.migrator')) ?: [],
            glob($this->workspace->path('*.migrator.gz')) ?: [],
        );

        $items = [];
        foreach ($paths as $path) {
            $items[] = ['file' => basename($path), 'bytes' => (int) filesize($path), 'time' => (int) filemtime($path)];
        }
        usort($items, static fn (array $a, array $b): int => $b['time'] <=> $a['time']);

        return $items;
    }

    /**
     * Serve the installer stub as installer.php, locked to the access key the
     * Deploy screen showed alongside the button.
     */
    public function handleDownload(): void
    {
        if (! Access::allowed()) {
            wp_die(esc_html__('You are not allowed to do this.', 'plogins-migrator'));
        }
        check_admin_referer(self::DOWNLOAD_ACTION);

        $key = isset($_POST['access_key']) ? sanitize_text_field(wp_unslash($_POST['access_key'])) : '';
        if (! preg_match('/^[A-Za-z0-9]{32}$/', $key)) {
            wp_die(esc_html__('The access key is missing. Reload the Deploy screen and try again.', 'plogins-migrator'));
        }

        $stub = \Migrator\PLUGIN_DIR . '/templates/installer-stub.txt';
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local template file.
        $source = is_readable($stub) ? (string) file_get_contents($stub) : '';
        if ('' === $source) {
            wp_die(esc_html__('Installer template is missing.', 'plogins-migrator'));
        }
        $source = str_replace(self::KEY_PLACEHOLDER, hash('sha256', $key), $source);

        /**
         * Filters the installer.php source before it is downloaded. An add-on can
         * insert its own code here instead of shipping a second installer.
         *
         * @param string $source Installer PHP source.
         */
        $source = (string) apply_filters('migrator/installer_source', $source);

        // Anything already buffered would be prepended to the download.
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        nocache_headers();
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="installer.php"');
        header('Content-Length: ' . strlen($source));
        echo $source; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- a PHP file download, not HTML.
        exit;
    }
}
