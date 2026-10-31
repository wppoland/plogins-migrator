<?php

declare(strict_types=1);

namespace Migrator\Backup;

use Migrator\Support\Access;
use Migrator\Contract\HasHooks;
use Migrator\Engine\Export\ExportOptions;
use Migrator\Storage\DestinationRegistry;
use Migrator\Storage\LocalFolderDestination;
use Migrator\Storage\OffsiteSettings;

defined('ABSPATH') || exit;

/**
 * The scheduled-backups feature: a settings screen under the Migrator menu, the
 * cron event that runs the backup, and the glue that keeps the cron registration
 * in step with the saved settings. Capability- and nonce-guarded throughout.
 */
final class Scheduler implements HasHooks
{
    private const SAVE_ACTION = 'migrator_save_schedule';

    private const RUN_ACTION = 'migrator_run_backup_now';

    public const PAGE_SLUG = 'migrator-schedule';

    /** Set while a before-update backup is fresh, so a burst of updates takes one backup. */
    public const UPDATE_LOCK = 'migrator_before_update_backup';

    /** Field types whose stored value is kept when the input is submitted blank. */
    private const SECRET_TYPES = ['password', 'textarea'];

    private string $hookSuffix = '';

    public function __construct(private BackupRunner $runner)
    {
    }

    public function registerHooks(): void
    {
        add_filter('cron_schedules', [Schedule::class, 'registerRecurrences']);
        add_action(Schedule::HOOK, [$this, 'runScheduledBackup']);
        add_action(Schedule::DB_HOOK, [$this, 'runScheduledDatabaseBackup']);

        // A site that scheduled its backup while the add-on owned this feature
        // still has the old event in cron, and it fires with nothing listening.
        // Answer it too, and re-align on the next save.
        add_action(Schedule::LEGACY_HOOK, [$this, 'runScheduledBackup']);

        add_filter('upgrader_pre_download', [$this, 'backupBeforeUpdate'], 10, 4);

        add_action('admin_menu', [$this, 'registerMenu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue']);
        add_action('admin_post_' . self::SAVE_ACTION, [$this, 'handleSave']);
        add_action('admin_post_' . self::RUN_ACTION, [$this, 'handleRunNow']);
    }

