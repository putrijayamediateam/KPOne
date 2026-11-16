<?php

namespace App\Domain\Visit\Billing\Services;

use App\Domain\Access\TransactionalActorAuthority;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Patient\Models\Patient;
use App\Domain\Visit\Billing\Models\CoverageAllocation;
use App\Domain\Visit\Billing\Models\Invoice;
use App\Domain\Visit\Billing\Models\PanelClaim;
use App\Domain\Visit\Billing\Models\PanelClaimAdjustment;
use App\Domain\Visit\Billing\Models\PanelClaimEvent;
use App\Domain\Visit\Models\Visit;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * PCA-2 (design note v3.2, owner approved 2026-11-15): request, confirm or withdraw a Panel claim adjustment.
 *
 * The invoice payable and the Panel share move together by the same amount; what the patient owes never moves. The same
 * person may confirm their own request (a separate, recorded step; no amount limit). On confirmation the Panel claim is
 * kept in step: an undecided original claim is re-synced to the new share, a decided or paid one gets a supplementary
 * claim for a plus adjustment, and a minus adjustment on a decided claim may not go below what the Panel accepted.
 *
 * One transaction, one lock order (the order payments and completion use): patient, visit, invoice, the Panel
 * allocation, then its claims, then the ledger rows.
 */
final class PanelClaimAdjustmentService
{
    public const REQUEST_PERMISSION = 'panel.claims.adjustments.request.branch';

    public const CONFIRM_PERMISSION = 'panel.claims.adjustments.confirm.branch';

    private const UNDECIDED = ['prepared', 'submitted', 'queried', 'rejected'];

    /** Daniel's board v10, section 3: the refusal texts, verbatim (RM figures are live amounts). */
    private const CHANGED = 'This record has changed. Reload and review it again.';

    private const NOT_ADJUSTABLE = 'Only a finalized invoice with an approved Panel share can be adjusted.';

    public function __construct(private TransactionalActorAuthority $authority, private AuditRecorder $audit, private FinancialLedger $ledger) {}

