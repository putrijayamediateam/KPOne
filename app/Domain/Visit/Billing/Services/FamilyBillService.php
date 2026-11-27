<?php

namespace App\Domain\Visit\Billing\Services;

use App\Domain\Access\BranchAccessService;
use App\Domain\Visit\Billing\Models\Invoice;
use App\Domain\Visit\Billing\Models\InvoiceLine;
use App\Domain\Visit\Models\Visit;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * BIL-39: the family bill. A read-only view of the open, finalised invoices of the visits that share the anchor visit's
 * visits.family_group_id (written only by Patients & Registration, FAM-1) at the actor's active branch. Each member keeps
 * their own invoice, coverage and receipts; nothing here changes money. Billing never writes family_group_id.
 */
final class FamilyBillService
{
    public function __construct(
        private BillingContextService $context,
        private FinancialLedger $ledger,
        private BranchDayGate $gate,
    ) {}

    /**
     * The family bill seen from one member's visit, or null when the visit has no family group.
     *
     * @return array<string, mixed>|null
     */
    public function view(User $actor, Visit $visit): ?array
    {
        return DB::transaction(function () use ($actor, $visit): ?array {
            $permission = $actor->can('billing.view.branch') ? 'billing.view.branch' : 'billing.summary.branch';
            $branchId = app(BranchAccessService::class)->activeBranch($actor)?->id;
            // The same check (and the same patient then visit lock) as the member's own Billing page.
            [$actor, $visit, $branch] = $this->context->lock($actor, $visit, $branchId, $permission);
            if ($visit->family_group_id === null) {
                return null;
            }
            $invoices = $this->openInvoices($visit);
            $states = $this->ledger->states($invoices);
            $showLines = $actor->can('billing.view.branch') || $actor->can('billing.lines.view.branch'); // Kumar R5
            $lines = $showLines && $invoices->isNotEmpty()
                ? InvoiceLine::query()->whereIn('invoice_id', $invoices->pluck('id'))->orderBy('id')->get()->groupBy('invoice_id')
                : collect();
            $paused = $this->gate->paused($branch, now($branch->timezone)->toDateString());
            $visits = Visit::query()->whereIn('id', $invoices->pluck('visit_id'))
                ->with('patient:id,patient_number,full_name')->get()->keyBy('id');
            $members = $invoices->map(function (Invoice $invoice) use ($states, $lines, $showLines, $branch, $visits): array {
                $state = $states[$invoice->id];
                $member = $visits[$invoice->visit_id];

                return [
                    'visitNumber' => $member->visit_number,
                    'patientName' => $member->patient->full_name,
                    'patientNumber' => $member->patient->patient_number,
                    'coverage' => $member->coverage_type === 'panel' ? $member->coverage_panel_name_snapshot : 'Self-pay',
                    'billingUrl' => route('billing.show', $member, absolute: false),
                    'invoice' => [
                        'publicId' => $invoice->public_id,
                        'number' => $invoice->invoice_number,
                        'lockVersion' => $invoice->lock_version,
                        'finalizedAt' => $invoice->finalized_at?->setTimezone($branch->timezone)->format('j M Y, g:i A'),
                        'correctionHold' => (bool) $invoice->correction_hold,
                        'state' => $state,
                        'lines' => $showLines ? ($lines[$invoice->id] ?? collect())->map(fn (InvoiceLine $l): array => [
                            'type' => $l->line_type, 'name' => $l->display_name, 'unit' => $l->unit_snapshot,
                            'quantity' => $l->quantity, 'unitPriceSen' => $l->unit_price_sen, 'totalSen' => $l->line_total_sen,
                        ])->values()->all() : [],
                    ],
                ];
            })->values()->all();
            $payable = array_filter($members, fn (array $m): bool => ! $m['invoice']['correctionHold']);

            return [
                'familyGroupId' => $visit->family_group_id,
                'anchorVisitNumber' => $visit->visit_number,
                'branch' => $branch->name,
                'expectedBranchId' => $branch->id,
                'members' => $members,
                'totalDueNowSen' => array_sum(array_map(fn (array $m): int => (int) $m['invoice']['state']['due_now'], $payable)),
                'linesShown' => $showLines,
                'can' => ['pay' => $actor->can('payments.add.branch') && ! $paused],
                'paused' => $paused,
            ];
        });
    }

    /**
     * Whether the member's own Billing page should offer "Family bill": the visit has a family group and at least one other
     * member at the same branch has an open, finalised invoice. Read only, no locks.
     */
    public function hasOtherMembers(Visit $visit): bool
    {
        if ($visit->family_group_id === null) {
            return false;
        }

        return $this->openInvoices($visit)->contains(fn (Invoice $invoice): bool => $invoice->visit_id !== $visit->id);
    }

    /**
     * Open invoices of the family group at the anchor visit's branch: the visit's current (not void) finalised invoice on a
     * still-registered visit, oldest finalised first (the owner's split order).
     *
     * @return Collection<int, Invoice>
     */
    private function openInvoices(Visit $visit): Collection
    {
        return Invoice::query()
            ->where('organisation_id', $visit->organisation_id)
            ->where('branch_id', $visit->branch_id)
            ->where('status', 'finalized')
            ->whereIn('current_visit_guard', Visit::query()
                ->select('id')
                ->where('organisation_id', $visit->organisation_id)
                ->where('branch_id', $visit->branch_id)
                ->where('family_group_id', $visit->family_group_id)
                ->where('status', Visit::STATUS_REGISTERED))
            ->orderBy('finalized_at')
            ->orderBy('id')
            ->get();
    }
}
