<?php

namespace App\Domain\Shared\Services;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/** Read-only invoice cohort aggregates. Item rankings deliberately remain gross. */
final class InvoiceSalesProjection
{
    public const PAYABLE = 'kpone_invoice_payable(invoices.id)';

    /**
     * BIL-10 (decisions 2026-11-06, A and B): refund credits are their own row, taken off net sales on the day the
     * refund was approved, so past days never change. Pass the credits approved in the same period as the cohort.
     *
     * @return array{grossSen: int, discountsSen: int, surchargesSen: int, refundCreditsSen: int, netSen: int}
     */
    public static function reconciliation(Builder $invoices, int $refundCreditsSen = 0): array
    {
        $payable = self::PAYABLE;
        $row = (clone $invoices)->selectRaw("COALESCE(SUM(invoices.total_sen),0) AS gross, COALESCE(SUM(GREATEST(invoices.total_sen-{$payable},0)),0) AS discounts, COALESCE(SUM(GREATEST({$payable}-invoices.total_sen,0)),0) AS surcharges, COALESCE(SUM({$payable}),0) AS net")->first();

        return ['grossSen' => (int) $row->gross, 'discountsSen' => (int) $row->discounts, 'surchargesSen' => (int) $row->surcharges,
            'refundCreditsSen' => $refundCreditsSen, 'netSen' => (int) $row->net - $refundCreditsSen];
    }

    /**
     * BIL-10: approved invoice credits of one organisation, one row each, as a plain derived table with the columns
     * amount_sen, branch_id, decided_at (UTC approval time) and consultation_checkout_id, so callers scope it by
     * branch and approval period like any invoice query. Waiting and rejected credits are never included.
     */
    public static function approvedCredits(int $organisationId): Builder
    {
        return DB::query()->fromSub(DB::table('invoice_credits as c')
            ->join('payment_refund_decisions as d', 'd.payment_refund_id', '=', 'c.payment_refund_id')
            ->join('invoices as i', 'i.id', '=', 'c.invoice_id')
            ->where('d.decision', 'approved')->where('i.organisation_id', $organisationId)->where('i.status', 'finalized')
            ->select(['c.amount_sen', 'i.branch_id', 'd.decided_at', 'i.consultation_checkout_id']), 'credits');
    }
}
