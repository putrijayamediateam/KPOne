<?php

namespace App\Domain\Visit\Billing\Services;

use App\Domain\Visit\Billing\Models\CoverageAllocation;
use App\Domain\Visit\Billing\Models\Invoice;
use App\Domain\Visit\Billing\Models\PanelClaim;
use App\Domain\Visit\Billing\Models\PanelClaimAdjustment;
use App\Domain\Visit\Billing\Models\PanelClaimSnapshot;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * PCA-2: the effective Panel share of an approved allocation is its stored amount plus confirmed Panel claim
 * adjustments, the same figure as PostgreSQL's kpone_panel_effective. The allocation row itself is never edited.
 */
final class PanelShare
{
    public static function adjustments(CoverageAllocation $allocation): int
    {
        if ($allocation->status !== 'approved') {
            return 0;
        }

        return (int) PanelClaimAdjustment::query()->where('coverage_allocation_id', $allocation->id)->where('status', 'confirmed')
            ->selectRaw("COALESCE(SUM(CASE direction WHEN 'add' THEN amount_sen ELSE -amount_sen END), 0) AS amount")->value('amount');
    }

    public static function effective(CoverageAllocation $allocation): int
    {
        return $allocation->amount_sen + self::adjustments($allocation);
    }

    /** Append the next recorded version of a claim pack's totals (version 1 at creation). */
    public static function recordVersion(PanelClaim $claim, User $actor, ?PanelClaimAdjustment $adjustment): PanelClaimSnapshot
    {
        $allocation = CoverageAllocation::query()->findOrFail($claim->coverage_allocation_id);
        $adjustments = self::adjustments($allocation);
        $version = (int) PanelClaimSnapshot::query()->where('panel_claim_id', $claim->id)->max('version') + 1;
        $snapshot = new PanelClaimSnapshot;
        $snapshot->forceFill([
            'public_id' => (string) Str::uuid(),
            'organisation_id' => $claim->organisation_id,
            'branch_id' => $claim->branch_id,
            'panel_claim_id' => $claim->id,
            'version' => $version,
            'invoice_total_sen' => app(FinancialLedger::class)->chargedBeforeCredits(Invoice::query()->findOrFail($claim->invoice_id)),
            'panel_adjustment_sen' => $adjustments,
            'panel_share_sen' => $allocation->amount_sen + $adjustments,
            'claim_amount_sen' => $claim->claim_amount_sen,
            'panel_claim_adjustment_id' => $adjustment?->id,
            'created_by_user_id' => $actor->id,
            'created_at' => now()->utc(),
        ])->save();

        return $snapshot;
    }
}
