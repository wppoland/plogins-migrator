<?php

declare(strict_types=1);

namespace Migrator\Backup;

use Migrator\Engine\Export\ExportOptions;

defined('ABSPATH') || exit;

/**
 * The user's scheduled-backup settings, persisted in one option, plus the cron
 * recurrence names this plugin registers. A plain, validated value object so the
 * UI, the scheduler and the runner all agree on the shape of the data.
 *
 * Only what the free plugin can actually act on lives here. Settings that belong
 * to an add-on (a backup mode it implements, a passphrase for encryption it
 * provides) are that add-on's to store, so this class never carries a field the
 * plugin holding it cannot honour.
 */
final class Schedule
{
    public const OPTION = 'migrator_schedule';

    /** The pre-split option, read once so an existing schedule keeps working. */
    private const LEGACY_OPTION = 'migrator_pro_schedule';

    public const HOOK = 'migrator_run_scheduled_backup';

    /** The hook the paid add-on used before scheduling moved here. */
    public const LEGACY_HOOK = 'migrator_pro_run_scheduled_backup';

    /** Marker in a scheduled archive's filename, so retention only prunes its own backups. */
    public const MARKER = 'scheduled';

    public const FREQ_DAILY = 'daily';

    public const FREQ_WEEKLY = 'weekly';

    /** Custom cron recurrences, so the interval never depends on which ones core ships. */
    public const RECURRENCE = [
        self::FREQ_DAILY  => 'migrator_daily',
        self::FREQ_WEEKLY => 'migrator_weekly',
    ];

    private const INTERVAL = [
        'migrator_daily'  => DAY_IN_SECONDS,
        'migrator_weekly' => WEEK_IN_SECONDS,
    ];

    /**
     * @param array<string, bool> $exclude ExportOptions flags to leave out of scheduled backups.
     */
    public function __construct(
        public readonly bool $enabled,
        public readonly string $frequency,
        public readonly int $retention,
        public readonly array $exclude,
        public readonly bool $compress = false,
    ) {
    }

    public static function load(): self
    {
        $raw = get_option(self::OPTION, null);

        if (! is_array($raw)) {
            $legacy = get_option(self::LEGACY_OPTION, null);
            $raw    = is_array($legacy) ? $legacy : [];
        }

        return self::fromArray($raw);
    }

    /**
     * @param array<string, mixed> $raw
     */
    public static function fromArray(array $raw): self
    {
        $frequency = in_array($raw['frequency'] ?? '', [self::FREQ_DAILY, self::FREQ_WEEKLY], true)
            ? (string) $raw['frequency']
            : self::FREQ_DAILY;

        $retention = isset($raw['retention']) ? (int) $raw['retention'] : 5;
        $retention = max(1, min(60, $retention));

        $exclude    = [];
        $rawExclude = is_array($raw['exclude'] ?? null) ? $raw['exclude'] : [];
        foreach (ExportOptions::keys() as $key) {
            $exclude[$key] = ! empty($rawExclude[$key]);
        }

        return new self(
            ! empty($raw['enabled']),
            $frequency,
            $retention,
            $exclude,
            ! empty($raw['compress']),
        );
    }

    public function save(): void
    {
        update_option(self::OPTION, [
            'enabled'   => $this->enabled,
            'frequency' => $this->frequency,
            'retention' => $this->retention,
            'exclude'   => $this->exclude,
            'compress'  => $this->compress,
        ], false);
    }

    public function recurrence(): string
    {
        return self::RECURRENCE[$this->frequency] ?? self::RECURRENCE[self::FREQ_DAILY];
    }

    public function options(): ExportOptions
    {
        return new ExportOptions($this->exclude);
    }

    /**
     * Cron recurrences to register via the cron_schedules filter.
     *
     * @param array<string, array{interval: int, display: string}> $schedules
     * @return array<string, array{interval: int, display: string}>
     */
    public static function registerRecurrences(array $schedules): array
    {
        $schedules[self::RECURRENCE[self::FREQ_DAILY]] = [
            'interval' => self::INTERVAL['migrator_daily'],
            'display'  => __('Once a day (Migrator)', 'plogins-migrator'),
        ];
        $schedules[self::RECURRENCE[self::FREQ_WEEKLY]] = [
            'interval' => self::INTERVAL['migrator_weekly'],
            'display'  => __('Once a week (Migrator)', 'plogins-migrator'),
        ];

        return $schedules;
    }
}
