<?php

namespace App\Domain\Visit\Billing\Services;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Visit\Billing\Models\CoverageAllocation;
use App\Domain\Visit\Billing\Models\Invoice;
use App\Domain\Visit\Billing\Models\PatientReceivable;
use App\Domain\Visit\Models\Visit;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ResponsibilityService
{
    public function __construct(private BillingContextService $context, private FinancialLedger $ledger, private AuditRecorder $audit) {}

    /** @param array<string,mixed> $a */
    public function propose(User $actor, Visit $visit, Invoice $invoice, string $kind, array $a): CoverageAllocation|PatientReceivable
    {
        $class = $this->model($kind);
        Validator::make($a, ['reason' => ['required', 'string', 'max:500'], 'due_date' => [$kind === 'deferment' ? 'required' : 'nullable', 'date_format:Y-m-d', 'after_or_equal:today'], 'member_reference' => ['nullable', 'string', 'max:100', 'regex:/\A[A-Za-z0-9 ._\/-]+\z/']])->validate();
        $amount = ExactMoney::sen($a['amount_sen'] ?? null);

        return DB::transaction(function () use ($actor, $visit, $invoice, $kind, $a, $amount, $class) {
            [$actor, $visit, $branch] = $this->context->lock($actor, $visit, $a['expected_branch_id'] ?? null, $kind === 'panel' ? 'coverage.propose.branch' : 'outstanding.request.branch');
            $invoice = Invoice::query()->whereKey($invoice->id)->where('visit_id', $visit->id)->lockForUpdate()->firstOrFail();
            $this->ledger->requireMutable($invoice, $a['lock_version'] ?? null);
            $this->ledger->lock($invoice);
            $state = $this->ledger->state($invoice);
            if ($visit->status !== Visit::STATUS_REGISTERED || $amount < 1 || $amount > $state['due_now']) {
                $this->invalid();
            }
            $previous = $class::query()->where('current_invoice_guard', $invoice->id)->first();
            if ($previous?->status === 'approved') {
                $this->invalid();
            }
            $previous?->forceFill(['status' => 'superseded', 'current_invoice_guard' => null, 'lock_version' => $previous->lock_version + 1])->save();
            $extra = [];
            if ($kind === 'panel') {
                $panel = DB::table('panels')->where('organisation_id', $actor->organisation_id)->where('id', $a['panel_id'] ?? null)->where('is_active', true)->first();
                if (! $panel) {
                    $this->invalid();
                }
                $extra = ['panel_id' => $panel->id, 'panel_name_snapshot' => $panel->name, 'member_reference' => $a['member_reference'] ?? null];
            } else {
                $extra = ['due_date' => $a['due_date'], 'remaining_sen' => $amount];
            }
            $proposal = new $class;
            $proposal->forceFill([...$extra, 'public_id' => (string) Str::uuid(), 'organisation_id' => $actor->organisation_id, 'branch_id' => $branch->id, 'invoice_id' => $invoice->id,
                'current_invoice_guard' => $invoice->id, 'amount_sen' => $amount, 'status' => 'proposed', 'lock_version' => 1, 'expected_invoice_version' => $invoice->lock_version + 1, 'requested_by_user_id' => $actor->id, 'reason' => $a['reason']])->save();
            $invoice->forceFill(['lock_version' => $invoice->lock_version + 1])->save();
            $this->audit->record('billing.responsibility_proposed', $invoice, ['record_version' => $invoice->lock_version], $actor, $branch);

            return $proposal;
        }, 3);
    }

    /** @param array<string,mixed> $a */
    public function approve(User $actor, Visit $visit, Invoice $invoice, string $kind, string $publicId, array $a): void
    {
        $class = $this->model($kind);
        DB::transaction(function () use ($actor, $visit, $invoice, $kind, $publicId, $a, $class): void {
            [$actor, $visit, $branch] = $this->context->lock($actor, $visit, $a['expected_branch_id'] ?? null, $kind === 'panel' ? 'coverage.approve.branch' : 'outstanding.approve.branch');
            // Approval configuration is part of actor authority, before financial locks.
            $limit = DB::table('billing_approval_limits')->where('organisation_id', $actor->organisation_id)->where('branch_id', $branch->id)->where('user_id', $actor->id)->where('capability', $kind)->lockForUpdate()->first();
            $panelActive = true;
            if ($kind === 'panel') {
                $panelId = CoverageAllocation::query()->where('public_id', $publicId)->where('invoice_id', $invoice->id)->value('panel_id');
                $panelActive = DB::table('panels')->where('id', $panelId)->where('organisation_id', $actor->organisation_id)->where('is_active', true)->lockForUpdate()->first() !== null;
            }
            $invoice = Invoice::query()->whereKey($invoice->id)->where('visit_id', $visit->id)->lockForUpdate()->firstOrFail();
            $this->ledger->requireMutable($invoice, $a['lock_version'] ?? null);
            $this->ledger->lock($invoice);
            $proposal = $class::query()->where('public_id', $publicId)->where('invoice_id', $invoice->id)->firstOrFail();
            $state = $this->ledger->state($invoice);
            if ($visit->status !== Visit::STATUS_REGISTERED || ! $limit || ! $panelActive || $proposal->status !== 'proposed' || $proposal->requested_by_user_id === $actor->id
                || $proposal->lock_version !== filter_var($a['proposal_lock_version'] ?? null, FILTER_VALIDATE_INT)
                || $proposal->expected_invoice_version !== $invoice->lock_version || $proposal->amount_sen > (int) $limit->limit_sen || $proposal->amount_sen > $state['due_now']) {
                $this->invalid();
            }
            $proposal->forceFill(['status' => 'approved', 'approved_by_user_id' => $actor->id, 'approved_at' => now()->utc(), 'lock_version' => $proposal->lock_version + 1])->save();
            $invoice->forceFill(['lock_version' => $invoice->lock_version + 1])->save();
            $this->ledger->state($invoice);
            $this->audit->record('billing.responsibility_approved', $invoice, ['record_version' => $invoice->lock_version], $actor, $branch);
        }, 3);
    }

    /** @return class-string<CoverageAllocation>|class-string<PatientReceivable> */
    private function model(string $kind): string
    {
        abort_unless(in_array($kind, ['panel', 'deferment'], true), 404);

        return $kind === 'panel' ? CoverageAllocation::class : PatientReceivable::class;
    }

    private function invalid(): never
    {
        throw ValidationException::withMessages(['responsibility' => 'Current independent approval within configured authority is required; reload the Invoice and proposal.']);
    }
}
