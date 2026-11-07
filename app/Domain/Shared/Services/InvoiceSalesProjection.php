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
            ->leftJoin('consultation_checkouts as cc', 'cc.id', '=', 'i.consultation_checkout_id')
            ->where('d.decision', 'approved')->where('i.organisation_id', $organisationId)->where('i.status', 'finalized')
            ->select(['c.amount_sen', 'i.branch_id', 'd.decided_at', 'i.consultation_checkout_id', 'i.dispensary_case_id', 'cc.attending_doctor_user_id as doctor_id']), 'credits');
    }

    /**
     * BIL-12 (owner decision 2026-11-07): each approved credit comes off the provider row of the invoice it belongs
     * to, on its approval day: a consultation invoice's attending doctor, or the OTC row. Rows then add up to the
     * headline net sales. A provider with credits but no invoices in the period still appears, with 0 patients.
     *
     * @param  list<array{id: int, name: string, salesSen: int, patients: int}>  $doctors  invoice sales by doctor
     * @param  Builder  $credits  approvedCredits() already scoped to the same branches, period and doctor filter
     * @param  array{name: string, salesSen: int, patients: int, fixed: true}|null  $otc  the OTC row, when shown
     * @return array{doctors: list<array{name: string, salesSen: int, patients: int}>, otc: array{name: string, salesSen: int, patients: int, fixed: true}|null}
     */
    public static function withCredits(array $doctors, Builder $credits, ?array $otc, bool $showOtc): array
    {
        $byDoctor = (clone $credits)->whereNotNull('doctor_id')->selectRaw('doctor_id, SUM(amount_sen) AS amount')->groupBy('doctor_id')->pluck('amount', 'doctor_id');
        $rows = collect($doctors)->keyBy('id');
        $missing = $byDoctor->keys()->map(fn ($id): int => (int) $id)->diff($rows->keys()->all());
        if ($missing->isNotEmpty()) {
            foreach (DB::table('users')->whereIn('id', $missing->all())->get(['id', 'name']) as $user) {
                $rows->put((int) $user->id, ['id' => (int) $user->id, 'name' => (string) $user->name, 'salesSen' => 0, 'patients' => 0]);
            }
        }
        $rows = $rows->map(fn (array $row): array => [...$row, 'salesSen' => $row['salesSen'] - (int) ($byDoctor[$row['id']] ?? 0)])
            ->sortBy([['salesSen', 'desc'], ['name', 'asc']])->values()
            ->map(fn (array $row): array => ['name' => $row['name'], 'salesSen' => $row['salesSen'], 'patients' => $row['patients']])->all();
        if ($showOtc) {
            $otcCredit = (int) (clone $credits)->whereNotNull('dispensary_case_id')->sum('amount_sen');
            if ($otcCredit !== 0) {
                $otc = $otc === null ? ['name' => OtcProviderRow::LABEL, 'salesSen' => -$otcCredit, 'patients' => 0, 'fixed' => true]
                    : [...$otc, 'salesSen' => $otc['salesSen'] - $otcCredit];
            }
        }

        return ['doctors' => $rows, 'otc' => $showOtc ? $otc : null];
    }
}
