<?php

namespace App\Domain\HR\OpenShifts;

use App\Domain\Access\PermissionCatalogue;

/**
 * HR-15b open shifts (HR-PLAN 21.4; B.E.R.N.I.S.S for the owner, 2026-11-10). The Director and the HR Manager post open
 * shifts and confirm one locum; a locum with a linked sign-in asks for a shift. Neither is people or access authority.
 */
final class OpenShiftPermissions
{
    public const MANAGE = 'hr.open_shifts.manage.organisation';

    public const REQUEST_OWN = 'hr.open_shifts.request.own';

    /** @return array<string, list<string>> */
    public static function grants(): array
    {
        return [self::MANAGE => ['director', 'hr_manager'], self::REQUEST_OWN => [PermissionCatalogue::LOCUM_ROLE]];
    }
}
