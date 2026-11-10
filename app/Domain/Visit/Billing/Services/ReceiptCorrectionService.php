<?php

namespace App\Domain\Visit\Billing\Services;

use App\Domain\Access\StaffAccessService;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Organisation\Models\Organisation;
use App\Domain\Visit\Billing\Models\Invoice;
use App\Domain\Visit\Billing\Models\PatientReceivable;
use App\Domain\Visit\Billing\Models\Payment;
use App\Domain\Visit\Billing\Models\PaymentAllocation;
use App\Domain\Visit\Billing\Models\PaymentCorrection;
use App\Domain\Visit\Billing\Models\PaymentMethod;
use App\Domain\Visit\Billing\Models\PaymentReversal;
use App\Domain\Visit\Models\Visit;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * BIL-16 (owner decisions 2026-11-10): correct a receipt recorded in error on a completed visit, in one step.
 *
 * A Finance Officer (payments.reverse.branch) who did not record the receipt reverses it as a recording error and, in
 * the same transaction, records a replacement receipt for what was really received (or none). Only money not really
 * received becomes due. It takes effect at once and works on any date; an approved cash closing day then shows
 * "changed after approval". It is never a refund (BIL-10), and invoice totals, Panel and pay-later approvals do not
 * change. A receipt that paid off pay-later keeps that part: the replacement carries the same pay-later amount,
 * because an approved pay-later balance can never grow back.
 */
class ReceiptCorrectionService
{
    public const PERMISSION = 'payments.reverse.branch';

    public function __construct(
        private BillingContextService $context,
        private FinancialLedger $ledger,
        private BillingNumberGenerator $numbers,
        private RefundService $refunds,
        private AuditRecorder $audit,
        private StaffAccessService $staff,
    ) {}

