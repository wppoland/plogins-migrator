<?php
/**
 * Plugin Name:       Migrator
 * Plugin URI:        https://plogins.com/migrator/
 * Description:        Back up, clone and migrate your WordPress site to a new host. One file, drag and drop, no technical setup.
 * Version:           0.3.0
 * Requires at least: 6.5
 * Requires PHP:      8.1
 * Tested up to:      7.0
 * Author:            WPPoland.com
 * Author URI:        https://wppoland.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       migrator
 * Domain Path:       /languages
 *
 * @package Migrator
 */

declare(strict_types=1);

namespace Migrator;

defined('ABSPATH') || exit;

const VERSION         = '0.3.0';
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
                __('Migrator requires PHP %1$s or higher. You are running PHP %2$s.', 'migrator'),
                MIN_PHP_VERSION,
                PHP_VERSION,
            )),
        );
    });
    return;
}

require_once __DIR__ . '/autoload.php';

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

register_activation_hook(PLUGIN_FILE, static function (): void {
    require_once PLUGIN_DIR . '/autoload.php';
    Plugin::instance()->container()->get(Support\Workspace::class)->ensure();
});
