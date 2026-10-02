<?php

declare(strict_types=1);

namespace Migrator\Support;

defined('ABSPATH') || exit;

/**
 * Who may use Migrator.
 *
 * On a single site that is anyone with manage_options. On a network every
 * subsite administrator has manage_options, and a backup holds the shared
 * users table and all of wp-content, every site's files included, while a
 * restore overwrites them. So on multisite it takes a super admin.
 */
final class Access
{
    public static function capability(): string
    {
        return is_multisite() ? 'manage_network_options' : 'manage_options';
    }

    public static function allowed(): bool
    {
        return current_user_can(self::capability());
    }
}
