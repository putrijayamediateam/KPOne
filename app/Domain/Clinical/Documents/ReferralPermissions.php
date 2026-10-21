<?php

namespace App\Domain\Clinical\Documents;

final class ReferralPermissions
{
    /** @return array<string, list<string>> */
    public static function grants(): array
    {
        return array_fill_keys(['referrals.issue.own', 'referrals.view.own', 'referrals.print.own'], ['resident_doctor']);
    }
}