    /** @param array<string, mixed> $a */
    public function correct(User $actor, Visit $visit, Invoice $invoice, Payment $payment, array $a): PaymentCorrection
    {
        Validator::make($a, [
            'reason' => ['required', 'string', 'max:500'], 'recording_error_only' => ['required', 'accepted'],
            'idempotency_key' => ['required', 'uuid'], 'method' => ['nullable', 'string', 'max:40'],
            'reference' => ['nullable', 'string', 'max:100', 'regex:/\A[A-Za-z0-9 ._\/-]+\z/'],
        ], ['recording_error_only.accepted' => 'Confirm this receipt was recorded in error. A refund is a different action.'])->validate();
        $received = ExactMoney::sen($a['received_sen'] ?? null, 'received_sen');
        $method = $received > 0 ? (string) ($a['method'] ?? '') : null;
        if ($received > 0 && $method === '') {
            throw ValidationException::withMessages(['method' => 'Choose how the money was really received.']);
        }

        return DB::transaction(function () use ($actor, $visit, $invoice, $payment, $a, $received, $method): PaymentCorrection {
            [$actor, $visit, $branch] = $this->context->lock($actor, $visit, $a['expected_branch_id'] ?? null, self::PERMISSION);
            abort_unless($actor->can('payments.add.branch'), 403);
            Organisation::query()->whereKey($actor->organisation_id)->sharedLock()->firstOrFail();
            $invoice = Invoice::query()->whereKey($invoice->id)->where('visit_id', $visit->id)->where('organisation_id', $actor->organisation_id)->lockForUpdate()->firstOrFail();
            $this->ledger->lock($invoice);
            $hash = hash('sha256', json_encode([$invoice->public_id, $payment->public_id, $actor->id, $received, $method, $a['reference'] ?? null, $a['reason']], JSON_THROW_ON_ERROR));
            $existing = PaymentCorrection::query()->where('organisation_id', $actor->organisation_id)->where('idempotency_key', $a['idempotency_key'])->first();
            if ($existing !== null) {
                if ($existing->payload_hash !== $hash || $existing->branch_id !== $branch->id) {
                    throw ValidationException::withMessages(['idempotency_key' => 'This request was already used for a different correction. Reload and try again.']);
                }

                return $existing;
            }
            if ($visit->status !== Visit::STATUS_COMPLETED) {
                throw ValidationException::withMessages(['correction' => 'Only a completed visit\'s receipt is corrected here. Before completion, reverse it instead.']);
            }
            $this->ledger->requireMutable($invoice, $a['lock_version'] ?? null);
            $payment = Payment::query()->whereKey($payment->id)->where('organisation_id', $actor->organisation_id)->where('branch_id', $branch->id)->lockForUpdate()->firstOrFail();
            $allocations = PaymentAllocation::query()->where('payment_id', $payment->id)->get();
            $allocation = $allocations->first();
            abort_if($allocation === null || $allocation->invoice_id !== $invoice->id, 404);
            if ($payment->status !== 'posted' || $payment->lock_version !== filter_var($a['payment_lock_version'] ?? null, FILTER_VALIDATE_INT)) {
                throw ValidationException::withMessages(['correction' => 'This receipt changed. Reload the page.']);
            }
            if ($payment->recorded_by_user_id === $actor->id) {
                throw ValidationException::withMessages(['correction' => 'Someone other than the person who recorded this receipt must correct it.']);
            }
            if ($allocations->count() !== 1 || (int) $allocation->amount_sen !== (int) $payment->amount_sen) {
                throw ValidationException::withMessages(['correction' => 'This receipt pays more than one invoice, so it cannot be corrected here.']);
            }
            if ($this->refunds->openRefund($payment) !== null) {
                throw ValidationException::withMessages(['correction' => 'This receipt has a refund, so it cannot be corrected.']);
            }
            if ($received > (int) $payment->amount_sen) {
                throw ValidationException::withMessages(['received_sen' => 'A correction cannot record more than the receipt being corrected.']);
            }
            $payLater = (int) $allocation->deferment_applied_sen;
            if ($payLater > 0 && $received < $payLater) {
                throw ValidationException::withMessages(['received_sen' => 'This receipt paid off pay-later, which cannot grow back. The amount received cannot be less than '.number_format($payLater / 100, 2).'.']);
            }
            $debt = $payLater > 0 ? PatientReceivable::query()->whereKey($allocation->patient_receivable_id)->where('status', 'approved')->lockForUpdate()->first() : null;
            if ($payLater > 0 && $debt === null) {
                throw ValidationException::withMessages(['correction' => 'This receipt\'s pay-later is no longer approved, so it cannot be corrected here.']);
            }
            $paymentMethod = null;
            if ($method !== null) {
                $paymentMethod = PaymentMethod::query()->where('organisation_id', $actor->organisation_id)->where('code', $method)->lockForUpdate()->first();
                if ($paymentMethod === null || ! $paymentMethod->is_active || ($paymentMethod->requires_reference && empty($a['reference']))) {
                    throw ValidationException::withMessages(['method' => 'Select an active method and its required reference.']);
                }
            }

            $reversal = new PaymentReversal;
            $reversal->forceFill(['public_id' => (string) Str::uuid(), 'organisation_id' => $actor->organisation_id, 'branch_id' => $branch->id, 'invoice_id' => $invoice->id,
                'payment_id' => $payment->id, 'amount_sen' => $allocation->amount_sen, 'reason' => $a['reason'], 'approved_by_user_id' => $actor->id, 'reversed_at' => now()->utc()])->save();
            $payment->forceFill(['status' => 'reversed', 'lock_version' => $payment->lock_version + 1])->save();

            $replacement = null;
            if ($paymentMethod !== null) {
                $replacement = new Payment;
                $replacement->forceFill(['public_id' => (string) Str::uuid(), 'organisation_id' => $actor->organisation_id, 'branch_id' => $branch->id, 'patient_id' => $visit->patient_id,
                    'payment_method_id' => $paymentMethod->id, 'receipt_number' => $this->numbers->next($actor->organisation_id, 'receipt'), 'amount_sen' => $received, 'currency' => 'MYR',
                    'method_snapshot' => $paymentMethod->name, 'status' => 'posted', 'reference' => $a['reference'] ?? null, 'idempotency_key' => (string) Str::uuid(),
                    'payload_hash' => $hash, 'received_at' => now()->utc(), 'recorded_by_user_id' => $actor->id, 'lock_version' => 1])->save();
                $replacementAllocation = new PaymentAllocation;
                $replacementAllocation->forceFill(['public_id' => (string) Str::uuid(), 'organisation_id' => $actor->organisation_id, 'branch_id' => $branch->id, 'invoice_id' => $invoice->id,
                    'payment_id' => $replacement->id, 'amount_sen' => $received, 'patient_receivable_id' => $debt?->id, 'deferment_applied_sen' => $payLater])->save();
                if ($debt !== null && $debt->last_payment_id === $payment->id) {
                    $debt->forceFill(['last_payment_id' => $replacement->id, 'lock_version' => $debt->lock_version + 1])->save();
                }
            }

            $correction = new PaymentCorrection;
            $correction->forceFill(['public_id' => (string) Str::uuid(), 'organisation_id' => $actor->organisation_id, 'branch_id' => $branch->id, 'invoice_id' => $invoice->id,
                'original_payment_id' => $payment->id, 'payment_reversal_id' => $reversal->id, 'replacement_payment_id' => $replacement?->id, 'received_sen' => $received,
                'corrected_by_user_id' => $actor->id, 'idempotency_key' => $a['idempotency_key'], 'payload_hash' => $hash, 'created_at' => now()->utc()]);
            try {
                $correction->save();
            } catch (UniqueConstraintViolationException) {
                // The same key raced on another invoice; the first one won.
                throw ValidationException::withMessages(['idempotency_key' => 'This request was already used for a different correction. Reload and try again.']);
            }
            $invoice->forceFill(['lock_version' => $invoice->lock_version + 1])->save();
            $this->ledger->state($invoice);
            $this->audit->record('billing.receipt_corrected', $invoice, ['record_version' => $invoice->lock_version, 'reversed_sen' => (int) $allocation->amount_sen,
                'received_sen' => $received, 'replacement' => $replacement !== null], $actor, $branch);

            return $correction;
        }, 3);
    }

