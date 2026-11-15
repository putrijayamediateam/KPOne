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
use Illuminate\Support\Facades\DB;

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
        $claims = PanelClaim::query()->where('coverage_allocation_id', $allocation->id)->orderBy('id')->get(['id', 'public_id', 'claim_kind', 'claim_amount_sen', 'accepted_amount_sen', 'status', 'panel_claim_adjustment_id']);
        $original = $claims->firstWhere('claim_kind', 'original');
        $adjustments = PanelClaimAdjustment::query()->where('coverage_allocation_id', $allocation->id)->orderBy('id')->get();
        $people = $adjustments->pluck('requested_by_user_id')->merge($adjustments->pluck('confirmed_by_user_id'))->filter()->unique()->all();
        $names = self::names($actor, $people);
        $roles = self::roles($people);
        $canRequest = $actor->can(PanelClaimAdjustmentService::REQUEST_PERMISSION);
        $canConfirm = $actor->can(PanelClaimAdjustmentService::CONFIRM_PERMISSION);
        $waiting = $adjustments->firstWhere('status', 'requested');
        $sum = (int) $adjustments->where('status', 'confirmed')->sum(fn (PanelClaimAdjustment $a): int => $a->signedSen());
        $time = fn (?CarbonImmutable $at): ?string => $at?->setTimezone($branch->timezone)->format('j M Y, g:i A');

        $confirmed = $adjustments->where('status', 'confirmed')->values();
        $canManage = $actor->can('panel.claims.manage.branch');
        $claimRow = fn (PanelClaim $c): array => [
            'number' => self::number($c->id),
            'url' => $canManage ? route('panel-claims.show', $c) : null,
            'statusKey' => $c->status,
            'amountSen' => $c->claim_amount_sen,
            'acceptedSen' => $c->accepted_amount_sen,
            'decided' => in_array($c->status, PanelClaim::DECIDED, true),
            'reason' => ($r = $adjustments->firstWhere('id', $c->panel_claim_adjustment_id)) ? (PanelClaimAdjustment::REASONS[$r->reason_code] ?? null) : null,
        ];

        return [
            'invoiceNumber' => $invoice->invoice_number,
            'invoicePayableSen' => app(FinancialLedger::class)->chargedBeforeCredits($invoice),
            'reasonsApplied' => $confirmed->map(fn (PanelClaimAdjustment $a): string => PanelClaimAdjustment::REASONS[$a->reason_code] ?? $a->reason_code)->unique()->values()->all(),
            'claim' => $original === null ? null : [
                ...$claimRow($original),
                // Daniel's board: an undecided claim re-synced after it was submitted tells staff to tell the Panel.
                'changedAfterSubmission' => $original->status === 'submitted' && DB::table('panel_claim_events')->where('panel_claim_id', $original->id)->where('event_type', 'panel_adjusted')
                    ->where('occurred_at', '>', DB::table('panel_claim_events')->where('panel_claim_id', $original->id)->where('event_type', 'submitted')->max('occurred_at') ?? '1970-01-01')->exists(),
            ],
            'supplementaryClaims' => $claims->where('claim_kind', 'supplementary')->values()->map($claimRow)->all(),
            'originalShareSen' => $allocation->amount_sen,
            'adjustmentSen' => $sum,
            'shareSen' => $allocation->amount_sen + $sum,
            'claimedSen' => (int) $claims->where('status', '!=', 'cancelled')->sum('claim_amount_sen'),
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
                'newShareSen' => $allocation->amount_sen + $sum + $waiting->signedSen(),
                'requestedByYou' => $waiting->requested_by_user_id === $actor->id,
                'lockVersion' => $waiting->lock_version,
                'canConfirm' => $canConfirm,
                'canWithdraw' => $canRequest,
                'confirmUrl' => route('panel-claim-adjustments.confirm', $waiting),
                'withdrawUrl' => route('panel-claim-adjustments.withdraw', $waiting),
            ],
            'confirmed' => $confirmed->map(fn (PanelClaimAdjustment $a): array => [
                'direction' => $a->direction,
                'amountSen' => $a->amount_sen,
                'reason' => PanelClaimAdjustment::REASONS[$a->reason_code] ?? $a->reason_code,
                'confirmedAt' => $time($a->confirmed_at),
                'requestedBy' => $names[$a->requested_by_user_id] ?? 'Staff member',
                'confirmedBy' => $names[(int) $a->confirmed_by_user_id] ?? 'Staff member',
                'confirmedByRole' => $roles[(int) $a->confirmed_by_user_id] ?? null,
                'samePerson' => $a->requested_by_user_id === $a->confirmed_by_user_id,
            ])->all(),
        ];
    }

    /** @return array<string, mixed> The claim's own kind, links and recorded versions. */
    public static function forClaim(PanelClaim $claim, Branch $branch): array
    {
        $parent = $claim->parent_claim_id === null ? null : PanelClaim::query()->find($claim->parent_claim_id);
        $children = PanelClaim::query()->where('parent_claim_id', $claim->id)->orderBy('id')->get(['id', 'public_id', 'claim_amount_sen', 'status', 'panel_claim_adjustment_id']);

        $own = $claim->panel_claim_adjustment_id === null ? null : PanelClaimAdjustment::query()->find($claim->panel_claim_adjustment_id);

        return [
            'kind' => $claim->claim_kind,
            'number' => self::number($claim->id),
            'decided' => in_array($claim->status, PanelClaim::DECIDED, true),
            'reason' => $own === null ? null : (PanelClaimAdjustment::REASONS[$own->reason_code] ?? null),
            'parent' => $parent === null ? null : ['claimNo' => (string) $parent->id, 'number' => self::number($parent->id), 'url' => route('panel-claims.show', $parent),
                'amountSen' => $parent->claim_amount_sen, 'acceptedSen' => $parent->accepted_amount_sen, 'statusKey' => $parent->status],
            'supplementary' => $children->map(fn (PanelClaim $c): array => [
                'claimNo' => (string) $c->id, 'number' => self::number($c->id), 'url' => route('panel-claims.show', $c), 'amountSen' => $c->claim_amount_sen, 'statusKey' => $c->status,
                'reason' => ($a = PanelClaimAdjustment::query()->find($c->panel_claim_adjustment_id)) ? (PanelClaimAdjustment::REASONS[$a->reason_code] ?? null) : null,
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

    /** Daniel's board: claim numbers read PCL-00000123. */
    public static function number(int $id): string
    {
        return 'PCL-'.str_pad((string) $id, 8, '0', STR_PAD_LEFT);
    }

    /**
     * @param  array<int, mixed>  $ids
     * @return array<int, string>
     */
    private static function roles(array $ids): array
    {
        return DB::table('model_has_roles')->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->where('model_has_roles.model_type', User::class)->whereIn('model_has_roles.model_id', $ids)->orderBy('roles.name')
            ->get(['model_has_roles.model_id', 'roles.name'])->groupBy('model_id')
            ->map(fn ($rows): string => ucwords(str_replace('_', ' ', (string) $rows->first()->name)))->all();
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