    /** @param array<string, mixed> $a */
    public function request(User $actor, CoverageAllocation $routeAllocation, array $a): PanelClaimAdjustment
    {
        Validator::make($a, [
            'expected_branch_id' => ['required', 'integer', 'min:1'],
            'invoice_lock_version' => ['required', 'integer', 'min:1'],
            'direction' => ['required', Rule::in(['add', 'deduct'])],
            'amount_sen' => ['required'],
            'reason_code' => ['required', Rule::in(array_keys(PanelClaimAdjustment::REASONS))],
            'note' => ['nullable', 'string', 'max:500', Rule::requiredIf(($a['reason_code'] ?? null) === 'other')],
            'idempotency_key' => ['required', 'uuid'],
        ])->validate();
        $amount = ExactMoney::sen($a['amount_sen']);
        if ($amount < 1) {
            throw ValidationException::withMessages(['amount_sen' => 'Enter an amount above RM 0.00.']);
        }
        $note = trim((string) ($a['note'] ?? '')) === '' ? null : trim((string) $a['note']);
        $hash = hash('sha256', json_encode([$routeAllocation->id, $a['direction'], $amount, $a['reason_code'], $note], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($actor, $routeAllocation, $a, $amount, $note, $hash): PanelClaimAdjustment {
            $branch = $this->authority->branch($actor, $a['expected_branch_id']);
            $actor = $this->authority->lock($actor, $branch, self::REQUEST_PERMISSION);
            $existing = PanelClaimAdjustment::query()->where('organisation_id', $actor->organisation_id)
                ->where('request_idempotency_key', $a['idempotency_key'])->lockForUpdate()->first();
            if ($existing !== null) {
                if ($existing->branch_id !== $branch->id || $existing->request_payload_hash !== $hash) {
                    throw ValidationException::withMessages(['adjustment' => 'This request key was already used with different details.']);
                }

                return $existing;
            }
            [$invoice, $allocation, $original, $supplementary] = $this->lockSources($actor, $branch, $routeAllocation->id);
            if ($invoice->lock_version !== (int) $a['invoice_lock_version']) {
                throw ValidationException::withMessages(['adjustment' => self::CHANGED]);
            }
            if (PanelClaimAdjustment::query()->where('invoice_id', $invoice->id)->where('status', 'requested')->exists()) {
                throw ValidationException::withMessages(['adjustment' => 'A Panel adjustment is already waiting to be confirmed on this invoice. Confirm or withdraw it first.']);
            }
            $signed = $a['direction'] === 'add' ? $amount : -$amount;
            $this->plan($allocation, $original, $supplementary, $signed);

            $adjustment = new PanelClaimAdjustment;
            $adjustment->forceFill([
                'public_id' => (string) Str::uuid(),
                'organisation_id' => $actor->organisation_id,
                'branch_id' => $branch->id,
                'invoice_id' => $invoice->id,
                'coverage_allocation_id' => $allocation->id,
                'panel_claim_id' => $original?->id,
                'direction' => $a['direction'],
                'amount_sen' => $amount,
                'reason_code' => $a['reason_code'],
                'note' => $note,
                'status' => 'requested',
                'claim_status_at_request' => $original?->status,
                'invoice_version' => $invoice->lock_version,
                'requested_by_user_id' => $actor->id,
                'requested_at' => now()->utc(),
                'request_idempotency_key' => $a['idempotency_key'],
                'request_payload_hash' => $hash,
                'lock_version' => 1,
            ])->save();
            $this->audit->record('panel_claim_adjustment.requested', $adjustment, [
                'direction' => $adjustment->direction, 'amount_sen' => $amount, 'reason_code' => $adjustment->reason_code,
            ], $actor, $branch);

            return $adjustment;
        }, 3);
    }

    /** @param array<string, mixed> $a */
    public function confirm(User $actor, PanelClaimAdjustment $routeAdjustment, array $a): PanelClaimAdjustment
    {
        Validator::make($a, [
            'expected_branch_id' => ['required', 'integer', 'min:1'],
            'lock_version' => ['required', 'integer', 'min:1'],
            'idempotency_key' => ['required', 'uuid'],
        ])->validate();

        return DB::transaction(function () use ($actor, $routeAdjustment, $a): PanelClaimAdjustment {
            $branch = $this->authority->branch($actor, $a['expected_branch_id']);
            $actor = $this->authority->lock($actor, $branch, self::CONFIRM_PERMISSION);
            $current = $this->find($actor, $branch, $routeAdjustment);
            if ($current->status === 'confirmed' && $current->confirm_idempotency_key === $a['idempotency_key']) {
                return $current; // A retried confirmation does nothing.
            }
            [$invoice, $allocation, $original, $supplementary] = $this->lockSources($actor, $branch, $current->coverage_allocation_id);
            $adjustment = PanelClaimAdjustment::query()->whereKey($current->id)->lockForUpdate()->firstOrFail();
            if ($adjustment->status === 'confirmed' && $adjustment->confirm_idempotency_key === $a['idempotency_key']) {
                return $adjustment; // The same confirmation, retried at the same moment: it already applied once.
            }
            if ($adjustment->status !== 'requested') {
                throw ValidationException::withMessages(['adjustment' => self::CHANGED]);
            }
            if ($adjustment->lock_version !== (int) $a['lock_version']) {
                throw ValidationException::withMessages(['adjustment' => self::CHANGED]);
            }
            if ($original?->status !== $adjustment->claim_status_at_request || ($original === null) !== ($adjustment->panel_claim_id === null)) {
                throw ValidationException::withMessages(['adjustment' => 'This claim was decided or paid after the request. Withdraw this request and adjust again. An increase will make a supplementary claim.']);
            }
            $plan = $this->plan($allocation, $original, $supplementary, $adjustment->signedSen());

            $adjustment->forceFill([
                'status' => 'confirmed', 'confirmed_by_user_id' => $actor->id, 'confirmed_at' => now()->utc(),
                'confirm_idempotency_key' => $a['idempotency_key'], 'lock_version' => $adjustment->lock_version + 1,
            ])->save();
            // An open payment, completion or claim form on this invoice is refused and reloaded.
            $invoice->forceFill(['lock_version' => $invoice->lock_version + 1])->save();
            $this->apply($actor, $branch, $adjustment, $original, $supplementary, $plan);
            $this->ledger->state($invoice->refresh()); // Reconciles: what the patient owes is unchanged.
            $this->audit->record('panel_claim_adjustment.confirmed', $adjustment, [
                'direction' => $adjustment->direction, 'amount_sen' => $adjustment->amount_sen, 'reason_code' => $adjustment->reason_code,
                'self_confirmed' => $adjustment->requested_by_user_id === $actor->id,
            ], $actor, $branch);

            return $adjustment;
        }, 3);
    }

    /** @param array<string, mixed> $a */
    public function withdraw(User $actor, PanelClaimAdjustment $routeAdjustment, array $a): PanelClaimAdjustment
    {
        Validator::make($a, [
            'expected_branch_id' => ['required', 'integer', 'min:1'],
            'lock_version' => ['required', 'integer', 'min:1'],
        ])->validate();

        return DB::transaction(function () use ($actor, $routeAdjustment, $a): PanelClaimAdjustment {
            $branch = $this->authority->branch($actor, $a['expected_branch_id']);
            $actor = $this->authority->lock($actor, $branch, self::REQUEST_PERMISSION);
            $current = $this->find($actor, $branch, $routeAdjustment);
            // A waiting request can always be withdrawn, even after the invoice was voided.
            $this->lockSources($actor, $branch, $current->coverage_allocation_id, requireAdjustable: false);
            $adjustment = PanelClaimAdjustment::query()->whereKey($current->id)->lockForUpdate()->firstOrFail();
            if ($adjustment->status !== 'requested') {
                throw ValidationException::withMessages(['adjustment' => self::CHANGED]);
            }
            if ($adjustment->lock_version !== (int) $a['lock_version']) {
                throw ValidationException::withMessages(['adjustment' => self::CHANGED]);
            }
            $adjustment->forceFill(['status' => 'withdrawn', 'withdrawn_by_user_id' => $actor->id, 'withdrawn_at' => now()->utc(), 'lock_version' => $adjustment->lock_version + 1])->save();
            $this->audit->record('panel_claim_adjustment.withdrawn', $adjustment, ['amount_sen' => $adjustment->amount_sen], $actor, $branch);

            return $adjustment;
        }, 3);
    }

    private function find(User $actor, Branch $branch, PanelClaimAdjustment $routeAdjustment): PanelClaimAdjustment
    {
        return PanelClaimAdjustment::query()->whereKey($routeAdjustment->id)->where('organisation_id', $actor->organisation_id)
            ->where('branch_id', $branch->id)->firstOrFail();
    }

    /**
     * Patient, visit, invoice, allocation, claims, ledger rows: the lock order shared with payments and completion.
     *
     * @return array{Invoice, CoverageAllocation, PanelClaim|null, Collection<int, PanelClaim>}
     */
    private function lockSources(User $actor, Branch $branch, int $allocationId, bool $requireAdjustable = true): array
    {
        $peek = CoverageAllocation::query()->whereKey($allocationId)->where('organisation_id', $actor->organisation_id)->where('branch_id', $branch->id)->firstOrFail();
        $visitId = (int) Invoice::query()->whereKey($peek->invoice_id)->value('visit_id');
        $peekVisit = Visit::query()->whereKey($visitId)->where('organisation_id', $actor->organisation_id)->where('branch_id', $branch->id)->firstOrFail();
        Patient::query()->whereKey($peekVisit->patient_id)->where('organisation_id', $actor->organisation_id)->lockForUpdate()->firstOrFail();
        Visit::query()->whereKey($visitId)->lockForUpdate()->firstOrFail();
        $invoice = Invoice::query()->whereKey($peek->invoice_id)->where('organisation_id', $actor->organisation_id)->where('branch_id', $branch->id)->lockForUpdate()->firstOrFail();
        $allocation = CoverageAllocation::query()->whereKey($allocationId)->where('invoice_id', $invoice->id)->lockForUpdate()->firstOrFail();
        $claims = PanelClaim::query()->where('coverage_allocation_id', $allocation->id)->orderBy('id')->lockForUpdate()->get();
        $this->ledger->lock($invoice);
        if (! $requireAdjustable) {
            return [$invoice, $allocation, null, collect()];
        }
        if ($invoice->status === 'voided') {
            throw ValidationException::withMessages(['adjustment' => 'This invoice was voided. Request the adjustment again on the new invoice.']);
        }
        if ($allocation->status !== 'approved' || (int) $allocation->current_invoice_guard !== $invoice->id || ! $allocation->panel_id
            || $invoice->status !== 'finalized' || $invoice->correction_hold || $invoice->source_stale) {
            throw ValidationException::withMessages(['adjustment' => self::NOT_ADJUSTABLE]);
        }

        return [$invoice, $allocation, $claims->firstWhere('claim_kind', 'original'), $claims->where('claim_kind', 'supplementary')->values()];
    }

    /**
     * Decide what a confirmed adjustment of $signed sen does, or refuse it. Re-run under the locks at confirmation.
     *
     * @param  Collection<int, PanelClaim>  $supplementary
     * @return array{kind: string, originalAmount?: int, reductions?: array<int, int>}
     */
    private function plan(CoverageAllocation $allocation, ?PanelClaim $original, Collection $supplementary, int $signed): array
    {
        $share = PanelShare::effective($allocation);
        $newShare = $share + $signed;
        if ($newShare < 0) {
            throw ValidationException::withMessages(['amount_sen' => "The Panel share can't go below RM 0.00."]);
        }
        if ($original === null) {
            return ['kind' => 'unclaimed'];
        }
        $supplementary = $supplementary->where('status', '!=', 'cancelled')->values();
        $supplementarySum = (int) $supplementary->sum('claim_amount_sen');
        if (in_array($original->status, self::UNDECIDED, true) && ! $original->payments()->exists()) {
            // Owner default: an old claim-only excess must be brought back to the share first, so the two kinds never mix.
            if ($original->claim_amount_sen > $share - $supplementarySum) {
                throw ValidationException::withMessages(['adjustment' => 'This claim amount is above the Panel share. Bring the claim amount back to the Panel share first, so the two kinds of change do not mix.']);
            }
            // The adjustment moves the claim by the same amount, so a claim kept below the share by a claim-only edit
            // stays below it (a minus never raises what is asked of the Panel).
            $amount = $original->claim_amount_sen + $signed;
            if ($amount < 1) {
                throw ValidationException::withMessages(['amount_sen' => 'This would leave the claim at RM 0.00.']);
            }

            return ['kind' => 'sync', 'originalAmount' => $amount];
        }
        if ($signed > 0) {
            return ['kind' => 'supplementary'];
        }
        // A minus on a decided claim: reduce undecided supplementary claims first (newest first; one fully covered is
        // cancelled, so a mistaken plus can always be undone), then never below what the Panel accepted or paid.
        $remaining = -$signed;
        $reductions = [];
        foreach ($supplementary->sortByDesc('id') as $claim) {
            if ($remaining === 0 || ! in_array($claim->status, self::UNDECIDED, true) || $claim->payments()->exists()) {
                continue;
            }
            $cut = min($remaining, $claim->claim_amount_sen);
            $reductions[$claim->id] = $cut;
            $remaining -= $cut;
        }
        $committed = 0;
        $accepted = 0;
        $paid = 0;
        foreach (collect([$original])->merge($supplementary) as $claim) {
            $decided = in_array($claim->status, PanelClaim::DECIDED, true);
            $accepted += $decided ? (int) $claim->accepted_amount_sen : 0;
            // A rejected claim commits nothing (accepted RM 0.00); an undecided one commits what it still asks for.
            $committed += match (true) {
                $decided => (int) $claim->accepted_amount_sen,
                $claim->status === 'rejected' => 0,
                default => $claim->claim_amount_sen - ($reductions[$claim->id] ?? 0),
            };
            $paid += (int) $claim->receivedPayments()->sum('amount_sen');
        }
        if ($newShare < $paid) {
            throw ValidationException::withMessages(['amount_sen' => sprintf("The Panel has already paid %s. A refund to the Panel isn't available yet, so the Panel share can't go below %s.", self::myr($paid), self::myr($paid))]);
        }
        if ($newShare < $committed) {
            throw ValidationException::withMessages(['amount_sen' => sprintf("The Panel has accepted %s on this Panel share. Record the Panel's revised decision on the claim first, then deduct.", self::myr($accepted))]);
        }

        return ['kind' => 'decided', 'reductions' => $reductions];
    }

    /**
     * @param  Collection<int, PanelClaim>  $supplementary
     * @param  array{kind: string, originalAmount?: int, reductions?: array<int, int>}  $plan
     */
    private function apply(User $actor, Branch $branch, PanelClaimAdjustment $adjustment, ?PanelClaim $original, Collection $supplementary, array $plan): void
    {
        if ($original === null) {
            return; // No claim yet: the claim created later starts at the adjusted share.
        }
        if ($plan['kind'] === 'sync') {
            $previous = $original->claim_amount_sen;
            $original->forceFill(['claim_amount_sen' => $plan['originalAmount'] ?? $original->claim_amount_sen, 'lock_version' => $original->lock_version + 1])->save();
            $this->event($original, $actor, $branch, 'panel_adjusted', $previous, $original->claim_amount_sen,
                $original->status === 'submitted' ? 'Amount changed after submission: tell the Panel' : null);
            PanelShare::recordVersion($original, $actor, $adjustment);

            return;
        }
        if ($plan['kind'] === 'supplementary') {
            $claim = $original->replicate(['public_id', 'claim_amount_sen', 'accepted_amount_sen', 'status', 'lock_version', 'remarks',
                'creation_idempotency_key', 'creation_payload_hash', 'claim_kind', 'parent_claim_id', 'panel_claim_adjustment_id', 'created_by_user_id']);
            $claim->forceFill([
                'public_id' => (string) Str::uuid(),
                'invoice_total_sen' => $this->ledger->chargedBeforeCredits(Invoice::query()->findOrFail($original->invoice_id)),
                'claim_amount_sen' => $adjustment->amount_sen,
                'status' => 'prepared',
                'lock_version' => 1,
                'claim_kind' => 'supplementary',
                'parent_claim_id' => $original->id,
                'panel_claim_adjustment_id' => $adjustment->id,
                'created_by_user_id' => $actor->id,
            ])->save();
            $this->event($claim, $actor, $branch, 'prepared', null, $claim->claim_amount_sen, null);
            $this->event($original, $actor, $branch, 'supplementary_created', null, $claim->claim_amount_sen, 'Supplementary claim '.$claim->id);
            PanelShare::recordVersion($claim, $actor, $adjustment);
            $this->audit->record('panel_claim.supplementary_created', $claim, ['claim_amount_sen' => $claim->claim_amount_sen], $actor, $branch);

            return;
        }
        foreach ($plan['reductions'] ?? [] as $claimId => $cut) {
            $claim = $supplementary->firstWhere('id', $claimId);
            if ($claim === null) {
                continue;
            }
            $previous = $claim->claim_amount_sen;
            if ($cut >= $previous) {
                $wasSubmitted = $claim->status !== 'prepared';
                $claim->forceFill(['status' => 'cancelled', 'accepted_amount_sen' => null, 'lock_version' => $claim->lock_version + 1])->save();
                // Daniel's wording: the log names the claim and the amount, and says whether the Panel must be told.
                $this->event($claim, $actor, $branch, 'cancelled', null, $previous,
                    'Supplementary claim '.PanelAdjustmentView::number($claim->id).' ('.self::myr($previous).') was cancelled by a Panel claim adjustment. '
                    .($wasSubmitted ? 'Tell the Panel.' : 'Nothing was submitted.'));
                $this->audit->record('panel_claim.supplementary_cancelled', $claim, ['claim_amount_sen' => $previous], $actor, $branch);

                continue;
            }
            $claim->forceFill(['claim_amount_sen' => $previous - $cut, 'lock_version' => $claim->lock_version + 1])->save();
            $this->event($claim, $actor, $branch, 'panel_adjusted', $previous, $claim->claim_amount_sen,
                $claim->status === 'submitted' ? 'Amount changed after submission: tell the Panel' : null);
            PanelShare::recordVersion($claim, $actor, $adjustment);
        }
    }

    private static function myr(int $sen): string
    {
        return 'RM '.number_format($sen / 100, 2);
    }

    private function event(PanelClaim $claim, User $actor, Branch $branch, string $type, ?int $previous, ?int $amount, ?string $note): void
    {
        $event = new PanelClaimEvent;
        $event->forceFill([
            'organisation_id' => $claim->organisation_id,
            'branch_id' => $branch->id,
            'panel_claim_id' => $claim->id,
            'actor_user_id' => $actor->id,
            'event_type' => $type,
            'previous_amount_sen' => $previous,
            'amount_sen' => $amount,
            'external_reference' => null,
            'note' => $note,
            'occurred_at' => now()->utc(),
        ])->save();
    }
}