    /**
     * The correction panel for a completed visit's Billing page: its posted receipts on the current invoice and the
     * corrections already made. Null when the viewer may not correct or the visit is not completed.
     *
     * @return array<string, mixed>|null
     */
    public function forVisit(User $actor, Visit $visit, ?Invoice $invoice, string $timezone): ?array
    {
        if ($visit->status !== Visit::STATUS_COMPLETED || $invoice === null || $invoice->status !== 'finalized'
            || ! $actor->can(self::PERMISSION) || ! $actor->can('payments.add.branch')) {
            return null;
        }
        $allocations = PaymentAllocation::query()->where('invoice_id', $invoice->id)->get()->keyBy('payment_id');
        $payments = Payment::query()->whereIn('id', $allocations->keys())->orderBy('id')->get();
        $corrections = PaymentCorrection::query()->where('invoice_id', $invoice->id)->orderBy('id')->get();
        if ($payments->where('status', 'posted')->isEmpty() && $corrections->isEmpty()) {
            return null;
        }
        $splits = PaymentAllocation::query()->whereIn('payment_id', $payments->pluck('id'))->selectRaw('payment_id, COUNT(*) AS n')->groupBy('payment_id')->pluck('n', 'payment_id');
        $numbers = $payments->pluck('receipt_number', 'id');
        $people = $this->staff->visibleUsers($actor)->whereIn('id', $corrections->pluck('corrected_by_user_id')->unique())->pluck('name', 'id');
        $reasons = PaymentReversal::query()->whereIn('id', $corrections->pluck('payment_reversal_id'))->pluck('reason', 'id');

        return [
            'invoiceLockVersion' => $invoice->lock_version,
            'methods' => PaymentMethod::query()->where('organisation_id', $actor->organisation_id)->operational()->orderBy('sort_order')->orderBy('name')->get()
                ->map(fn (PaymentMethod $m): array => ['code' => $m->code, 'name' => $m->name, 'requiresReference' => $m->requires_reference])->all(),
            'receipts' => $payments->where('status', 'posted')->map(function (Payment $p) use ($allocations, $splits, $actor): array {
                $refused = match (true) {
                    $p->recorded_by_user_id === $actor->id => 'You recorded this receipt, so someone else must correct it.',
                    (int) ($splits[$p->id] ?? 0) !== 1 => 'This receipt pays more than one invoice.',
                    $this->refunds->openRefund($p) !== null => 'This receipt has a refund, so it cannot be corrected.',
                    default => null,
                };

                return ['publicId' => $p->public_id, 'number' => $p->receipt_number, 'method' => $p->method_snapshot, 'amountSen' => (int) $p->amount_sen,
                    'payLaterSen' => (int) ($allocations[$p->id]->deferment_applied_sen ?? 0), 'lockVersion' => $p->lock_version, 'refused' => $refused];
            })->values()->all(),
            'corrections' => $corrections->map(fn (PaymentCorrection $c): array => [
                'publicId' => $c->public_id, 'original' => $numbers[$c->original_payment_id] ?? '', 'replacement' => $c->replacement_payment_id ? ($numbers[$c->replacement_payment_id] ?? '') : null,
                'receivedSen' => $c->received_sen, 'reason' => (string) ($reasons[$c->payment_reversal_id] ?? ''), 'by' => $people[$c->corrected_by_user_id] ?? 'Staff member',
                'at' => $c->created_at->setTimezone($timezone)->format('j M Y, g:i A'),
            ])->values()->all(),
        ];
    }
}
