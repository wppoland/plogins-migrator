<?php

declare(strict_types=1);

namespace Migrator\Storage;

defined('ABSPATH') || exit;

/**
 * Every place a finished backup can be copied to, and the fields each one needs.
 *
 * The free plugin ships the two destinations that require nothing beyond PHP
 * itself: a folder on (or mounted into) the server, and FTP/FTPS via ext-ftp.
 * Anything needing a signed HTTP API or a vendored crypto library registers
 * itself through the `migrator/backup_destinations` filter instead.
 *
 * The admin screen renders whatever this returns, so the free plugin never
 * names a destination it cannot provide. A capability the user cannot reach is
 * not advertised to them; it simply is not there.
 */
final class DestinationRegistry
{
    /**
     * @return array<string, array{
     *     label: string,
     *     available: bool,
     *     requirement: string,
     *     fields: array<string, array{label: string, type: string, default?: string}>,
     *     factory: callable(array<string, string>): BackupDestination
     * }>
     */
    public static function all(): array
    {
        $types = [
            'local' => [
                'label'       => __('Folder on this server', 'plogins-migrator'),
                'available'   => true,
                'requirement' => '',
                'fields'      => [
                    'folder' => [
                        'label' => __('Absolute path', 'plogins-migrator'),
                        'type'  => 'text',
                    ],
                ],
                'factory' => static fn (array $c): BackupDestination => new LocalFolderDestination($c['folder'] ?? ''),
            ],
            'ftp' => [
                'label'       => __('FTP or FTPS', 'plogins-migrator'),
                'available'   => FtpDestination::available(),
                'requirement' => __('Requires the PHP FTP extension, which this server does not have.', 'plogins-migrator'),
                'fields'      => [
                    'host'     => ['label' => __('Host', 'plogins-migrator'), 'type' => 'text'],
                    'port'     => ['label' => __('Port', 'plogins-migrator'), 'type' => 'number', 'default' => '21'],
                    'username' => ['label' => __('Username', 'plogins-migrator'), 'type' => 'text'],
                    'password' => ['label' => __('Password', 'plogins-migrator'), 'type' => 'password'],
                    'path'     => ['label' => __('Remote directory', 'plogins-migrator'), 'type' => 'text'],
                    'passive'  => ['label' => __('Passive mode', 'plogins-migrator'), 'type' => 'checkbox', 'default' => '1'],
                    'secure'   => ['label' => __('Use FTPS (explicit TLS)', 'plogins-migrator'), 'type' => 'checkbox'],
                ],
                'factory' => static fn (array $c): BackupDestination => new FtpDestination(
                    $c['host'] ?? '',
                    (int) ($c['port'] ?? '21'),
                    $c['username'] ?? '',
                    $c['password'] ?? '',
                    $c['path'] ?? '',
                    // Passive on unless explicitly turned off: the stored value is
                    // absent on a fresh install and '' when the box is unticked, and
                    // only the second of those means "off".
                    ! array_key_exists('passive', $c) || '' !== $c['passive'],
                    ! empty($c['secure']),
                ),
            ],
        ];

        /**
         * Register additional backup destinations.
         *
         * @param array<string, array<string, mixed>> $types Keyed by machine id.
         */
        $filtered = apply_filters('migrator/backup_destinations', $types);

        return is_array($filtered) ? $filtered : $types;
    }

    /**
     * Build the destination for a type, or null when the type is unknown or the
     * configuration is incomplete.
     *
     * @param array<string, string> $config
     */
    public static function make(string $type, array $config): ?BackupDestination
    {
        $types = self::all();

        if (! isset($types[$type]) || ! is_callable($types[$type]['factory'] ?? null)) {
            return null;
        }

        $destination = ($types[$type]['factory'])($config);

        return $destination instanceof BackupDestination && $destination->isConfigured()
            ? $destination
            : null;
    }
}
