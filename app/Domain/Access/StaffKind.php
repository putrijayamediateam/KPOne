<?php

namespace App\Domain\Access;

use App\Models\User;

/**
 * HR-22a and HR-22b (owner, via B.E.R.N.I.S.S, 24 Nov 2026): a locum doctor's account holds both `resident_doctor` and
 * `locum`, so "is this a locum" is read from the `locum` role being present, never from the absence of another role.
 * Screens and services ask here instead of reading role names themselves.
 */
final class StaffKind
{
    public const DOCTOR_ROLE = 'resident_doctor';

    /**
     * The only role sets a locum account may hold (HR-15a; HR-22b, the owner's role decision under AGENTS.md, 24 Nov 2026),
     * keyed by what HR asks for on the sign-in request.
     */
    public const LOCUM_ROLE_SETS = [
        'locum' => [PermissionCatalogue::LOCUM_ROLE],
        'locum_doctor' => [PermissionCatalogue::LOCUM_ROLE, self::DOCTOR_ROLE],
    ];

    public static function isLocum(User $user): bool
    {
        return $user->hasRole(PermissionCatalogue::LOCUM_ROLE);
    }

    public static function isDoctor(User $user): bool
    {
        return $user->hasRole(self::DOCTOR_ROLE);
    }

    /** @param list<string> $roles */
    public static function rolesIncludeLocum(array $roles): bool
    {
        return in_array(PermissionCatalogue::LOCUM_ROLE, $roles, true);
    }

    /** @param list<string> $roles */
    public static function rolesIncludeDoctor(array $roles): bool
    {
        return in_array(self::DOCTOR_ROLE, $roles, true);
    }
}
