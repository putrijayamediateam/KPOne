<?php

namespace App\Domain\Visit\Billing\Services;

use App\Domain\Access\StaffAccessService;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Visit\Billing\Models\Invoice;
use App\Domain\Visit\Billing\Models\Payment;
use App\Domain\Visit\Billing\Models\PaymentAllocation;
use App\Domain\Visit\Billing\Models\PaymentMethod;
use App\Domain\Visit\Billing\Models\PaymentRefund;
use App\Domain\Visit\Billing\Models\PaymentRefundDecision;
use App\Domain\Visit\Billing\Models\PaymentReversal;
use App\Domain\Visit\Models\Visit;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * BIL-6 (owner decisions 2026-11-05): a refund records money actually handed back to the patient for a receipt
 * that was reversed before visit completion, always for that receipt's full amount, through any active payment
 * method. A CA Supervisor or Finance Officer records it; a Finance Officer or Director who did not record it
 * approves or rejects it, with no amount threshold. It never changes the invoice, the receipt or the ledger: the
 * reversal already corrected the books. Cash refunds count in daily cash closing on the day they were paid out.
 */
final class RefundService
{
    public const RECORD_PERMISSION = 'payments.refunds.record.branch';

    public const APPROVE_PERMISSION = 'payments.refunds.approve.branch';

    public function __construct(private BillingContextService $context, private StaffAccessService $staff, private AuditRecorder $audit) {}

    /** @param array<string, mixed> $input */
    public function record(User $actor, Visit $visit, string $paymentPublicId, array $input): PaymentRefund
    {
        $reason = $input['reason_code'] ?? null;
        if (! is_string($reason) || ! array_key_exists($reason, PaymentRefund::REASONS)) {
            throw ValidationException::withMessages(['reason_code' => 'Choose why the money was refunded.']);
        }
        $key = $this->key($input['idempotency_key'] ?? null);

        return DB::transaction(function () use ($actor, $visit, $paymentPublicId, $input, $reason, $key): PaymentRefund {
            [$actor, $visit, $branch] = $this->context->lock($actor, $visit, $input['expected_branch_id'] ?? null, self::RECORD_PERMISSION);
            $payment = $this->visitPayment($visit, $paymentPublicId, true);
            // Owner decision 2026-11-05: refunds in v1 only before the visit is completed.
            if ($visit->status !== Visit::STATUS_REGISTERED) {
                throw ValidationException::withMessages(['refund' => 'This visit is completed. A refund after completion is not available yet.']);
            }
            $method = PaymentMethod::query()->where('organisation_id', $actor->organisation_id)->whereKey($input['payment_method_id'] ?? null)->operational()->first();
            if ($method === null) {
                throw ValidationException::withMessages(['payment_method_id' => 'Choose how the money was given back.']);
            }
            $hash = hash('sha256', json_encode([$actor->id, $payment->id, $method->id, $reason], JSON_THROW_ON_ERROR));
            $existing = PaymentRefund::query()->where('organisation_id', $actor->organisation_id)->where('idempotency_key', $key)->first();
            if ($existing !== null) {
                return $existing->payload_hash === $hash ? $existing : throw ValidationException::withMessages(['refund' => 'This request was already used for a different refund. Reload and try again.']);
            }
            $reversal = PaymentReversal::query()->where('payment_id', $payment->id)->first();
            if ($payment->status !== 'reversed' || $reversal === null) {
                throw ValidationException::withMessages(['refund' => 'Only a reversed receipt can be refunded.']);
            }
            if ($this->openRefund($payment) !== null) {
                throw ValidationException::withMessages(['refund' => 'This receipt already has a refund that is not rejected.']);
            }

            $refund = new PaymentRefund;
            $refund->forceFill(['public_id' => (string) Str::uuid(), 'organisation_id' => $actor->organisation_id, 'branch_id' => $branch->id, 'payment_id' => $payment->id,
                'payment_method_id' => $method->id, 'method_snapshot' => $method->name, 'amount_sen' => (int) $reversal->amount_sen, 'reason_code' => $reason,
                'recorded_by_user_id' => $actor->id, 'paid_out_at' => now()->utc(), 'idempotency_key' => $key, 'payload_hash' => $hash])->save();
            $this->audit->record('billing.refund_recorded', $refund, ['amount_sen' => $refund->amount_sen, 'payment_method_id' => $method->id], $actor, $branch);

            return $refund;
        }, 3);
    }

