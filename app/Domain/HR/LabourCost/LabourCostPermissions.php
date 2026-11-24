<?php

namespace App\Domain\HR\LabourCost;

/**
 * HR-18 labour cost (owner, 18 and 20 Nov 2026): the Director, the HR Manager and the Finance Officer see a read-only
 * branch-by-month labour cost from approved pay runs and paid locum months. Aggregates only; never people or access
 * authority.
 */
final class LabourCostPermissions
{
    public const VIEW = 'hr.labour_cost.view.organisation';

    /** @return array<string, list<string>> */
    public static function grants(): array
    {
        return [self::VIEW => ['director', 'hr_manager', 'finance_officer']];
    }
}
