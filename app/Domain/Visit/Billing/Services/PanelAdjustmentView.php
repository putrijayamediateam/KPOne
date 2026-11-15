<?php

namespace App\Domain\Visit\Billing\Services;

use App\Domain\Access\StaffAccessService;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Visit\Billing\Models\CoverageAllocation;
use App\Domain\Visit\Billing\Models\Invoice;
use App\Domain\Visit\Billing\Models\PanelClaim;
use App\Domain\Visit\Billing\Models\PanelClaimAdjustment;
use App\Domain\Visit\Billing\Models\PanelClaimSnapshot;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * PCA-2: the Panel claim adjustment panel shared by the claim page and the Billing page: the Panel share as
 * "original + adjustments" next to the amount claimed, the waiting request, the confirmed history and the actions the
 * viewer may take. The private note is shown only on the waiting request to people who may confirm or withdraw it.
 */
final class PanelAdjustmentView
{
    /** @return array<string, mixed>|null */
    public static function forInvoice(User $actor, Invoice $invoice, Branch $branch, string $from): ?array
    {
        $allocation = CoverageAllocation::query()->where('invoice_id', $invoice->id)->where('current_invoice_guard', $invoice->id)->where('status', 'approved')->first();
        if ($allocation === null || $allocation->panel_id === null) {
            return null;
        }
        $claims = PanelClaim::query()->where('coverage_allocation_id', $allocation->id)->orderBy('id')->get(['id', 'public_id', 'claim_kind', 'claim_amount_sen', 'status']);
        $original = $claims->firstWhere('claim_kind', 'original');
        $adjustments = PanelClaimAdjustment::query()->where('coverage_allocation_id', $allocation->id)->orderBy('id')->get();
        $names = self::names($actor, $adjustments->pluck('requested_by_user_id')->merge($adjustments->pluck('confirmed_by_user_id'))->filter()->unique()->all());
        $canRequest = $actor->can(PanelClaimAdjustmentService::REQUEST_PERMISSION);
        $canConfirm = $actor->can(PanelClaimAdjustmentService::CONFIRM_PERMISSION);
        $waiting = $adjustments->firstWhere('status', 'requested');
        $sum = (int) $adjustments->where('status', 'confirmed')->sum(fn (PanelClaimAdjustment $a): int => $a->signedSen());
        $time = fn (?CarbonImmutable $at): ?string => $at?->setTimezone($branch->timezone)->format('j M Y, g:i A');

        return [
            'originalShareSen' => $allocation->amount_sen,
            'adjustmentSen' => $sum,
            'shareSen' => $allocation->amount_sen + $sum,
            'claimedSen' => (int) $claims->sum('claim_amount_sen'),
            'claimUrl' => $original === null || ! $actor->can('panel.claims.manage.branch') ? null : route('panel-claims.show', $original),
            'invoiceLockVersion' => $invoice->lock_version,
            'canRequest' => $canRequest && $invoice->status === 'finalized' && $waiting === null,
            'requestUrl' => route('panel-claim-adjustments.request', $allocation),
            'from' => $from,
            'reasons' => collect(PanelClaimAdjustment::REASONS)->map(fn (string $label, string $value): array => ['value' => $value, 'label' => $label])->values()->all(),
            'waiting' => $waiting === null ? null : [
                'publicId' => $waiting->public_id,
                'direction' => $waiting->direction,
                'amountSen' => $waiting->amount_sen,
                'reason' => PanelClaimAdjustment::REASONS[$waiting->reason_code] ?? $waiting->reason_code,
                'note' => $canRequest || $canConfirm ? $waiting->note : null,
                'requestedBy' => $names[$waiting->requested_by_user_id] ?? 'Staff member',
                'requestedAt' => $time($waiting->requested_at),
                'requestedByYou' => $waiting->requested_by_user_id === $actor->id,
                'lockVersion' => $waiting->lock_version,
                'canConfirm' => $canConfirm,
                'canWithdraw' => $canRequest,
                'confirmUrl' => route('panel-claim-adjustments.confirm', $waiting),
                'withdrawUrl' => route('panel-claim-adjustments.withdraw', $waiting),
            ],
            'confirmed' => $adjustments->where('status', 'confirmed')->values()->map(fn (PanelClaimAdjustment $a): array => [
                'direction' => $a->direction,
                'amountSen' => $a->amount_sen,
                'reason' => PanelClaimAdjustment::REASONS[$a->reason_code] ?? $a->reason_code,
                'confirmedAt' => $time($a->confirmed_at),
                'requestedBy' => $names[$a->requested_by_user_id] ?? 'Staff member',
                'confirmedBy' => $names[(int) $a->confirmed_by_user_id] ?? 'Staff member',
                'samePerson' => $a->requested_by_user_id === $a->confirmed_by_user_id,
            ])->all(),
        ];
    }

    /** @return array<string, mixed> The claim's own kind, links and recorded versions. */
    public static function forClaim(PanelClaim $claim, Branch $branch): array
    {
        $parent = $claim->parent_claim_id === null ? null : PanelClaim::query()->find($claim->parent_claim_id);
        $children = PanelClaim::query()->where('parent_claim_id', $claim->id)->orderBy('id')->get(['id', 'public_id', 'claim_amount_sen', 'status']);

        return [
            'kind' => $claim->claim_kind,
            'parent' => $parent === null ? null : ['claimNo' => (string) $parent->id, 'url' => route('panel-claims.show', $parent)],
            'supplementary' => $children->map(fn (PanelClaim $c): array => [
                'claimNo' => (string) $c->id, 'url' => route('panel-claims.show', $c), 'amountSen' => $c->claim_amount_sen, 'statusKey' => $c->status,
            ])->all(),
            'versions' => PanelClaimSnapshot::query()->where('panel_claim_id', $claim->id)->orderByDesc('version')->get()->map(fn (PanelClaimSnapshot $s): array => [
                'version' => $s->version,
                'invoiceTotalSen' => $s->invoice_total_sen,
                'panelAdjustmentSen' => $s->panel_adjustment_sen,
                'panelShareSen' => $s->panel_share_sen,
                'claimAmountSen' => $s->claim_amount_sen,
                'recordedAt' => $s->created_at->setTimezone($branch->timezone)->format('j M Y, g:i A'),
            ])->all(),
        ];
    }

    /**
     * @param  array<int, mixed>  $ids
     * @return array<int, string>
     */
    private static function names(User $actor, array $ids): array
    {
        return app(StaffAccessService::class)->visibleUsers($actor)->whereIn('id', $ids)->pluck('name', 'id')->all();
    }
}
