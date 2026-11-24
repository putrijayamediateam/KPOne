<?php

namespace App\Domain\Visit\Billing\Overview;

use App\Domain\Organisation\Inventory\Models\PurchaseOrder;
use App\Domain\Visit\Models\Visit;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * BIL-31 (design note 2.3, owner answers 3A and 6A): what is waiting for a decision across the whole organisation,
 * counted the same way for every viewer. The Approvals inbox sources leave out the viewer's own items and need the
 * viewer's decide permission and branch; these rules do not, so the overview and the inbox can show different totals.
 * Counts only.
 */
final class OverviewWaitingCounts
{
    /** Kinds counted per branch, in display order. */
    public const BRANCH_KINDS = ['cash_close', 'refund', 'panel_or_pay_later', 'panel_claim_adjustment', 'supplier_invoice'];

    /** Kinds counted once for the organisation. */
    public const ORGANISATION_KINDS = ['purchase_order', 'panel_price_change'];

    /**
     * @param  list<int>  $branchIds
     * @return array{branches: array<int, array<string, int>>, organisation: array<string, int>}
     */
    public function counts(int $organisationId, array $branchIds): array
    {
        $branches = [];
        foreach ($branchIds as $id) {
            $branches[$id] = array_fill_keys(self::BRANCH_KINDS, 0);
        }
        $add = function (string $kind, Builder $query, string $column = 'branch_id') use (&$branches, $branchIds): void {
            foreach ($query->whereIn($column, $branchIds)->select($column.' as branch')->selectRaw('COUNT(*) AS waiting')->groupBy($column)->pluck('waiting', 'branch') as $branchId => $count) {
                $branches[(int) $branchId][$kind] += (int) $count;
            }
        };

        // The latest count of a branch-day with no decision yet (CashCloseSource's rule, for every counter).
        $add('cash_close', DB::table('cash_closings')->where('organisation_id', $organisationId)
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('cash_closing_decisions as d')->whereColumn('d.cash_closing_id', 'cash_closings.id'))
            ->whereRaw('cash_closings.revision = (select max(x.revision) from cash_closings as x where x.branch_id = cash_closings.branch_id and x.business_date = cash_closings.business_date)'));
        // A recorded refund with no approval or rejection yet.
        $add('refund', DB::table('payment_refunds')->where('organisation_id', $organisationId)
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('payment_refund_decisions as d')->whereColumn('d.payment_refund_id', 'payment_refunds.id')));
        // A Panel or pay-later share that can still be approved: proposed on the current finalised invoice, at the
        // invoice version it was proposed on, while the visit is still open. Stale or superseded ones are not counted.
        foreach (['coverage_allocations', 'patient_receivables'] as $table) {
            $add('panel_or_pay_later', DB::table($table.' as p')->where('p.organisation_id', $organisationId)->where('p.status', 'proposed')
                ->whereColumn('p.current_invoice_guard', 'p.invoice_id')
                // Kumar F7: an invoice on correction hold or with stale sources cannot be approved until that clears.
                ->join('invoices as i', 'i.id', '=', 'p.invoice_id')->where('i.status', 'finalized')->whereNull('i.voided_at')
                ->where('i.correction_hold', false)->where('i.source_stale', false)
                ->whereColumn('p.expected_invoice_version', 'i.lock_version')
                ->join('visits as v', 'v.id', '=', 'i.visit_id')->where('v.status', Visit::STATUS_REGISTERED), 'p.branch_id');
        }
        // A Panel claim adjustment requested and not yet confirmed or withdrawn.
        $add('panel_claim_adjustment', DB::table('panel_claim_adjustments')->where('organisation_id', $organisationId)->where('status', 'requested'));
        // A supplier invoice waiting for its match approval (SupplierInvoiceSource's rule, for every recorder).
        $add('supplier_invoice', DB::table('inventory_supplier_invoices')->where('organisation_id', $organisationId)->where('status', 'waiting'));

        return [
            'branches' => $branches,
            'organisation' => [
                'purchase_order' => DB::table('inventory_purchase_orders')->where('organisation_id', $organisationId)->where('status', PurchaseOrder::STATUS_SUBMITTED)->count(),
                'panel_price_change' => DB::table('price_change_proposals')->where('organisation_id', $organisationId)->where('status', 'proposed')->count(),
            ],
        ];
    }
}
