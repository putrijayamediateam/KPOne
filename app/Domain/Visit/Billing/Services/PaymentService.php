<?php

namespace App\Domain\Visit\Billing\Services;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Visit\Billing\Models\Invoice;
use App\Domain\Visit\Billing\Models\PatientReceivable;
use App\Domain\Visit\Billing\Models\Payment;
use App\Domain\Visit\Billing\Models\PaymentAllocation;
use App\Domain\Visit\Billing\Models\PaymentMethod;
use App\Domain\Visit\Models\Visit;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PaymentService
{
    public function __construct(private BillingContextService $context, private FinancialLedger $ledger, private BillingNumberGenerator $numbers, private AuditRecorder $audit) {}

    /** @param array<string,mixed> $a */
    public function add(User $actor, Visit $visit, Invoice $invoice, array $a): Payment
    {
        Validator::make($a, ['idempotency_key' => ['required', 'uuid'], 'method' => ['required', 'string', 'max:40'], 'reference' => ['nullable', 'string', 'max:100', 'regex:/\A[A-Za-z0-9 ._\/-]+\z/']])->validate();
        $amount = ExactMoney::sen($a['amount_sen'] ?? null);
        if ($amount === 0) {
            throw ValidationException::withMessages(['amount_sen' => 'A receipt must be positive.']);
        }

        return DB::transaction(function () use ($actor, $visit, $invoice, $a, $amount): Payment {
            [$actor, $visit, $branch] = $this->context->lock($actor, $visit, $a['expected_branch_id'] ?? null, 'payments.add.branch');
            $invoice = Invoice::query()->whereKey($invoice->id)->where('visit_id', $visit->id)->where('organisation_id', $actor->organisation_id)->lockForUpdate()->firstOrFail();
            $this->ledger->lock($invoice);
            $hash = hash('sha256', json_encode([$invoice->public_id, $actor->id, $amount, $a['method'], $a['reference'] ?? null], JSON_THROW_ON_ERROR));
            $existing = Payment::query()->where('organisation_id', $actor->organisation_id)->where('idempotency_key', $a['idempotency_key'])->first();
            if ($existing) {
                if ($existing->payload_hash !== $hash || $existing->branch_id !== $branch->id) {
                    throw ValidationException::withMessages(['payment' => 'This request key was already used.']);
                }

                return $existing;
            }
            $this->ledger->requireMutable($invoice, $a['lock_version'] ?? null);
            $state = $this->ledger->state($invoice);
            if (! in_array($visit->status, [Visit::STATUS_REGISTERED, Visit::STATUS_COMPLETED], true) || $amount > $state['due_now'] + $state['deferred']
                || ($visit->status === Visit::STATUS_COMPLETED && ($state['due_now'] !== 0 || $amount > $state['deferred']))) {
                throw ValidationException::withMessages(['amount_sen' => 'Payment exceeds the originating Invoice amount available for collection.']);
            }
            $method = PaymentMethod::query()->where('organisation_id', $actor->organisation_id)->where('code', $a['method'])->where('is_active', true)->first();
            if (! $method || ($method->requires_reference && empty($a['reference']))) {
                throw ValidationException::withMessages(['method' => 'Select an active governed method and its required reference.']);
            }
            $receiptNumber = $this->numbers->next($actor->organisation_id, 'receipt');
            // The organisation counter also serializes same-key requests on different Invoices.
            if (Payment::query()->where('organisation_id', $actor->organisation_id)->where('idempotency_key', $a['idempotency_key'])->exists()) {
                throw ValidationException::withMessages(['payment' => 'This request key was already used.']);
            }
            $payment = new Payment;
            $payment->forceFill(['public_id' => (string) Str::uuid(), 'organisation_id' => $actor->organisation_id, 'branch_id' => $branch->id, 'patient_id' => $visit->patient_id,
                'payment_method_id' => $method->id, 'receipt_number' => $receiptNumber, 'amount_sen' => $amount, 'currency' => 'MYR', 'method_snapshot' => $method->name,
                'status' => 'posted', 'reference' => $a['reference'] ?? null, 'idempotency_key' => $a['idempotency_key'], 'payload_hash' => $hash, 'received_at' => now()->utc(), 'recorded_by_user_id' => $actor->id, 'lock_version' => 1])->save();
            $allocation = new PaymentAllocation;
            $settled = max(0, $amount - $state['due_now']);
            $debt = $settled > 0 ? PatientReceivable::query()->where('invoice_id', $invoice->id)->where('status', 'approved')->sole() : null;
            $allocation->forceFill(['public_id' => (string) Str::uuid(), 'organisation_id' => $actor->organisation_id, 'branch_id' => $branch->id, 'invoice_id' => $invoice->id, 'payment_id' => $payment->id, 'amount_sen' => $amount, 'patient_receivable_id' => $debt?->id, 'deferment_applied_sen' => $settled])->save();
            if ($debt) {
                $remaining = $debt->remaining_sen - $settled;
                $debt->forceFill(['remaining_sen' => $remaining, 'last_payment_id' => $payment->id, 'settled_at' => $remaining === 0 ? now()->utc() : null, 'lock_version' => $debt->lock_version + 1])->save();
            }
            $invoice->forceFill(['lock_version' => $invoice->lock_version + 1])->save();
            $this->ledger->state($invoice);
            $this->audit->record('billing.payment_recorded', $invoice, ['record_version' => $invoice->lock_version], $actor, $branch);

            return $payment;
        }, 3);
    }
}
