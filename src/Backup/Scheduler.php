<?php

declare(strict_types=1);

namespace Migrator\Backup;

use Migrator\Engine\Export\ExportOptions;
use Migrator\Storage\DestinationRegistry;
use Migrator\Storage\OffsiteSettings;

defined('ABSPATH') || exit;

/**
 * The scheduled-backups feature: a settings screen under the Migrator menu, the
 * cron event that runs the backup, and the glue that keeps the cron registration
 * in step with the saved settings. Capability- and nonce-guarded throughout.
 */
final class Scheduler
{
    private const SAVE_ACTION = 'migrator_save_schedule';

    private const RUN_ACTION = 'migrator_run_backup_now';

    public const PAGE_SLUG = 'migrator-schedule';

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

        // A site that scheduled its backup while the add-on owned this feature
        // still has the old event in cron, and it fires with nothing listening.
        // Answer it too, and re-align on the next save.
        add_action(Schedule::LEGACY_HOOK, [$this, 'runScheduledBackup']);

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
            'manage_options',
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
        if (! current_user_can('manage_options')) {
            return;
        }

        $schedule     = Schedule::load();
        $last         = $this->runner->lastStatus();
        $archives     = $this->runner->listArchives();
        $nextRun      = wp_next_scheduled(Schedule::HOOK);
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
        if (! current_user_can('manage_options')) {
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
            'enabled'   => ! empty($post['enabled']),
            'frequency' => isset($post['frequency']) ? sanitize_text_field((string) $post['frequency']) : Schedule::FREQ_DAILY,
            'retention' => isset($post['retention']) ? (int) $post['retention'] : 5,
            'exclude'   => $exclude,
            'compress'  => ! empty($post['compress']),
        ]);

        $schedule->save();
        $this->reschedule($schedule);

        OffsiteSettings::fromArray([
            'enabled' => ! empty($post['offsite_enabled']),
            'type'    => isset($post['offsite_type']) ? sanitize_key((string) $post['offsite_type']) : '',
            'config'  => $this->readDestinationConfig(is_array($post['dest'] ?? null) ? $post['dest'] : []),
        ])->save();

        /**
         * Fires after the schedule and its destination have been saved, so an
         * add-on can persist its own settings from the same form submission.
         *
         * @param array<string, mixed> $post     The unslashed request body.
         * @param Schedule             $schedule The schedule just saved.
         */
        do_action('migrator/schedule_saved', $post, $schedule);

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
        if (! current_user_can('manage_options')) {
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

        $this->runner->run($schedule);
    }

    /**
     * Clear any existing event, then schedule a fresh one when enabled. Idempotent.
     */
    public function reschedule(Schedule $schedule): void
    {
        wp_clear_scheduled_hook(Schedule::HOOK);
        wp_clear_scheduled_hook(Schedule::LEGACY_HOOK);

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
