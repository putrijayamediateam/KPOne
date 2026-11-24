<?php

namespace App\Domain\Visit\Assignment;

/**
 * AUTO-1 (owner, 24 Nov 2026, "Q-G approved"): the Director switches automatic doctor assignment on or off for a branch.
 * Assigning needs no new permission: it is part of registering. Never people or access authority.
 */
final class AutoAssignPermissions
{
    public const MANAGE_BRANCH = 'registration.auto_assign.manage.branch';

    /** @return array<string, list<string>> */
    public static function grants(): array
    {
        return [self::MANAGE_BRANCH => ['director']];
    }
}
