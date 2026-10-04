<?php

declare(strict_types=1);

namespace Migrator\Reprint;

defined('ABSPATH') || exit;

/**
 * A new signing key was made for a remote and has to be enrolled there before
 * the pull can run. Not a failure: the command prints the key and stops.
 */
final class EnrolKey extends \RuntimeException
{
    public function __construct(public readonly string $publicKey)
    {
        parent::__construct('A key for this source was created and has to be enrolled there first.');
    }
}
