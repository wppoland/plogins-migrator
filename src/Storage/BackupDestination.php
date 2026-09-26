<?php

declare(strict_types=1);

namespace Migrator\Storage;

defined('ABSPATH') || exit;

/**
 * An off-site place a finished backup is copied to. The local-folder adapter is
 * the first implementation; cloud adapters (S3, R2, Dropbox, ...) implement the
 * same contract so the scheduler never needs to know where a backup goes.
 */
interface BackupDestination
{
    /** Stable machine id, e.g. "local-folder". */
    public function id(): string;

    /** Human label for the admin UI. */
    public function label(): string;

    /** Whether this destination has everything it needs to receive a backup. */
    public function isConfigured(): bool;

    /**
     * Copy a finished archive to the destination.
     *
     * @throws \RuntimeException If the transfer fails.
     */
    public function store(string $archivePath): void;

    /**
     * Keep the newest $retention archives at the destination, remove the rest.
     *
     * @return int How many archives remain after pruning.
     */
    public function prune(int $retention): int;

    /**
     * Archives currently at the destination, newest first.
     *
     * @return array<int, array{file: string, bytes: int, time: int}>
     */
    public function archives(): array;
}
