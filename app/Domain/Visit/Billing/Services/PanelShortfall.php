<?php

namespace App\Domain\Visit\Billing\Services;

use App\Domain\Visit\Billing\Models\CoverageAllocation;
use App\Domain\Visit\Billing\Models\Invoice;
use App\Domain\Visit\Billing\Models\PanelClaim;

/**
 * BIL-1b (owner decision 2026-11-05, Option B): the part of an invoice's approved Panel share that the Panel
 * did not accept. Read-only and display-only: it never changes the ledger, what the patient owes, the invoice,
 * the allocation or the claim. Numbers and the decision only; never claim notes, remarks or references.
 */
final class PanelShortfall
{
    /**
     * decision: 'rejected' (nothing accepted), 'partially_accepted' (less than the claim accepted) or
     * 'claim_reduced' (the whole claim accepted, but the claim was for less than the Panel share).
     *
     * @return array{shortfallSen:int, panelShareSen:int, acceptedSen:int, decision:'rejected'|'partially_accepted'|'claim_reduced'}|null
     */
    public static function forInvoice(Invoice $invoice): ?array
    {
        $allocation = CoverageAllocation::query()->where('invoice_id', $invoice->id)
            ->where('current_invoice_guard', $invoice->id)->where('status', 'approved')->first(['id', 'amount_sen']);
        if ($allocation === null) {
            return null;
        }
        $claim = PanelClaim::query()->where('coverage_allocation_id', $allocation->id)->first(['claim_amount_sen', 'accepted_amount_sen']);
        // A decision exists only once the accepted amount is recorded; a resubmission clears it again.
        if ($claim === null || $claim->accepted_amount_sen === null) {
            return null;
        }
        $accepted = $claim->accepted_amount_sen;
        $shortfall = max(0, $allocation->amount_sen - $accepted);
        if ($shortfall === 0) {
            return null;
        }
        $decision = match (true) {
            $accepted === 0 => 'rejected',
            $accepted < $claim->claim_amount_sen => 'partially_accepted',
            default => 'claim_reduced',
        };

        return ['shortfallSen' => $shortfall, 'panelShareSen' => $allocation->amount_sen, 'acceptedSen' => $accepted, 'decision' => $decision];
    }
}
