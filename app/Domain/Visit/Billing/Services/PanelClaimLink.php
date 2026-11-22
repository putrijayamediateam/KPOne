<?php

declare(strict_types=1);

namespace App\Domain\Visit\Billing\Services;

use App\Domain\Access\BranchAccessService;
use App\Domain\Access\TransactionalActorAuthority;
use App\Domain\Visit\Billing\Models\CoverageAllocation;
use App\Domain\Visit\Billing\Models\Invoice;
use App\Domain\Visit\Billing\Models\PanelClaim;
use App\Domain\Visit\Models\Visit;
use App\Models\User;

/**
 * BIL-35 (owner "go", agreed with the Panel team): the Panel claim button on a Panel invoice. Read only navigation.
 *
 * It shows only to someone who may open Panel Claims at the invoice's branch, with that branch active, and only where
 * the Panel team's own Create page would accept the allocation (PanelClaimDirectoryService::createDetails): approved,
 * the invoice's current allocation, finalised invoice, not on correction hold, not stale, and an effective Panel share
 * between 1 sen and the payable total before credits. An existing original claim of the invoice is always offered
 * (Open Panel claim), as the claim page needs only the branch; otherwise it offers Create claim. Links carry from=invoice|billing and the visit number so the claim page can offer a way back.
 *
 * @phpstan-type Link array{kind: 'open'|'create', label: string, href: string, claimNumber: string|null}
 */
final class PanelClaimLink
{
    public function __construct(private BranchAccessService $branches, private FinancialLedger $ledger, private TransactionalActorAuthority $authority) {}

    /**
     * @param  'invoice'|'billing'  $from
     * @return Link|null
     */
    public function for(User $actor, Visit $visit, ?Invoice $invoice, string $from): ?array
    {
        if ($invoice === null || ! $actor->can('panel.claims.manage.branch')) {
            return null;
        }

        $branch = $this->branches->activeBranch($actor);
        if ($branch === null || $branch->id !== $invoice->branch_id || $invoice->organisation_id !== $actor->organisation_id) {
            return null;
        }

        // The Panel pages lock the actor and need an active account, a staff profile and an assignment at the branch in
        // effect today; a Director may switch to a branch without one, so check the same rule here (Kumar, #1040).
        if (! $this->authority->wouldAllow($actor, $branch, 'panel.claims.manage.branch') || $visit->branch_id !== $invoice->branch_id) {
            return null;
        }

        $back = ['from' => $from, 'visit' => $visit->visit_number];
        // An existing original claim stays reachable even if the invoice later goes on hold or its allocation is
        // replaced: the claim page checks only organisation and branch.
        $claim = PanelClaim::query()->where('organisation_id', $invoice->organisation_id)->where('branch_id', $invoice->branch_id)
            ->where('invoice_id', $invoice->id)->where('claim_kind', 'original')->orderByDesc('id')->first();
        if ($claim !== null) {
            $number = PanelAdjustmentView::number((int) $claim->id);

            return ['kind' => 'open', 'label' => 'Open Panel claim · '.$number, 'href' => route('panel-claims.show', [$claim, ...$back], false), 'claimNumber' => $number];
        }

        if ($invoice->status !== 'finalized' || $invoice->correction_hold || $invoice->source_stale) {
            return null;
        }

        $allocation = CoverageAllocation::query()
            ->where('organisation_id', $invoice->organisation_id)
            ->where('branch_id', $invoice->branch_id)
            ->where('invoice_id', $invoice->id)
            ->where('status', 'approved')
            ->where('current_invoice_guard', $invoice->id)
            ->first();
        if ($allocation === null) {
            return null;
        }

        $share = PanelShare::effective($allocation);
        if ($share < 1 || $share > $this->ledger->chargedBeforeCredits($invoice)) {
            return null;
        }

        return ['kind' => 'create', 'label' => 'Create claim', 'href' => route('panel-claims.create', [$allocation, ...$back], false), 'claimNumber' => null];
    }
}
