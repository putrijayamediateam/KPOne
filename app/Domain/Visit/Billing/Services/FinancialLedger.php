<?php

namespace App\Domain\Visit\Billing\Services;

use App\Domain\Visit\Billing\Models\CoverageAllocation;
use App\Domain\Visit\Billing\Models\Invoice;
use App\Domain\Visit\Billing\Models\InvoiceCredit;
use App\Domain\Visit\Billing\Models\PatientReceivable;
use App\Domain\Visit\Billing\Models\Payment;
use App\Domain\Visit\Billing\Models\PaymentAllocation;
use App\Domain\Visit\Billing\Models\PaymentRefund;
use App\Domain\Visit\Billing\Models\PaymentRefundDecision;
use App\Domain\Visit\Billing\Models\PaymentReversal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
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
        $credit = (int) self::approvedCredits([$invoice->id])->sum('amount_sen');

        return $this->calculate($invoice, $paid, $panel, $deferred, InvoiceAdjustmentService::summary($invoice)['adjustmentSen'], $credit);
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
        // Reversals and approved BIL-10 credits in one read, so the batch stays within its query budget.
        $reversedAndCredited = DB::query()->fromSub(
            PaymentReversal::query()->whereIn('invoice_id', $ids)->selectRaw('invoice_id, amount_sen AS reversed, 0 AS credited')
                ->unionAll(self::approvedCredits($ids)->selectRaw('invoice_credits.invoice_id, 0 AS reversed, invoice_credits.amount_sen AS credited')), 'r')
            ->selectRaw('invoice_id, SUM(reversed) AS reversed, SUM(credited) AS credited')->groupBy('invoice_id')->get()->keyBy('invoice_id');
        $panel = CoverageAllocation::query()->whereIn('invoice_id', $ids)->where('status', 'approved')->selectRaw('invoice_id, SUM(amount_sen) AS amount')->groupBy('invoice_id')->pluck('amount', 'invoice_id');
        $deferred = PatientReceivable::query()->whereIn('invoice_id', $ids)->where('status', 'approved')->selectRaw('invoice_id, SUM(remaining_sen) AS amount')->groupBy('invoice_id')->pluck('amount', 'invoice_id');
        $adjustments = InvoiceAdjustmentService::activeForInvoices($ids);
        $states = [];
        foreach ($invoices as $invoice) {
            $adjustment = $adjustments->get($invoice->id);
            $net = InvoiceAdjustmentService::net($adjustment);
            $row = $reversedAndCredited->get($invoice->id);
            $states[$invoice->id] = $this->calculate($invoice, (int) $paid->get($invoice->id, 0) - (int) ($row->reversed ?? 0), (int) $panel->get($invoice->id, 0), (int) $deferred->get($invoice->id, 0), $net, (int) ($row->credited ?? 0));
        }

        return $states;
    }

    /**
     * BIL-10: invoice credits of approved refunds after completion, by invoice. A waiting or rejected one counts for nothing.
     *
     * @param  list<int>  $invoiceIds
     * @return Builder<InvoiceCredit>
     */
    public static function approvedCredits(array $invoiceIds): Builder
    {
        return InvoiceCredit::query()->whereIn('invoice_credits.invoice_id', $invoiceIds)
            ->whereIn('invoice_credits.payment_refund_id', PaymentRefundDecision::query()->where('decision', 'approved')->select('payment_refund_id'));
    }

    /**
     * BIL-10: what the invoice charges before refund credits (total plus any adjustment), the same figure as
     * PostgreSQL's kpone_invoice_payable. Panel claims use it: a refund credit gives back only the patient's money, so
     * it never changes what the Panel was billed against, and it must not read as an invoice discount there.
     */
    public function chargedBeforeCredits(Invoice $invoice): int
    {
        return $this->state($invoice)['total'] + (int) self::approvedCredits([$invoice->id])->sum('amount_sen');
    }

    /**
     * BIL-10: approved refunds after completion, summed per receipt, for the receipt lists.
     *
     * @param  iterable<int>  $paymentIds
     * @return array<int, int>
     */
    public static function approvedRefundsByPayment(iterable $paymentIds): array
    {
        return PaymentRefund::query()->whereIn('payment_id', collect($paymentIds)->all())->where('kind', PaymentRefund::KIND_AFTER_COMPLETION)
            ->whereIn('id', PaymentRefundDecision::query()->where('decision', 'approved')->select('payment_refund_id'))
            ->selectRaw('payment_id, SUM(amount_sen) AS amount')->groupBy('payment_id')->pluck('amount', 'payment_id')->map(fn ($v): int => (int) $v)->all();
    }

    /**
     * An approved credit lowers what the invoice charges, and its refund lowers what was paid, by the same amount, so
     * the amount due never moves (and the PostgreSQL payable and reconciliation checks, which see neither, still hold).
     *
     * @return array{total:int,self_pay:int,panel:int,deferred:int,due_now:int}
     */
    private function calculate(Invoice $invoice, int $paid, int $panel, int $deferred, int $adjustment, int $credit): array
    {
        $paid -= $credit;
        $total = $invoice->total_sen + $adjustment - $credit;
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
