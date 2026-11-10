<?php

namespace App\Domain\Organisation\Compliance;

/**
 * HR-11 clinic compliance (docs/teams/hr/HR-11-DESIGN.md section 8; owner, 2026-11-10: "yes, order is fine, go ahead").
 * Narrow permissions, one additive migration per slice; none is people or access authority, and Technical Admin, the
 * TV account and Marketing get none. HR-11a adds the licences permission only; the fridge and audit permissions come
 * with their own slices.
 */
final class CompliancePermissions
{
    public const LICENCES_MANAGE = 'compliance.licences.manage.organisation';

    /** @return array<string, list<string>> */
    public static function grants(): array
    {
        return [self::LICENCES_MANAGE => ['director', 'administrator', 'hr_manager']];
    }
}
