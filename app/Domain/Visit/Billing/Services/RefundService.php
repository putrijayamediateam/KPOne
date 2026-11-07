<?php

namespace App\Domain\Visit\Billing\Services;

use App\Domain\Access\StaffAccessService;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Visit\Billing\Models\Invoice;
use App\Domain\Visit\Billing\Models\InvoiceCredit;
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
        if ($visit->status === Visit::STATUS_COMPLETED) {
            return $this->recordAfterCompletion($actor, $visit, $paymentPublicId, $input);
        }
        $reason = $input['reason_code'] ?? null;
        if (! is_string($reason) || ! array_key_exists($reason, PaymentRefund::REASONS)) {
            throw ValidationException::withMessages(['reason_code' => 'Choose why the money was refunded.']);
        }
        $key = $this->key($input['idempotency_key'] ?? null);

        return DB::transaction(function () use ($actor, $visit, $paymentPublicId, $input, $reason, $key): PaymentRefund {
            [$actor, $visit, $branch] = $this->context->lock($actor, $visit, $input['expected_branch_id'] ?? null, self::RECORD_PERMISSION);
            $payment = $this->visitPayment($visit, $paymentPublicId, true);
            if ($visit->status !== Visit::STATUS_REGISTERED) {
                throw ValidationException::withMessages(['refund' => 'This visit changed. Reload the page.']);
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

    /**
     * BIL-10 (owner decisions 2026-11-06): after completion, part or all of a posted receipt, never more than that
     * receipt less its earlier refunds that are not rejected, with an invoice credit of the same amount. Nothing
     * changes on the invoice until another person approves it.
     *
     * @param  array<string, mixed>  $input
     */
    private function recordAfterCompletion(User $actor, Visit $visit, string $paymentPublicId, array $input): PaymentRefund
    {
        $key = $this->key($input['idempotency_key'] ?? null);

        return DB::transaction(function () use ($actor, $visit, $paymentPublicId, $input, $key): PaymentRefund {
            [$actor, $visit, $branch] = $this->context->lock($actor, $visit, $input['expected_branch_id'] ?? null, self::RECORD_PERMISSION);
            if ($visit->status !== Visit::STATUS_COMPLETED) {
                throw ValidationException::withMessages(['refund' => 'This visit changed. Reload the page.']);
            }
            $invoice = $this->lockReceiptInvoice($visit, $paymentPublicId);
            $payment = $this->visitPayment($visit, $paymentPublicId, true);
            $method = PaymentMethod::query()->where('organisation_id', $actor->organisation_id)->whereKey($input['payment_method_id'] ?? null)->operational()->first();
            if ($method === null) {
                throw ValidationException::withMessages(['payment_method_id' => 'Choose how the money was given back.']);
            }
            if ($payment->status !== 'posted') {
                throw ValidationException::withMessages(['refund' => 'Only a receipt that still stands can be refunded after completion.']);
            }
            $reason = $input['reason_code'] ?? null;
            if (! is_string($reason) || ! array_key_exists($reason, PaymentRefund::AFTER_COMPLETION_REASONS)) {
                throw ValidationException::withMessages(['reason_code' => 'Choose why the money was refunded.']);
            }
            $amount = filter_var($input['amount_sen'] ?? null, FILTER_VALIDATE_INT);
            if ($amount === false || $amount < 1) {
                throw ValidationException::withMessages(['amount_sen' => 'Enter the amount given back.']);
            }
            // A same-key retry returns the first refund before the remaining amount is checked again.
            $hash = hash('sha256', json_encode([$actor->id, $payment->id, $method->id, $reason, $amount, PaymentRefund::KIND_AFTER_COMPLETION], JSON_THROW_ON_ERROR));
            $existing = PaymentRefund::query()->where('organisation_id', $actor->organisation_id)->where('idempotency_key', $key)->first();
            if ($existing !== null) {
                return $existing->payload_hash === $hash ? $existing : throw ValidationException::withMessages(['refund' => 'This request was already used for a different refund. Reload and try again.']);
            }
            if ($amount > $this->refundable($payment)) {
                throw ValidationException::withMessages(['amount_sen' => 'This is more than is left to refund on this receipt.']);
            }

            $refund = new PaymentRefund;
            $refund->forceFill(['public_id' => (string) Str::uuid(), 'organisation_id' => $actor->organisation_id, 'branch_id' => $branch->id, 'payment_id' => $payment->id,
                'payment_method_id' => $method->id, 'method_snapshot' => $method->name, 'amount_sen' => $amount, 'kind' => PaymentRefund::KIND_AFTER_COMPLETION,
                'reason_code' => $reason, 'recorded_by_user_id' => $actor->id, 'paid_out_at' => now()->utc(), 'idempotency_key' => $key, 'payload_hash' => $hash])->save();
            $credit = new InvoiceCredit;
            $credit->forceFill(['organisation_id' => $actor->organisation_id, 'branch_id' => $branch->id, 'invoice_id' => $invoice->id,
                'payment_refund_id' => $refund->id, 'amount_sen' => $amount])->save();
            $this->audit->record('billing.refund_recorded', $refund, ['amount_sen' => $amount, 'payment_method_id' => $method->id, 'kind' => PaymentRefund::KIND_AFTER_COMPLETION, 'reason_code' => $reason], $actor, $branch);

            return $refund;
        }, 3);
    }

    /** What is left to refund on a posted receipt: its amount less its refunds that are not rejected. */
    public function refundable(Payment $payment): int
    {
        $refunded = (int) PaymentRefund::query()->where('payment_id', $payment->id)->whereDoesntHave('decision', fn ($d) => $d->where('decision', 'rejected'))->sum('amount_sen');

        return max(0, (int) $payment->amount_sen - $refunded);
    }

    /** Locks the receipt's invoice and its ledger evidence (the ledger's own order), before the receipt is read. */
    private function lockReceiptInvoice(Visit $visit, string $paymentPublicId): Invoice
    {
        $invoiceId = PaymentAllocation::query()->whereIn('invoice_id', Invoice::query()->where('visit_id', $visit->id)->select('id'))
            ->whereIn('payment_id', Payment::query()->where('public_id', $paymentPublicId)->where('organisation_id', $visit->organisation_id)->where('branch_id', $visit->branch_id)->select('id'))
            ->value('invoice_id');
        abort_if($invoiceId === null, 404);
        $invoice = Invoice::query()->whereKey($invoiceId)->lockForUpdate()->firstOrFail();
        app(FinancialLedger::class)->lock($invoice);
        if ($invoice->status !== 'finalized') {
            throw ValidationException::withMessages(['refund' => 'This invoice cannot take a refund after completion.']);
        }

        return $invoice;
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
            if ($refund->kind === PaymentRefund::KIND_AFTER_COMPLETION) {
                // BIL-10: approving changes the invoice's figures, so lock the invoice and its ledger first.
                $this->lockReceiptInvoice($visit, (string) Payment::query()->whereKey($refund->payment_id)->value('public_id'));
            }
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
        $visitOpen = $visit->status === Visit::STATUS_REGISTERED;
        // BIL-10: once the visit is completed, its posted receipts can be refunded too (part or all).
        $payments = Payment::query()->whereIn('status', $visit->status === Visit::STATUS_COMPLETED ? ['reversed', 'posted'] : ['reversed'])
            ->whereIn('id', PaymentAllocation::query()->whereIn('invoice_id', Invoice::query()->where('visit_id', $visit->id)->select('id'))->select('payment_id'))
            ->orderBy('id')->get();
        if ($payments->isEmpty()) {
            return null;
        }
        $refunds = PaymentRefund::query()->with('decision')->whereIn('payment_id', $payments->pluck('id'))->orderBy('id')->get()->groupBy('payment_id');
        $people = $this->staff->visibleUsers($actor)->whereIn('id', $refunds->flatten()->pluck('recorded_by_user_id')
            ->merge($refunds->flatten()->pluck('decision.decided_by_user_id'))->filter()->unique())->pluck('name', 'id');
        $reversals = PaymentReversal::query()->whereIn('payment_id', $payments->pluck('id'))->pluck('amount_sen', 'payment_id');

        return [
            'can' => ['record' => $canRecord, 'approve' => $canApprove],
            'reasons' => PaymentRefund::REASONS, 'afterCompletionReasons' => PaymentRefund::AFTER_COMPLETION_REASONS, 'rejectReasons' => PaymentRefund::REJECT_REASONS,
            'methods' => $canRecord ? PaymentMethod::query()->where('organisation_id', $actor->organisation_id)->operational()->orderBy('sort_order')->orderBy('name')->get()
                ->map(fn (PaymentMethod $m): array => ['id' => $m->id, 'name' => $m->name])->all() : [],
            'visitCompleted' => ! $visitOpen,
            'receipts' => $payments->map(function (Payment $p) use ($refunds, $people, $reversals, $actor, $timezone, $canRecord, $canApprove, $visitOpen): array {
                $history = ($refunds[$p->id] ?? collect())->map(fn (PaymentRefund $r): array => [
                    'publicId' => $r->public_id, 'amountSen' => $r->amount_sen, 'method' => $r->method_snapshot,
                    'reason' => (PaymentRefund::REASONS + PaymentRefund::AFTER_COMPLETION_REASONS)[$r->reason_code] ?? $r->reason_code,
                    'paidOutAt' => $r->paid_out_at->setTimezone($timezone)->format('j M Y, g:i A'), 'recordedBy' => $people[$r->recorded_by_user_id] ?? 'Staff member',
                    'state' => $r->decision->decision ?? 'waiting', 'decidedBy' => $r->decision ? ($people[$r->decision->decided_by_user_id] ?? 'Staff member') : null,
                    'rejectReason' => $r->decision?->reason_code ? (PaymentRefund::REJECT_REASONS[$r->decision->reason_code] ?? $r->decision->reason_code) : null,
                    'canDecide' => $canApprove && $r->decision === null && $r->recorded_by_user_id !== $actor->id,
                    'recordedByYou' => $r->recorded_by_user_id === $actor->id,
                ])->values()->all();
                $open = collect($history)->first(fn (array $h): bool => $h['state'] !== 'rejected');
                if ($p->status === 'posted') {
                    // BIL-10: a posted receipt of a completed visit; refunds that are not rejected use up its amount.
                    $left = max(0, (int) $p->amount_sen - (int) collect($history)->where('state', '!=', 'rejected')->sum('amountSen'));

                    return ['publicId' => $p->public_id, 'number' => $p->receipt_number, 'method' => $p->method_snapshot, 'amountSen' => (int) $p->amount_sen,
                        'kind' => PaymentRefund::KIND_AFTER_COMPLETION, 'refundableSen' => $left, 'canRecord' => $canRecord && $left > 0, 'refunds' => $history];
                }

                return ['publicId' => $p->public_id, 'number' => $p->receipt_number, 'method' => $p->method_snapshot, 'amountSen' => (int) ($reversals[$p->id] ?? $p->amount_sen),
                    'kind' => PaymentRefund::KIND_REVERSAL, 'refundableSen' => $open === null ? (int) ($reversals[$p->id] ?? $p->amount_sen) : 0,
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
