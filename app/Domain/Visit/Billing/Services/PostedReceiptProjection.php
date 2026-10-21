<?php

namespace App\Domain\Visit\Billing\Services;

use App\Domain\Visit\Billing\Models\Payment;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

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

    /** @param Builder<Payment> $query
     * @param  list<string>  $visitTypes
     * @return Builder<Payment>
     */
    public static function filterVisitTypes(Builder $query, int $organisation, array $visitTypes): Builder
    {
        if ($visitTypes !== []) {
            $query->whereExists(fn (QueryBuilder $allocation) => $allocation->selectRaw('1')->from('payment_allocations')
                ->join('invoices', 'invoices.id', '=', 'payment_allocations.invoice_id')
                ->join('visits', 'visits.id', '=', 'invoices.visit_id')
                ->whereColumn('payment_allocations.payment_id', 'payments.id')
                ->where('invoices.organisation_id', $organisation)->whereIn('visits.visit_type', $visitTypes));
        }

        return $query;
    }

    /** @return array{receiptsSen:int,reversedSen:int,netSen:int} */
    public static function amounts(int $amount, string $status): array
    {
        if (! in_array($status, ['posted', 'reversed'], true)) {
            throw new \LogicException('Unsupported receipt state.');
        }
        $reversed = $status === 'reversed' ? $amount : 0;

        return ['receiptsSen' => $amount, 'reversedSen' => $reversed, 'netSen' => $amount - $reversed];
    }
}
