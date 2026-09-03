<?php

namespace App\Domain\Visit\Billing\Services;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Visit\Billing\Models\CoverageAllocation;
use App\Domain\Visit\Billing\Models\Invoice;
use App\Domain\Visit\Billing\Models\PatientReceivable;
use App\Domain\Visit\Billing\Models\Payment;
use App\Domain\Visit\Billing\Models\PaymentAllocation;
use App\Domain\Visit\Billing\Models\PaymentReversal;
use App\Domain\Visit\Models\Visit;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class InvoiceCorrectionService
{
    public function __construct(private BillingContextService $context, private FinancialLedger $ledger, private AuditRecorder $audit) {}

    /** Recording error only, never a cash refund. Post-completion corrections are excluded from v1.
     * @param  array<string,mixed>  $a
     */
    public function reverse(User $actor, Visit $visit, Invoice $invoice, Payment $payment, array $a): void
    {
        Validator::make($a, ['reason' => ['required', 'string', 'max:500'], 'recording_error_only' => ['required', 'accepted']])->validate();
        DB::transaction(function () use ($actor, $visit, $invoice, $payment, $a): void {
            [$actor, $visit, $branch] = $this->context->lock($actor, $visit, $a['expected_branch_id'] ?? null, 'payments.reverse.branch');
            $invoice = Invoice::query()->whereKey($invoice->id)->where('visit_id', $visit->id)->lockForUpdate()->firstOrFail();
            $this->ledger->requireMutable($invoice, $a['lock_version'] ?? null);
            $this->ledger->lock($invoice);
            $allocation = PaymentAllocation::query()->where('invoice_id', $invoice->id)->where('payment_id', $payment->id)->firstOrFail();
            $payment = Payment::query()->findOrFail($allocation->payment_id);
            if ($visit->status !== Visit::STATUS_REGISTERED || $payment->status !== 'posted' || $payment->recorded_by_user_id === $actor->id
                || $payment->lock_version !== filter_var($a['payment_lock_version'] ?? null, FILTER_VALIDATE_INT)) {
                $this->invalid();
            }
            $reversal = new PaymentReversal;
            $reversal->forceFill(['public_id' => (string) Str::uuid(), 'organisation_id' => $actor->organisation_id, 'branch_id' => $branch->id, 'invoice_id' => $invoice->id,
                'payment_id' => $payment->id, 'amount_sen' => $allocation->amount_sen, 'reason' => $a['reason'], 'approved_by_user_id' => $actor->id, 'reversed_at' => now()->utc()])->save();
            $payment->forceFill(['status' => 'reversed', 'lock_version' => $payment->lock_version + 1])->save();
            // Do not enlarge or resurrect any prior deferment: new due needs fresh independent approval.
            foreach (PatientReceivable::query()->where('current_invoice_guard', $invoice->id)->get() as $debt) {
                $debt->forceFill(['status' => 'superseded', 'current_invoice_guard' => null, 'lock_version' => $debt->lock_version + 1])->save();
            }
            $invoice->forceFill(['lock_version' => $invoice->lock_version + 1])->save();
            $this->ledger->state($invoice);
            $this->audit->record('billing.payment_recording_reversed', $invoice, ['record_version' => $invoice->lock_version], $actor, $branch);
        }, 3);
    }

    /** @param array<string,mixed> $a */
    public function void(User $actor, Visit $visit, Invoice $invoice, array $a): void
    {
        Validator::make($a, ['reason' => ['required', 'string', 'max:500']])->validate();
        DB::transaction(function () use ($actor, $visit, $invoice, $a): void {
            [$actor, $visit, $branch] = $this->context->lock($actor, $visit, $a['expected_branch_id'] ?? null, 'invoices.void.branch');
            $invoice = Invoice::query()->whereKey($invoice->id)->where('visit_id', $visit->id)->lockForUpdate()->firstOrFail();
            $this->ledger->requireMutable($invoice, $a['lock_version'] ?? null);
            $this->ledger->lock($invoice);
            $state = $this->ledger->state($invoice);
            if ($visit->status !== Visit::STATUS_REGISTERED || $state['self_pay'] !== 0 || $state['panel'] !== 0 || $state['deferred'] !== 0) {
                $this->invalid();
            }
            foreach ([CoverageAllocation::class, PatientReceivable::class] as $class) {
                foreach ($class::query()->where('current_invoice_guard', $invoice->id)->get() as $proposal) {
                    $proposal->forceFill(['status' => 'superseded', 'current_invoice_guard' => null, 'lock_version' => $proposal->lock_version + 1])->save();
                }
            }
            $invoice->forceFill(['status' => 'voided', 'current_visit_guard' => null, 'voided_at' => now()->utc(), 'voided_by_user_id' => $actor->id, 'void_reason' => $a['reason'], 'lock_version' => $invoice->lock_version + 1])->save();
            $this->audit->record('billing.invoice_voided', $invoice, ['record_version' => $invoice->lock_version], $actor, $branch);
        }, 3);
    }

    private function invalid(): never
    {
        throw ValidationException::withMessages(['correction' => 'This correction requires independent authority before Visit completion and fully reconciled evidence. It is not a refund.']);
    }
}
