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
     * PCA-3: confirmed Panel claim adjustments are their own row too, added on the day they were confirmed.
     *
     * @return array{grossSen: int, discountsSen: int, surchargesSen: int, refundCreditsSen: int, panelAdjustmentsSen: int, netSen: int}
     */
    public static function reconciliation(Builder $invoices, int $refundCreditsSen = 0, int $panelAdjustmentsSen = 0): array
    {
        $payable = self::PAYABLE;
        $row = (clone $invoices)->selectRaw("COALESCE(SUM(invoices.total_sen),0) AS gross, COALESCE(SUM(GREATEST(invoices.total_sen-{$payable},0)),0) AS discounts, COALESCE(SUM(GREATEST({$payable}-invoices.total_sen,0)),0) AS surcharges, COALESCE(SUM({$payable}),0) AS net")->first();

        return ['grossSen' => (int) $row->gross, 'discountsSen' => (int) $row->discounts, 'surchargesSen' => (int) $row->surcharges,
            'refundCreditsSen' => $refundCreditsSen, 'panelAdjustmentsSen' => $panelAdjustmentsSen, 'netSen' => (int) $row->net - $refundCreditsSen + $panelAdjustmentsSen];
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
     * PCA-3 (design note v3.2, section 6): confirmed Panel claim adjustments of one organisation, one row each, signed
     * (a plus is positive), as a plain derived table with amount_sen, invoice_id, branch_id, confirmed_at (UTC), panel_id, panel_name,
     * consultation_checkout_id, dispensary_case_id and doctor_id. Day-story reports count each one on the day it was
     * confirmed, so past days never change. Only adjustments on an approved Panel share of a finalised invoice count:
     * the same rule as kpone_panel_adjustment_sum, so the days add up to each invoice's current adjustment.
     */
    public static function confirmedPanelAdjustments(int $organisationId): Builder
    {
        return DB::query()->fromSub(DB::table('panel_claim_adjustments as a')
            ->join('coverage_allocations as ca', function ($join): void {
                $join->on('ca.id', '=', 'a.coverage_allocation_id')->where('ca.status', 'approved');
            })
            ->join('invoices as i', 'i.id', '=', 'a.invoice_id')
            ->leftJoin('consultation_checkouts as cc', 'cc.id', '=', 'i.consultation_checkout_id')
            ->where('a.status', 'confirmed')->where('a.organisation_id', $organisationId)->where('i.organisation_id', $organisationId)->where('i.status', 'finalized')
            ->selectRaw("CASE a.direction WHEN 'add' THEN a.amount_sen ELSE -a.amount_sen END AS amount_sen")
            ->addSelect(['a.invoice_id', 'a.branch_id', 'a.confirmed_at', 'ca.panel_id', 'ca.panel_name_snapshot as panel_name', 'i.consultation_checkout_id', 'i.dispensary_case_id', 'cc.attending_doctor_user_id as doctor_id']), 'panel_adjustments');
    }

    /**
     * PCA-3: the offsets that move net sales after the invoice day, as one credit-shaped table (amount taken off,
     * by its own date): approved refund credits as they are, and confirmed Panel claim adjustments with the sign
     * turned (a plus adds to sales). Callers scope it with the column `decided_at` like approvedCredits().
     */
    public static function netOffsets(int $organisationId): Builder
    {
        $credits = DB::query()->fromSub(self::approvedCredits($organisationId), 'c')
            ->select(['amount_sen', 'branch_id', 'decided_at', 'consultation_checkout_id', 'dispensary_case_id', 'doctor_id']);
        $adjustments = DB::query()->fromSub(self::confirmedPanelAdjustments($organisationId), 'p')
            ->selectRaw('-amount_sen AS amount_sen, branch_id, confirmed_at AS decided_at, consultation_checkout_id, dispensary_case_id, doctor_id');

        return DB::query()->fromSub($credits->unionAll($adjustments), 'offsets');
    }

    /**
     * BIL-12 (owner decision 2026-11-07): each approved credit comes off the provider row of the invoice it belongs
     * to, on its approval day: a consultation invoice's attending doctor, or the OTC row. Rows then add up to the
     * headline net sales. A provider with credits but no invoices in the period still appears, with 0 patients,
     * unless the doctor list was cut at its cap: then a doctor below the cut is not re-added with only their credit
     * (their sales are not in the list either), and the caller's truncated flag explains why rows do not add up.
     *
     * @param  int  $organisationId  the actor's organisation; the doctor name lookup stays inside it
     * @param  array<int, array{id: int, name: string, salesSen: int, patients: int}>  $doctors  invoice sales by doctor
     * @param  Builder  $credits  approvedCredits() already scoped to the same branches, period and doctor filter
     * @param  array{name: string, salesSen: int, patients: int, fixed: true}|null  $otc  the OTC row, when shown
     * @param  bool  $truncated  the doctor list was cut at its cap before credits
     * @return array{doctors: list<array{name: string, salesSen: int, patients: int}>, otc: array{name: string, salesSen: int, patients: int, fixed: true}|null}
     */
    public static function withCredits(int $organisationId, array $doctors, Builder $credits, ?array $otc, bool $showOtc, bool $truncated = false): array
    {
        $byDoctor = (clone $credits)->whereNotNull('doctor_id')->selectRaw('doctor_id, SUM(amount_sen) AS amount')->groupBy('doctor_id')->pluck('amount', 'doctor_id');
        $rows = collect($doctors)->keyBy('id');
        $missing = $byDoctor->keys()->map(fn ($id): int => (int) $id)->diff($rows->keys()->all());
        if ($missing->isNotEmpty() && ! $truncated) {
            foreach (DB::table('users')->where('organisation_id', $organisationId)->whereIn('id', $missing->all())->get(['id', 'name']) as $user) {
                $rows->put((int) $user->id, ['id' => (int) $user->id, 'name' => (string) $user->name, 'salesSen' => 0, 'patients' => 0]);
            }
        }
        $rows = $rows->map(fn (array $row): array => [...$row, 'salesSen' => $row['salesSen'] - (int) ($byDoctor[$row['id']] ?? 0)])
            ->sortBy([['salesSen', 'desc'], ['name', 'asc']])->values()
            ->map(fn (array $row): array => ['name' => $row['name'], 'salesSen' => $row['salesSen'], 'patients' => $row['patients']])->values()->all();
        if ($showOtc) {
            $otcCredit = (int) (clone $credits)->whereNotNull('dispensary_case_id')->sum('amount_sen');
            if ($otcCredit !== 0) {
                $otc = $otc === null ? ['name' => OtcProviderRow::LABEL, 'salesSen' => -$otcCredit, 'patients' => 0, 'fixed' => true]
                    : [...$otc, 'salesSen' => $otc['salesSen'] - $otcCredit];
            }
        }

        return ['doctors' => array_values($rows), 'otc' => $showOtc ? $otc : null];
    }
}
