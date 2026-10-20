<?php

namespace App\Domain\Visit\Billing\Services;

use App\Domain\Visit\Billing\Models\Payment;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/** Receipt-date cohort shared by terminal reconciliation and aggregate reporting. */
final class PostedReceiptProjection
{
    /** @param list<string> $statuses
     * @return Builder<Payment>
     */
    public static function query(int $organisation, int $branch, CarbonImmutable $start, CarbonImmutable $end, array $statuses = ['posted']): Builder
    {
        return Payment::query()->where('organisation_id', $organisation)->where('branch_id', $branch)
            ->whereIn('status', $statuses)->where('received_at', '>=', $start)->where('received_at', '<', $end);
    }
}
