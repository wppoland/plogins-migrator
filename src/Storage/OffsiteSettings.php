<?php

declare(strict_types=1);

namespace Migrator\Storage;

defined('ABSPATH') || exit;

/**
 * Where, if anywhere, finished backups are copied off the server.
 *
 * Deliberately generic: one selected type plus a bag of configuration per type,
 * resolved through {@see DestinationRegistry}. The previous shape named every
 * provider as its own typed property, which meant the class had to know about
 * destinations it might not ship, and adding one meant editing this file.
 */
final class OffsiteSettings
{
    public const OPTION = 'migrator_offsite';

    /** The pre-split option, read once so an existing schedule keeps working. */
    private const LEGACY_OPTION = 'migrator_pro_offsite';

    /**
     * @param array<string, array<string, string>> $config Keyed by destination type.
     */
    public function __construct(
        public readonly bool $enabled,
        public readonly string $type,
        public readonly array $config,
    ) {
    }

    public static function load(): self
    {
        $raw = get_option(self::OPTION, null);

        if (! is_array($raw)) {
            $legacy = get_option(self::LEGACY_OPTION, null);

            return is_array($legacy) ? self::fromLegacy($legacy) : self::fromArray([]);
        }

        return self::fromArray($raw);
    }

    /**
     * @param array<string, mixed> $raw
     */
    public static function fromArray(array $raw): self
    {
        $types = DestinationRegistry::all();
        $type  = isset($raw['type']) && isset($types[(string) $raw['type']])
            ? (string) $raw['type']
            : (string) array_key_first($types);

        $config = [];
        foreach ($types as $id => $spec) {
            $stored = is_array($raw['config'][$id] ?? null) ? $raw['config'][$id] : [];
            foreach (array_keys($spec['fields'] ?? []) as $field) {
                $config[$id][$field] = isset($stored[$field]) ? trim((string) $stored[$field]) : '';
            }
        }

        return new self(! empty($raw['enabled']), $type, $config);
    }

    /**
     * Carry a pre-split configuration across.
     *
     * The old option kept one array per provider at the top level (`s3`,
     * `dropbox`, `ftp`, ...) plus a bare `folder` string for the local
     * destination. Both shapes map onto `config[<type>]` unchanged apart from
     * the local folder, which becomes a normal field.
     *
     * @param array<string, mixed> $legacy
     */
    private static function fromLegacy(array $legacy): self
    {
        $normalised = [
            'enabled' => ! empty($legacy['enabled']),
            'type'    => (string) ($legacy['type'] ?? 'local'),
            'config'  => [],
        ];

        foreach ($legacy as $key => $value) {
            if (is_array($value)) {
                $normalised['config'][$key] = $value;
            }
        }

        if (isset($legacy['folder']) && '' !== (string) $legacy['folder']) {
            $normalised['config']['local']['folder'] = (string) $legacy['folder'];
        }

        return self::fromArray($normalised);
    }

    public function save(): void
    {
        update_option(self::OPTION, [
            'enabled' => $this->enabled,
            'type'    => $this->type,
            'config'  => $this->config,
        ], false);
    }

    /**
     * The configured destination, or null when off-site copy is disabled or the
     * selected destination is not fully configured.
     */
    public function destination(): ?BackupDestination
    {
        if (! $this->enabled) {
            return null;
        }

        return DestinationRegistry::make($this->type, $this->config[$this->type] ?? []);
    }
}
