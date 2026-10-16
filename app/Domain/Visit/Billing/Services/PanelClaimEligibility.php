<?php

namespace App\Domain\Visit\Billing\Services;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/** Shared query only: callers must enforce actor authority and active branch scope. */
final class PanelClaimEligibility
{
    public function query(int $organisationId, int $branchId): Builder
    {
        return DB::table('coverage_allocations as c')
            ->join('invoices as i', 'i.id', '=', 'c.invoice_id')
            ->join('visits as v', 'v.id', '=', 'i.visit_id')
            ->join('patients as p', 'p.id', '=', 'i.patient_id')
            ->join('panels as pn', function ($join): void {
                $join->on('pn.id', '=', 'c.panel_id')->on('pn.organisation_id', '=', 'c.organisation_id');
            })
            ->leftJoin('users as d', function ($join): void {
                $join->on('d.id', '=', 'v.assigned_doctor_user_id')->on('d.organisation_id', '=', 'v.organisation_id');
            })
            ->where('c.organisation_id', $organisationId)
            ->where('c.branch_id', $branchId)
            ->where('c.status', 'approved')
            ->whereColumn('c.current_invoice_guard', 'i.id')
            ->whereColumn('c.invoice_id', 'i.id')
            ->whereColumn('i.organisation_id', 'c.organisation_id')
            ->whereColumn('i.branch_id', 'c.branch_id')
            ->whereColumn('v.organisation_id', 'i.organisation_id')
            ->whereColumn('v.branch_id', 'i.branch_id')
            ->whereColumn('p.organisation_id', 'i.organisation_id')
            ->whereColumn('p.id', 'i.patient_id')
            ->where('i.status', 'finalized')
            ->where('i.correction_hold', false)
            ->where('i.source_stale', false)
            ->whereRaw('c.amount_sen <= kpone_invoice_payable(i.id)')
            ->whereNotExists(fn (Builder $existing) => $existing->selectRaw('1')
                ->from('panel_claims')
                ->whereColumn('panel_claims.coverage_allocation_id', 'c.id'));
    }
}
