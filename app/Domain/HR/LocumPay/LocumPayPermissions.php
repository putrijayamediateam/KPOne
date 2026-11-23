<?php

namespace App\Domain\HR\LocumPay;

use App\Domain\Access\PermissionCatalogue;

/**
 * HR-15g locum pay statement (owner, 18 Nov 2026). A locum sees their own statement. The Director and the HR Manager
 * enter rates, mark shifts not worked and add corrections. The Finance Officer and the Director confirm a rate (never one
 * they entered) and record that a month was paid outside KPOne. KPOne pays nothing. None of these is people or access
 * authority.
 */
final class LocumPayPermissions
{
    public const VIEW_OWN = 'hr.locum_pay.view.own';

    public const MANAGE = 'hr.locum_pay.manage.organisation';

    public const FINANCE = 'hr.locum_pay.finance.organisation';

    /** @return array<string, list<string>> */
    public static function grants(): array
    {
        return [
            self::VIEW_OWN => [PermissionCatalogue::LOCUM_ROLE],
            self::MANAGE => ['director', 'hr_manager'],
            self::FINANCE => ['finance_officer', 'director'],
        ];
    }
}
