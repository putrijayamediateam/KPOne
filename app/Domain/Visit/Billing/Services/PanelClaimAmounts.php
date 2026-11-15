<?php

namespace App\Domain\Visit\Billing\Services;

use App\Domain\Visit\Billing\Models\PanelClaim;

/** Numeric measures shared with the existing claim detail; not a new financial ledger. */
final class PanelClaimAmounts
{
    /** @return array{claimed:int,accepted:int,rejected:int,remitted:int,outstanding:int} */
    public static function summary(PanelClaim $claim, int $paid): array
    {
        return ['claimed' => $claim->claim_amount_sen, 'accepted' => $claim->accepted_amount_sen ?? 0, 'rejected' => $claim->status === 'rejected' ? $claim->claim_amount_sen : 0, 'remitted' => $paid, 'outstanding' => max(0, ($claim->accepted_amount_sen ?? 0) - $paid)];
    }
}