    /** @param array<string, mixed> $input */
    public function decide(User $actor, Visit $visit, string $refundPublicId, string $decision, array $input): PaymentRefundDecision
    {
        abort_unless(in_array($decision, ['approved', 'rejected'], true), 404);
        $reason = $decision === 'rejected' ? ($input['reason_code'] ?? null) : null;
        if ($decision === 'rejected' && (! is_string($reason) || ! array_key_exists($reason, PaymentRefund::REJECT_REASONS))) {
            throw ValidationException::withMessages(['reason_code' => 'Choose why the refund is rejected.']);
        }
        $key = $this->key($input['idempotency_key'] ?? null);

        return DB::transaction(function () use ($actor, $visit, $refundPublicId, $decision, $input, $reason, $key): PaymentRefundDecision {
            [$actor, $visit, $branch] = $this->context->lock($actor, $visit, $input['expected_branch_id'] ?? null, self::APPROVE_PERMISSION);
            $refund = PaymentRefund::query()->where('organisation_id', $actor->organisation_id)->where('branch_id', $branch->id)->where('public_id', $refundPublicId)->first();
            abort_if($refund === null, 404);
            // Lock the refunded receipt: decisions, new refunds and cash closing approvals on it go one at a time.
            $payment = Payment::query()->whereKey($refund->payment_id)->lockForUpdate()->firstOrFail();
            abort_unless(PaymentAllocation::query()->where('payment_id', $payment->id)->whereIn('invoice_id', Invoice::query()->where('visit_id', $visit->id)->select('id'))->exists(), 404);
            $hash = hash('sha256', json_encode([$actor->id, $refund->id, $decision, $reason], JSON_THROW_ON_ERROR));
            $existing = PaymentRefundDecision::query()->where('organisation_id', $actor->organisation_id)->where('idempotency_key', $key)->first();
            if ($existing !== null) {
                return $existing->payload_hash === $hash ? $existing : throw ValidationException::withMessages(['refund' => 'This request was already used for a different decision. Reload and try again.']);
            }
            if ($refund->recorded_by_user_id === $actor->id) {
                throw ValidationException::withMessages(['refund' => 'You recorded this refund. Someone else must approve or reject it.']);
            }
            if ($refund->decision()->exists()) {
                throw ValidationException::withMessages(['refund' => 'This refund was already decided. Reload the page.']);
            }

            $row = new PaymentRefundDecision;
            $row->forceFill(['organisation_id' => $actor->organisation_id, 'payment_refund_id' => $refund->id, 'decision' => $decision, 'reason_code' => $reason,
                'decided_by_user_id' => $actor->id, 'decided_at' => now()->utc(), 'idempotency_key' => $key, 'payload_hash' => $hash])->save();
            $this->audit->record($decision === 'approved' ? 'billing.refund_approved' : 'billing.refund_rejected', $refund, ['amount_sen' => $refund->amount_sen, 'reason_code' => $reason], $actor, $branch);

            return $row;
        }, 3);
    }

