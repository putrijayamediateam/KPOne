<?php

namespace App\Domain\Visit\Billing\Services;

use App\Domain\Visit\Billing\Models\CoverageAllocation;
use App\Domain\Visit\Billing\Models\Invoice;
use App\Domain\Visit\Billing\Models\PatientReceivable;
use App\Domain\Visit\Billing\Models\Payment;
use App\Domain\Visit\Billing\Models\PaymentAllocation;
use App\Domain\Visit\Billing\Models\PaymentReversal;
use Illuminate\Validation\ValidationException;

class FinancialLedger
{
    /** Parent Invoice must already be locked. Order is coverage, receivable, payments, allocations, reversals. */
    public function lock(Invoice $invoice): void
    {
        CoverageAllocation::query()->where('invoice_id', $invoice->id)->orderBy('id')->lockForUpdate()->get();
        PatientReceivable::query()->where('invoice_id', $invoice->id)->orderBy('id')->lockForUpdate()->get();
        $paymentIds = PaymentAllocation::query()->where('invoice_id', $invoice->id)->pluck('payment_id');
        Payment::query()->whereIn('id', $paymentIds)->orderBy('id')->lockForUpdate()->get();
        PaymentAllocation::query()->where('invoice_id', $invoice->id)->orderBy('id')->lockForUpdate()->get();
        PaymentReversal::query()->where('invoice_id', $invoice->id)->orderBy('id')->lockForUpdate()->get();
    }

    /** @return array{total: int, self_pay: int, panel: int, deferred: int, due_now: int} */
    public function state(Invoice $invoice): array
    {
        $paid = (int) PaymentAllocation::query()->where('invoice_id', $invoice->id)->sum('amount_sen')
            - (int) PaymentReversal::query()->where('invoice_id', $invoice->id)->sum('amount_sen');
        $panel = (int) CoverageAllocation::query()->where('invoice_id', $invoice->id)->where('status', 'approved')->sum('amount_sen');
        $deferred = (int) PatientReceivable::query()->where('invoice_id', $invoice->id)->where('status', 'approved')->sum('remaining_sen');
        $due = $invoice->total_sen - $paid - $panel - $deferred;
        if ($paid < 0 || $due < 0) {
            throw ValidationException::withMessages(['invoice' => 'Financial evidence does not reconcile. Review is required.']);
        }

        return ['total' => $invoice->total_sen, 'self_pay' => $paid, 'panel' => $panel, 'deferred' => $deferred, 'due_now' => $due];
    }

    public function requireMutable(Invoice $invoice, mixed $version): void
    {
        if ($invoice->status !== 'finalized' || $invoice->correction_hold || $invoice->source_stale || $invoice->lock_version !== filter_var($version, FILTER_VALIDATE_INT)) {
            throw ValidationException::withMessages(['invoice' => 'The finalized Invoice changed or is on hold. Reload before continuing.']);
        }
    }
}
