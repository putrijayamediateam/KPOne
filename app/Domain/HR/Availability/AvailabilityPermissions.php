<?php

namespace App\Domain\HR\Availability;

use App\Domain\Access\PermissionCatalogue;

/**
 * HR-22f locum availability (HR-PLAN 29.7; new permission approved in principle by the owner, via B.E.R.N.I.S.S, 24 Nov
 * 2026). A locum with a linked sign-in proposes the times they are free; the Director or HR Manager uses or declines a
 * proposal with the existing roster permission. Not people or access authority.
 */
final class AvailabilityPermissions
{
    public const PROPOSE_OWN = 'hr.locum_availability.propose.own';

    /** @return array<string, list<string>> */
    public static function grants(): array
    {
        return [self::PROPOSE_OWN => [PermissionCatalogue::LOCUM_ROLE]];
    }
}
