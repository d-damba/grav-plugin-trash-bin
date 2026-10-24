<?php

declare(strict_types=1);

namespace Grav\Plugin\TrashBin;

/**
 * A restore that cannot go back where it came from.
 *
 * $reason is one of `location_taken`, `parent_missing` or `parent_in_trash`;
 * the admin page picks what to offer from it.
 */
final class TrashConflict extends \RuntimeException
{
    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }
}
