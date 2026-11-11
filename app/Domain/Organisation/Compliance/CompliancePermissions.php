<?php

namespace App\Domain\Organisation\Compliance;

/**
 * HR-11 clinic compliance (docs/teams/hr/HR-11-DESIGN.md section 8; owner, 2026-11-10: "yes, order is fine, go ahead").
 * Narrow permissions, one additive migration per slice; none is people or access authority, and Technical Admin, the
 * TV account and Marketing get none. HR-11a adds the licences permission, HR-11b the three fridge permissions; the audit
 * permissions come with their own slice.
 */
final class CompliancePermissions
{
    public const LICENCES_MANAGE = 'compliance.licences.manage.organisation';

    public const FRIDGE_MANAGE = 'compliance.fridge.manage.organisation';

    public const FRIDGE_RECORD = 'compliance.fridge.record.branch';

    public const FRIDGE_REVIEW = 'compliance.fridge.review.branch';

    /** @return array<string, list<string>> */
    public static function grants(): array
    {
        return [
            self::LICENCES_MANAGE => ['director', 'administrator', 'hr_manager'],
            self::FRIDGE_MANAGE => ['director', 'administrator'],
            self::FRIDGE_RECORD => ['ca', 'ca_supervisor', 'director', 'administrator'],
            self::FRIDGE_REVIEW => ['ca_supervisor', 'director', 'administrator'],
        ];
    }
}