    /**
     * Reversed receipts of this visit (any of its invoices) with their latest refund, for the Billing page.
     *
     * @return array<string, mixed>|null
     */
    public function forVisit(User $actor, Visit $visit, string $timezone): ?array
    {
        $canRecord = $actor->can(self::RECORD_PERMISSION);
        $canApprove = $actor->can(self::APPROVE_PERMISSION);
        if (! $canRecord && ! $canApprove) {
            return null;
        }
        $payments = Payment::query()->where('status', 'reversed')
            ->whereIn('id', PaymentAllocation::query()->whereIn('invoice_id', Invoice::query()->where('visit_id', $visit->id)->select('id'))->select('payment_id'))
            ->orderBy('id')->get();
        if ($payments->isEmpty()) {
            return null;
        }
        $refunds = PaymentRefund::query()->with('decision')->whereIn('payment_id', $payments->pluck('id'))->orderBy('id')->get()->groupBy('payment_id');
        $people = $this->staff->visibleUsers($actor)->whereIn('id', $refunds->flatten()->pluck('recorded_by_user_id')
            ->merge($refunds->flatten()->pluck('decision.decided_by_user_id'))->filter()->unique())->pluck('name', 'id');
        $reversals = PaymentReversal::query()->whereIn('payment_id', $payments->pluck('id'))->pluck('amount_sen', 'payment_id');
        $visitOpen = $visit->status === Visit::STATUS_REGISTERED;

        return [
            'can' => ['record' => $canRecord, 'approve' => $canApprove],
            'reasons' => PaymentRefund::REASONS, 'rejectReasons' => PaymentRefund::REJECT_REASONS,
            'methods' => $canRecord ? PaymentMethod::query()->where('organisation_id', $actor->organisation_id)->operational()->orderBy('sort_order')->orderBy('name')->get()
                ->map(fn (PaymentMethod $m): array => ['id' => $m->id, 'name' => $m->name])->all() : [],
            'visitCompleted' => ! $visitOpen,
            'receipts' => $payments->map(function (Payment $p) use ($refunds, $people, $reversals, $actor, $timezone, $canRecord, $canApprove, $visitOpen): array {
                $history = ($refunds[$p->id] ?? collect())->map(fn (PaymentRefund $r): array => [
                    'publicId' => $r->public_id, 'amountSen' => $r->amount_sen, 'method' => $r->method_snapshot, 'reason' => PaymentRefund::REASONS[$r->reason_code] ?? $r->reason_code,
                    'paidOutAt' => $r->paid_out_at->setTimezone($timezone)->format('j M Y, g:i A'), 'recordedBy' => $people[$r->recorded_by_user_id] ?? 'Staff member',
                    'state' => $r->decision->decision ?? 'waiting', 'decidedBy' => $r->decision ? ($people[$r->decision->decided_by_user_id] ?? 'Staff member') : null,
                    'rejectReason' => $r->decision?->reason_code ? (PaymentRefund::REJECT_REASONS[$r->decision->reason_code] ?? $r->decision->reason_code) : null,
                    'canDecide' => $canApprove && $r->decision === null && $r->recorded_by_user_id !== $actor->id,
                    'recordedByYou' => $r->recorded_by_user_id === $actor->id,
                ])->values()->all();
                $open = collect($history)->first(fn (array $h): bool => $h['state'] !== 'rejected');

                return ['publicId' => $p->public_id, 'number' => $p->receipt_number, 'method' => $p->method_snapshot, 'amountSen' => (int) ($reversals[$p->id] ?? $p->amount_sen),
                    'canRecord' => $canRecord && $open === null && $visitOpen, 'refunds' => $history];
            })->values()->all(),
        ];
    }

    /** The receipt's refund that is waiting or approved (a rejected one no longer counts). */
    public function openRefund(Payment $payment): ?PaymentRefund
    {
        return PaymentRefund::query()->where('payment_id', $payment->id)->whereDoesntHave('decision', fn ($d) => $d->where('decision', 'rejected'))->first();
    }

    private function visitPayment(Visit $visit, string $publicId, bool $lock): Payment
    {
        $query = Payment::query()->where('organisation_id', $visit->organisation_id)->where('branch_id', $visit->branch_id)->where('public_id', $publicId)
            ->whereIn('id', PaymentAllocation::query()->whereIn('invoice_id', Invoice::query()->where('visit_id', $visit->id)->select('id'))->select('payment_id'));
        $payment = ($lock ? $query->lockForUpdate() : $query)->first();
        abort_if($payment === null, 404);

        return $payment;
    }

    private function key(mixed $value): string
    {
        if (! is_string($value) || ! Str::isUuid($value)) {
            throw ValidationException::withMessages(['idempotency_key' => 'Refresh this page and try again.']);
        }

        return $value;
    }
}
