<?php
/**
 * Plugin Name:       Migrator - Site Migration and Backup
 * Plugin URI:        https://plogins.com/plogins-migrator/
 * Description:        Back up, clone and migrate your WordPress site to a new host. One file, drag and drop, no technical setup.
 * Version:           1.5.1
 * Requires at least: 6.5
 * Requires PHP:      8.1
 * Author:            WPPoland.com
 * Author URI:        https://wppoland.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       plogins-migrator
 * Domain Path:       /languages
 *
 * @package Migrator
 */

declare(strict_types=1);

namespace Migrator;

defined('ABSPATH') || exit;

const VERSION     = '1.5.1';
const PLUGIN_FILE     = __FILE__;
const PLUGIN_DIR      = __DIR__;
const MIN_PHP_VERSION = '8.1.0';

define('MIGRATOR_DIR', plugin_dir_path(__FILE__));
define('MIGRATOR_URL', plugin_dir_url(__FILE__));

// Require PHP 8.1+ before loading any typed code.
if (version_compare(PHP_VERSION, MIN_PHP_VERSION, '<')) {
    add_action('admin_notices', static function (): void {
        printf(
            '<div class="notice notice-error"><p>%s</p></div>',
            esc_html(sprintf(
                /* translators: 1: Required PHP version, 2: Current PHP version */
                __('Migrator requires PHP %1$s or higher. You are running PHP %2$s.', 'plogins-migrator'),
                MIN_PHP_VERSION,
                PHP_VERSION,
            )),
        );
    });
    return;
}

require_once __DIR__ . '/autoload.php';

// Pull and push endpoint for other sites (?reprint-api). Off unless switched on
// under Migrator > Pull and Push; it has to load now, before plugins_loaded.
Reprint\Source::load();

// Declare WooCommerce HPOS compatibility, only fires when WooCommerce is
// present. Migrator backs up custom order tables, so it is HPOS-safe.
add_action('before_woocommerce_init', static function (): void {
    if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
    }
});

add_action('plugins_loaded', static function (): void {
    add_action('init', static function (): void {
        Plugin::instance()->boot();
    }, 0);
}, 10);

// WP-CLI: `wp migrator export` / `import`. Registered early so it is available
// even on a site that is otherwise mid-migration.
if (defined('WP_CLI') && WP_CLI) {
    \WP_CLI::add_command('migrator', Cli\Command::class);
}

// A deactivated plugin cannot answer its own cron event, so the event would
// sit in cron firing into nothing. The schedule itself is kept and re-armed on
// activation; uninstall removes it for good.
register_deactivation_hook(PLUGIN_FILE, static function (): void {
    wp_clear_scheduled_hook('migrator_run_scheduled_backup');
    wp_clear_scheduled_hook('migrator_pro_run_scheduled_backup');
});

register_activation_hook(PLUGIN_FILE, static function (): void {
    require_once PLUGIN_DIR . '/autoload.php';
    Plugin::instance()->container()->get(Support\Workspace::class)->ensure();

    // Put back the event deactivation cleared. The recurrences are registered
    // on init, which has not run for this request yet.
    add_filter('cron_schedules', [Backup\Schedule::class, 'registerRecurrences']);
    Plugin::instance()->container()->get(Backup\Scheduler::class)->reschedule(Backup\Schedule::load());
});
