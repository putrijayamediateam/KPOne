<?php

namespace App\Domain\HR\Sops;

use App\Domain\HR\Roster\RosterPermissions;

/**
 * HR-5c SOPs (owner, 2026-11-06: "approve the HR-5 note with the defaults"). Director and HR Manager publish, replace
 * and withdraw SOPs and see who has not acknowledged; every human staff member reads and acknowledges the SOPs
 * published to their roles. The TV account gets nothing. Neither is people or access authority.
 */
final class SopPermissions
{
    public const ACKNOWLEDGE_OWN = 'hr.sops.acknowledge.own';

    public const MANAGE = 'hr.sops.manage.organisation';

    /** @return array<string, list<string>> */
    public static function grants(): array
    {
        return [self::ACKNOWLEDGE_OWN => RosterPermissions::HUMAN_ROLES, self::MANAGE => ['director', 'hr_manager']];
    }
}
