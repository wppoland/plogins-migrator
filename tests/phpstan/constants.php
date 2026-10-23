<?php
/**
 * Constants PHPStan needs to analyse the plugin without bootstrapping WordPress.
 *
 * @package Migrator
 */

declare(strict_types=1);

if (! defined('DB_USER')) {
    define('DB_USER', 'root');
}
if (! defined('DB_NAME')) {
    define('DB_NAME', 'wordpress');
}
if (! defined('DB_PASSWORD')) {
    define('DB_PASSWORD', '');
}
if (! defined('DB_HOST')) {
    define('DB_HOST', 'localhost');
}
if (! defined('WP_CONTENT_DIR')) {
    define('WP_CONTENT_DIR', '/tmp/wordpress/wp-content');
}
