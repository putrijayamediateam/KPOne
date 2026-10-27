<?php

namespace App\Domain\Visit\Billing\Services;

use App\Domain\Visit\Billing\Models\CoverageAllocation;
use App\Domain\Visit\Billing\Models\Invoice;
use App\Domain\Visit\Billing\Models\PatientReceivable;
use App\Domain\Visit\Billing\Models\Payment;
use App\Domain\Visit\Billing\Models\PaymentAllocation;
use App\Domain\Visit\Billing\Models\PaymentReversal;
use Illuminate\Support\Collection;
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

        return $this->calculate($invoice, $paid, $panel, $deferred, InvoiceAdjustmentService::summary($invoice)['adjustmentSen']);
    }

    /** Same measures and reconciliation as state(), in bounded caller-selected batches.
     * @param  Collection<int, Invoice>  $invoices
     * @return array<int, array{total:int,self_pay:int,panel:int,deferred:int,due_now:int}>
     */
    public function states(Collection $invoices): array
    {
        if ($invoices->isEmpty()) {
            return [];
        }
        $ids = array_values($invoices->map(fn (Invoice $invoice): int => $invoice->id)->all());
        $paid = PaymentAllocation::query()->whereIn('invoice_id', $ids)->selectRaw('invoice_id, SUM(amount_sen) AS amount')->groupBy('invoice_id')->pluck('amount', 'invoice_id');
        $reversed = PaymentReversal::query()->whereIn('invoice_id', $ids)->selectRaw('invoice_id, SUM(amount_sen) AS amount')->groupBy('invoice_id')->pluck('amount', 'invoice_id');
        $panel = CoverageAllocation::query()->whereIn('invoice_id', $ids)->where('status', 'approved')->selectRaw('invoice_id, SUM(amount_sen) AS amount')->groupBy('invoice_id')->pluck('amount', 'invoice_id');
        $deferred = PatientReceivable::query()->whereIn('invoice_id', $ids)->where('status', 'approved')->selectRaw('invoice_id, SUM(remaining_sen) AS amount')->groupBy('invoice_id')->pluck('amount', 'invoice_id');
        $adjustments = InvoiceAdjustmentService::activeForInvoices($ids);
        $states = [];
        foreach ($invoices as $invoice) {
            $adjustment = $adjustments->get($invoice->id);
            $net = InvoiceAdjustmentService::net($adjustment);
            $states[$invoice->id] = $this->calculate($invoice, (int) $paid->get($invoice->id, 0) - (int) $reversed->get($invoice->id, 0), (int) $panel->get($invoice->id, 0), (int) $deferred->get($invoice->id, 0), $net);
        }

        return $states;
    }

    /** @return array{total:int,self_pay:int,panel:int,deferred:int,due_now:int} */
    private function calculate(Invoice $invoice, int $paid, int $panel, int $deferred, int $adjustment): array
    {
        $total = $invoice->total_sen + $adjustment;
        $due = $total - $paid - $panel - $deferred;
        if ($paid < 0 || $due < 0) {
            throw ValidationException::withMessages(['invoice' => 'Financial evidence does not reconcile. Review is required.']);
        }

        return ['total' => $total, 'self_pay' => $paid, 'panel' => $panel, 'deferred' => $deferred, 'due_now' => $due];
    }

    public function requireMutable(Invoice $invoice, mixed $version): void
    {
        if ($invoice->status !== 'finalized' || $invoice->correction_hold || $invoice->source_stale || $invoice->lock_version !== filter_var($version, FILTER_VALIDATE_INT)) {
            throw ValidationException::withMessages(['invoice' => 'The finalized Invoice changed or is on hold. Reload before continuing.']);
        }
    }
}
