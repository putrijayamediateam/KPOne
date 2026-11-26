<?php

namespace App\Domain\Visit\Billing\Review;

use App\Domain\Access\BranchAccessService;
use App\Domain\Access\StaffAccessService;
use App\Domain\Access\TransactionalActorAuthority;
use App\Domain\Clinical\Models\ClinicalEncounter;
use App\Domain\Shared\ReadOnlyReviewAudit;
use App\Domain\Visit\Billing\Models\Invoice;
use App\Domain\Visit\Billing\Models\InvoiceLine;
use App\Domain\Visit\Billing\Models\Payment;
use App\Domain\Visit\Billing\Models\PaymentAllocation;
use App\Domain\Visit\Billing\Services\FinancialLedger;
use App\Domain\Visit\Billing\Services\InvoiceAdjustmentService;
use App\Domain\Visit\Models\Visit;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * BIL-37 (owner decision 24 Nov 2026; design note revision 2 and the owner's answers): the Resident Doctor sees the
 * bill of their own patient's visit, view only.
 *
 * - Own patient: the encounter's attending doctor once the consultation has started, otherwise the visit's assigned
 *   doctor (the triage view's rule, TRI-1e). Anything else is 404, as a visit at another branch is.
 * - No transaction and no row locks (Kumar F9). Every open is recorded first through ReadOnlyReviewAudit, which commits
 *   on its own; if that fails, nothing is shown.
 * - A fresh, explicit projection: never the CA Billing data. No notes, Bill to, adjustment reason, refunds, corrections,
 *   Panel or pay-later proposals, identity, receipt numbers, issued copies or actions. Drafts are never shown.
 */
final class BillingReviewService
{
    public const PERMISSION = 'billing.view_readonly.branch';

    public function __construct(
        private BranchAccessService $branches,
        private TransactionalActorAuthority $authority,
        private ReadOnlyReviewAudit $audit,
        private FinancialLedger $ledger,
        private StaffAccessService $staff,
    ) {}

    /** Whether the actor may open this visit's bill (used for links; no audit). */
    public function canOpen(User $actor, Visit $visit): bool
    {
        $branch = $this->branches->activeBranch($actor);

        return $branch !== null
            && $visit->organisation_id === $actor->organisation_id
            && $visit->branch_id === $branch->id
            && $this->authority->wouldAllow($actor, $branch, self::PERMISSION)
            && $this->doctorOf($visit) === $actor->id;
    }

    /** Whether a non-draft invoice exists, so a "View bill" link is worth showing. */
    public function hasBill(Visit $visit): bool
    {
        return Invoice::query()->where('organisation_id', $visit->organisation_id)->where('visit_id', $visit->id)->where('status', '!=', 'draft')->exists();
    }

    /** @return array<string, mixed> */
    public function open(User $actor, Visit $visit): array
    {
        if (! $actor->is_active || ! $actor->can(self::PERMISSION)) {
            throw new AuthorizationException('You may not perform this operation.');
        }
        abort_unless($this->canOpen($actor, $visit), 404);
        $branch = $this->branches->activeBranch($actor);
        abort_unless($branch !== null, 404);

        $invoice = $this->invoice($visit);
        $ids = ['visit' => $visit->id, 'patient' => $visit->patient_id];
        if ($invoice !== null) {
            $ids['invoice'] = $invoice->id;
        }
        $this->audit->record($actor, 'billing.reviewed', $visit, $ids, $branch);

        return $this->project($actor, $visit, $invoice, $branch->timezone);
    }

    private function doctorOf(Visit $visit): ?int
    {
        $attending = ClinicalEncounter::query()->where('visit_id', $visit->id)->orderByDesc('id')->value('attending_clinician_user_id');

        return $attending !== null ? (int) $attending : ($visit->assigned_doctor_user_id !== null ? (int) $visit->assigned_doctor_user_id : null);
    }

    /** The visit's current invoice, otherwise the latest non-draft one. Drafts are never shown. */
    private function invoice(Visit $visit): ?Invoice
    {
        $query = fn () => Invoice::query()->where('organisation_id', $visit->organisation_id)->where('visit_id', $visit->id)->where('status', '!=', 'draft');

        return $query()->whereNotNull('current_visit_guard')->orderByDesc('id')->first() ?? $query()->orderByDesc('id')->first();
    }

    /** @return array<string, mixed> */
    private function project(User $actor, Visit $visit, ?Invoice $invoice, string $timezone): array
    {
        $visit->loadMissing('patient:id,organisation_id,patient_number,full_name');
        $head = [
            'visit' => ['number' => $visit->visit_number, 'status' => $visit->status],
            'patient' => ['name' => $visit->patient->full_name, 'patientNumber' => $visit->patient->patient_number],
        ];
        if ($invoice === null) {
            return $head + ['invoice' => null];
        }
        $base = ['number' => $invoice->invoice_number, 'status' => $invoice->status,
            'finalizedAt' => $invoice->finalized_at?->setTimezone($timezone)->format('j M Y, g:i A')];
        if ($invoice->status === 'voided') {
            return $head + ['invoice' => $base + ['lines' => [], 'totals' => null, 'payments' => []]];
        }

        $state = $this->ledger->state($invoice);
        $payments = Payment::query()->whereIn('id', PaymentAllocation::query()->where('invoice_id', $invoice->id)->select('payment_id'))->orderBy('id')->get();
        // BIL-2: staff names follow the staff-directory visibility rule; any other reads "Staff member".
        $names = $this->staff->visibleUsers($actor)->whereIn('id', $payments->pluck('recorded_by_user_id')->filter()->unique())->pluck('name', 'id');

        return $head + ['invoice' => $base + [
            'lines' => InvoiceLine::query()->where('invoice_id', $invoice->id)->orderBy('id')->get()->map(fn (InvoiceLine $line): array => [
                'name' => $line->display_name, 'quantity' => $line->quantity, 'unitPriceSen' => $line->unit_price_sen, 'totalSen' => $line->line_total_sen,
            ])->values()->all(),
            // Daniel: every figure that moves the total is shown, so the total adds up by eye. The same parts as
            // FinancialLedger::calculate(): items + discount or surcharge + Panel claim adjustment - refund credit.
            'totals' => [
                'subtotalSen' => (int) $invoice->total_sen,
                'adjustmentSen' => InvoiceAdjustmentService::summary($invoice)['adjustmentSen'],
                'panelAdjustmentSen' => (int) (FinancialLedger::panelAdjustments([$invoice->id])[$invoice->id] ?? 0),
                'refundCreditSen' => (int) FinancialLedger::approvedCredits([$invoice->id])->sum('amount_sen'),
                'totalSen' => $state['total'],
                'paidSen' => $state['self_pay'],
                'panelSen' => $state['panel'],
                'payLaterSen' => $state['deferred'],
                'dueNowSen' => $state['due_now'],
            ],
            'payments' => $payments->map(fn (Payment $payment): array => [
                'receivedAt' => $payment->received_at->setTimezone($timezone)->format('j M Y, g:i A'),
                'method' => $payment->method_snapshot,
                'amountSen' => $payment->amount_sen,
                'status' => $payment->status,
                'recordedBy' => (string) ($names[$payment->recorded_by_user_id] ?? 'Staff member'),
            ])->values()->all(),
        ]];
    }
}
