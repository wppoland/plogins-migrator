<?php
/**
 * Boot order: services listed here are resolved from the container and have
 * their registerHooks() called during Plugin::boot(). Each must implement
 * Migrator\Contract\HasHooks.
 *
 * @package Migrator
 *
 * @return array<class-string>
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

return [
    \Migrator\Admin\Page::class,
    \Migrator\Admin\Ajax::class,
    \Migrator\Backup\Scheduler::class,
];
