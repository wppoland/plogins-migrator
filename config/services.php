<?php
/**
 * Service wiring. Returns a closure that registers every service in the
 * container. Keep factories thin: they construct, they do not do work.
 *
 * @package Migrator
 */

declare(strict_types=1);

use Migrator\Admin\Ajax;
use Migrator\Backup\BackupRunner;
use Migrator\Backup\Scheduler;
use Migrator\Admin\Page;
use Migrator\Container;
use Migrator\Engine\Db\Dumper;
use Migrator\Engine\Export\ExportPipeline;
use Migrator\Support\Workspace;

defined('ABSPATH') || exit;

return static function (Container $c): void {
    $c->singleton(Workspace::class, static fn (): Workspace => new Workspace());

    $c->singleton(ExportPipeline::class, static function (Container $c): ExportPipeline {
        global $wpdb;

        return new ExportPipeline($c->get(Workspace::class), new Dumper($wpdb));
    });

    $c->singleton(Page::class, static fn (): Page => new Page());

    $c->singleton(Ajax::class, static fn (Container $c): Ajax => new Ajax(
        $c->get(ExportPipeline::class),
        $c->get(Workspace::class),
    ));

    $c->singleton(BackupRunner::class, static fn (Container $c): BackupRunner => new BackupRunner(
        $c->get(Workspace::class),
    ));

    $c->singleton(Scheduler::class, static fn (Container $c): Scheduler => new Scheduler(
        $c->get(BackupRunner::class),
    ));
};
