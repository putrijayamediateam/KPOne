<?php

namespace App\Domain\Visit\Billing\Services;

use App\Domain\Access\BranchAccessService;
use App\Domain\Clinical\Dispensary\Models\DispensaryCase;
use App\Domain\Clinical\Models\ConsultationCheckout;
use App\Domain\Visit\Billing\Models\CoverageAllocation;
use App\Domain\Visit\Billing\Models\Invoice;
use App\Domain\Visit\Billing\Models\InvoiceLine;
use App\Domain\Visit\Billing\Models\PatientReceivable;
use App\Domain\Visit\Billing\Models\Payment;
use App\Domain\Visit\Billing\Models\PaymentAllocation;
use App\Domain\Visit\Billing\Models\PaymentMethod;
use App\Domain\Visit\Models\Panel;
use App\Domain\Visit\Models\Visit;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class BillingDirectoryService
{
    public function __construct(private BillingContextService $context, private FinancialLedger $ledger) {}

    /** Explicit projection: financial-only actors never receive medicine line descriptions.
     * @return array<string,mixed>
     */
    public function detail(User $actor, Visit $visit, ?Invoice $document = null, bool $printing = false): array
    {
        return DB::transaction(function () use ($actor, $visit, $document, $printing): array {
            $permission = $printing ? 'billing.print.branch' : ($actor->can('billing.view.branch') ? 'billing.view.branch' : 'billing.summary.branch');
            $branchId = app(BranchAccessService::class)->activeBranch($actor)?->id;
            [$actor, $visit, $branch] = $this->context->lock($actor, $visit, $branchId, $permission);
            $visit->load('patient:id,patient_number,full_name');
            $checkout = ConsultationCheckout::query()->where('current_visit_guard', $visit->id)->first();
            $case = DispensaryCase::query()->where('visit_id', $visit->id)->first();
            $invoice = $document ? Invoice::query()->whereKey($document->id)->where('visit_id', $visit->id)->firstOrFail() : Invoice::query()->where('current_visit_guard', $visit->id)->first();
            $mutable = $visit->status === Visit::STATUS_REGISTERED && $invoice?->status === 'finalized' && ! $invoice->correction_hold;
            $state = $invoice ? $this->ledger->state($invoice) : null;
            $payments = $invoice ? Payment::query()->whereIn('id', PaymentAllocation::query()->where('invoice_id', $invoice->id)->select('payment_id'))->orderBy('id')->get() : collect();
            $proposal = function (string $class) use ($invoice): ?array {
                $record = $invoice ? $class::query()->where('current_invoice_guard', $invoice->id)->first() : null;

                return $record ? ['publicId' => $record->public_id, 'status' => $record->status, 'amountSen' => $record->amount_sen, 'lockVersion' => $record->lock_version,
                    'reason' => $record->reason, 'panelName' => $record instanceof CoverageAllocation ? $record->panel_name_snapshot : null,
                    'memberReference' => $record instanceof CoverageAllocation ? $record->member_reference : null,
                    'remainingSen' => $record instanceof PatientReceivable ? $record->remaining_sen : null, 'dueDate' => $record instanceof PatientReceivable ? $record->due_date->toDateString() : null] : null;
            };
            $oldOutstanding = $actor->can('outstanding.view.branch') ? DB::table('patient_receivables as r')->join('invoices as i', 'i.id', '=', 'r.invoice_id')->join('visits as v', 'v.id', '=', 'i.visit_id')
                ->where('i.organisation_id', $actor->organisation_id)->where('i.branch_id', $branch->id)->where('i.patient_id', $visit->patient_id)->where('i.visit_id', '<>', $visit->id)
                ->where('i.status', 'finalized')->where('r.status', 'approved')->where('r.remaining_sen', '>', 0)->orderBy('r.id')->limit(10)
                ->get(['i.invoice_number', 'r.remaining_sen', 'r.due_date', 'v.visit_number'])->map(fn ($r): array => ['invoiceNumber' => $r->invoice_number, 'amountSen' => (int) $r->remaining_sen, 'dueDate' => $r->due_date, 'url' => route('billing.show', $r->visit_number)])->all() : [];

            return ['patient' => ['name' => $visit->patient->full_name, 'patientNumber' => $visit->patient->patient_number],
                'visit' => ['visitNumber' => $visit->visit_number, 'status' => $visit->status, 'lockVersion' => $visit->lock_version, 'coverage' => $visit->coverage_type === 'panel' ? $visit->coverage_panel_name_snapshot : 'Self-pay', 'completedAt' => $visit->completed_at?->setTimezone($branch->timezone)->format('j M Y, g:i A')],
                'clinic' => $branch->organisation->name, 'branch' => $branch->name, 'oldOutstanding' => $oldOutstanding,
                'invoice' => $invoice ? ['publicId' => $invoice->public_id, 'number' => $invoice->invoice_number, 'status' => $invoice->status, 'lockVersion' => $invoice->lock_version, 'currency' => $invoice->currency,
                    'finalizedAt' => $invoice->finalized_at?->setTimezone($branch->timezone)->format('j M Y, g:i A'), 'correctionHold' => $invoice->correction_hold, 'state' => $state,
                    'lines' => $actor->can('billing.view.branch') ? InvoiceLine::query()->where('invoice_id', $invoice->id)->orderBy('id')->get()->map(fn ($l): array => ['type' => $l->line_type, 'name' => $l->display_name, 'unit' => $l->unit_snapshot, 'quantity' => $l->quantity, 'unitPriceSen' => $l->unit_price_sen, 'totalSen' => $l->line_total_sen])->all() : []] : null,
                'panel' => ($actor->can('coverage.propose.branch') || $actor->can('coverage.approve.branch')) ? $proposal(CoverageAllocation::class) : null,
                'deferment' => ($actor->can('outstanding.view.branch') || $actor->can('outstanding.request.branch') || $actor->can('outstanding.approve.branch')) ? $proposal(PatientReceivable::class) : null,
                'payments' => $payments->map(fn (Payment $p): array => ['publicId' => $p->public_id, 'number' => $p->receipt_number, 'amountSen' => $p->amount_sen, 'method' => $p->method_snapshot, 'status' => $p->status, 'lockVersion' => $p->lock_version, 'receivedAt' => $p->received_at->setTimezone($branch->timezone)->format('j M Y, g:i A')])->all(),
                'methods' => $actor->can('payments.add.branch') ? PaymentMethod::query()->where('organisation_id', $actor->organisation_id)->where('is_active', true)->orderBy('name')->get()->map(fn ($m): array => ['code' => $m->code, 'name' => $m->name, 'requiresReference' => $m->requires_reference])->all() : [],
                'panels' => $actor->can('coverage.propose.branch') ? Panel::query()->where('organisation_id', $actor->organisation_id)->where('is_active', true)->orderBy('name')->limit(100)->get(['id', 'name'])->toArray() : [],
                'can' => ['build' => $actor->can('billing.build.branch') && $visit->status === Visit::STATUS_REGISTERED && $checkout !== null && ($checkout->route === 'billing' || $case?->status === DispensaryCase::STATUS_COMPLETED) && (! $invoice || $invoice->status === 'draft'),
                    'finalize' => $visit->status === Visit::STATUS_REGISTERED && $actor->can('billing.finalize.branch') && $invoice?->status === 'draft' && ! $invoice->source_stale,
                    'pay' => in_array($visit->status, [Visit::STATUS_REGISTERED, Visit::STATUS_COMPLETED], true) && $actor->can('payments.add.branch') && $invoice?->status === 'finalized' && ! $invoice->correction_hold && ($state['due_now'] + $state['deferred']) > 0,
                    'panelPropose' => $mutable && $actor->can('coverage.propose.branch'), 'panelApprove' => $mutable && $actor->can('coverage.approve.branch'),
                    'deferPropose' => $mutable && $actor->can('outstanding.request.branch'), 'deferApprove' => $mutable && $actor->can('outstanding.approve.branch'),
                    'reverse' => $mutable && $actor->can('payments.reverse.branch'), 'void' => $mutable && $actor->can('invoices.void.branch'),
                    'complete' => $mutable && $actor->can('visits.complete.branch') && $state['due_now'] === 0, 'print' => $invoice !== null && $invoice->status !== 'draft' && $actor->can('billing.print.branch')]];
        });
    }
}