    public function registerMenu(): void
    {
        $hook = add_submenu_page(
            'migrator',
            __('Scheduled Backups', 'plogins-migrator'),
            __('Scheduled Backups', 'plogins-migrator'),
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
        wp_enqueue_script(
            'migrator-schedule',
            plugins_url('assets/schedule.js', \Migrator\PLUGIN_FILE),
            [],
            \Migrator\VERSION,
            true,
        );
    }

    public function render(): void
    {
        if (! Access::allowed()) {
            return;
        }

        $schedule     = Schedule::load();
        $last         = $this->runner->lastStatus();
        $archives     = $this->runner->listArchives();
        $nextRun      = wp_next_scheduled(Schedule::HOOK);
        $nextDbRun    = wp_next_scheduled(Schedule::DB_HOOK);
        $lastDb       = $this->runner->lastDatabaseStatus();
        $labels       = $this->exclusionLabels();
        $offsite      = OffsiteSettings::load();
        $destinations = DestinationRegistry::all();

        require \Migrator\PLUGIN_DIR . '/templates/schedule-page.php';
    }

    /**
     * Persist settings and re-align the cron event with them.
     */
    public function handleSave(): void
    {
        if (! Access::allowed()) {
            wp_die(esc_html__('You are not allowed to do this.', 'plogins-migrator'));
        }
        check_admin_referer(self::SAVE_ACTION);

        // phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $post = wp_unslash($_POST);
        // phpcs:enable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

        $exclude    = [];
        $rawExclude = is_array($post['exclude'] ?? null) ? $post['exclude'] : [];
        foreach (ExportOptions::keys() as $key) {
            $exclude[$key] = ! empty($rawExclude[$key]);
        }

        // Re-normalise through fromArray so out-of-range values are clamped.
        $schedule = Schedule::fromArray([
            'enabled'          => ! empty($post['enabled']),
            'frequency'        => isset($post['frequency']) ? sanitize_text_field((string) $post['frequency']) : Schedule::FREQ_DAILY,
            'retention'        => isset($post['retention']) ? (int) $post['retention'] : 5,
            'exclude'          => $exclude,
            'compress'         => ! empty($post['compress']),
            'exclude_patterns' => isset($post['exclude_patterns']) ? sanitize_textarea_field((string) $post['exclude_patterns']) : '',
            'max_file_mb'      => isset($post['max_file_mb']) ? absint($post['max_file_mb']) : 0,
            'before_update'    => ! empty($post['before_update']),
            'notify_failure'   => ! empty($post['notify_failure']),
            'notify_email'     => isset($post['notify_email']) ? sanitize_email((string) $post['notify_email']) : '',
            'db_enabled'       => ! empty($post['db_enabled']),
            'db_frequency'     => isset($post['db_frequency']) ? sanitize_key((string) $post['db_frequency']) : '',
            'db_retention'     => isset($post['db_retention']) ? (int) $post['db_retention'] : 12,
        ]);

        $schedule->save();
        $this->reschedule($schedule);

        $offsite = OffsiteSettings::fromArray([
            'enabled' => ! empty($post['offsite_enabled']),
            'type'    => isset($post['offsite_type']) ? sanitize_key((string) $post['offsite_type']) : '',
            'config'  => $this->readDestinationConfig(is_array($post['dest'] ?? null) ? $post['dest'] : []),
        ]);
        $offsite->save();

        /**
         * Fires after the schedule and its destination have been saved, so an
         * add-on can persist its own settings from the same form submission.
         *
         * @param array<string, mixed> $post     The unslashed request body.
         * @param Schedule             $schedule The schedule just saved.
         */
        do_action('migrator/schedule_saved', $post, $schedule);

        // A folder that cannot be used is saved as typed, so it can be fixed,
        // but the screen says why no copy will be made.
        $folder = new LocalFolderDestination((string) ($offsite->config['local']['folder'] ?? ''));
        if ($offsite->enabled && 'local' === $offsite->type && null !== $folder->problem()) {
            $this->redirectBack('badfolder');
        }

        $this->redirectBack('saved');
    }

    /**
     * Read every registered destination's fields out of the request.
     *
     * Walks the registry rather than naming providers, so an add-on's fields are
     * read by the same code that reads the built-in ones. A blank secret keeps
     * whatever is stored: secrets are never echoed back into the form, so an
     * empty box means "unchanged", not "clear it".
     *
     * @param array<string, mixed> $raw
     *
     * @return array<string, array<string, string>>
     */
    private function readDestinationConfig(array $raw): array
    {
        $stored = OffsiteSettings::load();
        $config = [];

        foreach (DestinationRegistry::all() as $type => $spec) {
            $submitted = is_array($raw[$type] ?? null) ? $raw[$type] : [];

            foreach (($spec['fields'] ?? []) as $field => $meta) {
                $value = isset($submitted[$field]) ? (string) $submitted[$field] : '';
                $kind  = (string) ($meta['type'] ?? 'text');

                if ('' === $value && in_array($kind, self::SECRET_TYPES, true)) {
                    $config[$type][$field] = (string) ($stored->config[$type][$field] ?? '');
                    continue;
                }

                $config[$type][$field] = match ($kind) {
                    // A key block or passphrase must survive verbatim; sanitising
                    // would eat the newlines that make it a valid key.
                    'textarea' => trim($value),
                    'password' => $value,
                    'number'   => (string) absint($value),
                    'checkbox' => '' === $value ? '' : '1',
                    'url'      => esc_url_raw($value),
                    default    => sanitize_text_field($value),
                };
            }
        }

        return $config;
    }

    /**
     * Run a backup immediately (same code path as the cron event).
     */
    public function handleRunNow(): void
    {
        if (! Access::allowed()) {
            wp_die(esc_html__('You are not allowed to do this.', 'plogins-migrator'));
        }
        check_admin_referer(self::RUN_ACTION);

        $status = $this->runner->run(Schedule::load());

        $this->redirectBack(! empty($status['ok']) ? 'ran' : 'failed');
    }

    /**
     * The cron callback. Skips work if the feature was disabled since the event
     * was scheduled (belt and braces alongside reschedule()).
     */
    public function runScheduledBackup(): void
    {
        $schedule = Schedule::load();
        if (! $schedule->enabled) {
            return;
        }

        $this->runAndReport($schedule);
    }

    /**
     * The database-only cron callback, on its own interval.
     */
    public function runScheduledDatabaseBackup(): void
    {
        $schedule = Schedule::load();
        if (! $schedule->dbEnabled) {
            return;
        }

        $status = $this->runner->runDatabase($schedule);

        // The add-on's notifications hear only the full backup, so this one is
        // always mailed from here when the owner asked for failure emails.
        if (empty($status['ok']) && $schedule->notifyFailure) {
            $this->mailFailure($schedule, $status);
        }
    }

    /**
     * Take a rollback point before WordPress replaces plugin, theme or core files.
     *
     * upgrader_pre_download runs before every package is fetched, for manual,
     * bulk and automatic updates alike. New installs and translations carry
     * neither a plugin nor a theme key and are not core, so they pass through.
     * One backup covers a burst of updates: a bulk run, or the Plugins screen,
     * which updates each plugin in its own request.
     *
     * The update is never blocked. A backup that fails is recorded on the
     * Scheduled Backups screen and mailed like any other unattended run.
     *
     * @param mixed                $reply     False to let WordPress download the package.
     * @param mixed                $package
     * @param mixed                $upgrader  The WP_Upgrader doing the work.
     * @param array<string, mixed> $hookExtra
     *
     * @return mixed The reply, unchanged.
     */
    public function backupBeforeUpdate($reply, $package = '', $upgrader = null, $hookExtra = [])
    {
        $hookExtra = is_array($hookExtra) ? $hookExtra : [];
        $isUpdate  = $upgrader instanceof \Core_Upgrader || isset($hookExtra['plugin']) || isset($hookExtra['theme']);

        if (false !== $reply || ! $isUpdate || false !== get_transient(self::UPDATE_LOCK)) {
            return $reply;
        }

        $schedule = Schedule::load();
        if (! $schedule->beforeUpdate) {
            return $reply;
        }

        set_transient(self::UPDATE_LOCK, time(), 10 * MINUTE_IN_SECONDS);

        // A plain full archive: a rollback point that lands as an incremental
        // link or encrypted with a passphrase nobody types at restore is no use.
        $this->runAndReport($schedule, false);

        return $reply;
    }

    /**
     * Run an unattended backup and mail the owner when it fails, if asked to.
     *
     * @return array<string, mixed>
     */
    private function runAndReport(Schedule $schedule, bool $allowStrategies = true): array
    {
        $status = $this->runner->run($schedule, $allowStrategies);

        if (empty($status['ok']) && $schedule->notifyFailure && ! $this->addonMailsFailures()) {
            $this->mailFailure($schedule, $status);
        }

        return $status;
    }

    /**
     * @param array<string, mixed> $status
     */
    private function mailFailure(Schedule $schedule, array $status): void
    {
        $site = wp_specialchars_decode((string) get_option('blogname'), ENT_QUOTES);
        wp_mail(
            '' !== $schedule->notifyEmail ? $schedule->notifyEmail : (string) get_option('admin_email'),
            /* translators: %s: site name. */
            sprintf(__('[%s] Automatic backup failed', 'plogins-migrator'), $site),
            sprintf(
                /* translators: 1: site address, 2: error message, 3: link to the Scheduled Backups screen. */
                __("An automatic backup of %1\$s failed.\n\nError: %2\$s\n\nDetails: %3\$s", 'plogins-migrator'),
                home_url('/'),
                '' !== (string) ($status['message'] ?? '') ? (string) $status['message'] : __('unknown error', 'plogins-migrator'),
                admin_url('admin.php?page=' . self::PAGE_SLUG),
            ),
        );
    }

    /**
     * Whether the paid add-on's notification screen already mails failures,
     * so the owner does not get the same news twice.
     */
    private function addonMailsFailures(): bool
    {
        if (! defined('Migrator\\Pro\\PLUGIN_FILE')) {
            return false;
        }

        $pro = get_option('migrator_pro_notify', []);

        return is_array($pro) && ! empty($pro['enabled']) && (! isset($pro['on_failure']) || ! empty($pro['on_failure']));
    }

    /**
     * Clear any existing event, then schedule a fresh one when enabled. Idempotent.
     */
    public function reschedule(Schedule $schedule): void
    {
        wp_clear_scheduled_hook(Schedule::HOOK);
        wp_clear_scheduled_hook(Schedule::LEGACY_HOOK);
        wp_clear_scheduled_hook(Schedule::DB_HOOK);

        if ($schedule->dbEnabled) {
            wp_schedule_event(time() + MINUTE_IN_SECONDS, $schedule->dbRecurrence(), Schedule::DB_HOOK);
        }

        if (! $schedule->enabled) {
            return;
        }

        wp_schedule_event(time() + MINUTE_IN_SECONDS, $schedule->recurrence(), Schedule::HOOK);
    }

    /**
     * @return array<string, string> ExportOptions flag => human label.
     */
    private function exclusionLabels(): array
    {
        return [
            'no_media'            => __('Media library (uploads)', 'plogins-migrator'),
            'no_themes'           => __('All themes', 'plogins-migrator'),
            'no_inactive_themes'  => __('Inactive themes (keep active only)', 'plogins-migrator'),
            'no_plugins'          => __('All plugins', 'plogins-migrator'),
            'no_inactive_plugins' => __('Inactive plugins (keep active only)', 'plogins-migrator'),
            'no_muplugins'        => __('Must-use plugins', 'plogins-migrator'),
            'no_cache'            => __('Cache files', 'plogins-migrator'),
            'no_spam_comments'    => __('Spam comments', 'plogins-migrator'),
            'no_post_revisions'   => __('Post revisions', 'plogins-migrator'),
            'no_transients'       => __('Transients', 'plogins-migrator'),
            'no_sessions'         => __('WooCommerce sessions', 'plogins-migrator'),
            'no_action_scheduler' => __('Action Scheduler tables', 'plogins-migrator'),
            'no_database'         => __('Database (files-only backup)', 'plogins-migrator'),
            'no_files'            => __('Files (database-only backup)', 'plogins-migrator'),
        ];
    }

    private function redirectBack(string $notice): void
    {
        wp_safe_redirect(add_query_arg(
            ['page' => self::PAGE_SLUG, 'migrator_notice' => $notice],
            admin_url('admin.php'),
        ));
        exit;
    }
}
