<?php

namespace App\Domain\Visit\Billing\Services;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Visit\Billing\Models\Invoice;
use App\Domain\Visit\Models\Visit;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CompleteVisitationService
{
    public function __construct(private BillingContextService $context, private BillingSourceService $sources, private FinancialLedger $ledger, private AuditRecorder $audit) {}

    /** @param array<string,mixed> $a */
    public function complete(User $actor, Visit $visit, array $a): void
    {
        DB::transaction(function () use ($actor, $visit, $a): void {
            [$actor, $visit, $branch] = $this->context->lock($actor, $visit, $a['expected_branch_id'] ?? null, 'visits.complete.branch');
            if ($visit->status !== Visit::STATUS_REGISTERED || $visit->lock_version !== filter_var($a['visit_lock_version'] ?? null, FILTER_VALIDATE_INT)) {
                $this->invalid();
            }
            $source = $this->sources->locked($visit);
            $invoice = Invoice::query()->where('current_visit_guard', $visit->id)->lockForUpdate()->first();
            if (! $invoice) {
                $this->invalid();
            }
            $this->ledger->requireMutable($invoice, $a['lock_version'] ?? null);
            if ($invoice->source_manifest !== $source['manifest']) {
                $this->invalid();
            }
            $this->ledger->lock($invoice);
            $state = $this->ledger->state($invoice);
            if ($state['due_now'] !== 0) {
                $this->invalid();
            }
            $visit->forceFill(['status' => Visit::STATUS_COMPLETED, 'completed_at' => now()->utc(), 'completed_by_user_id' => $actor->id,
                'completion_evidence' => ['invoice_public_id' => $invoice->public_id, 'invoice_version' => $invoice->lock_version, 'source_hash' => $invoice->source_hash, 'financial_revision' => $state],
                'lock_version' => $visit->lock_version + 1])->save();
            $this->audit->record('billing.visit_completed', $visit, ['record_version' => $visit->lock_version], $actor, $branch);
        }, 3);
    }

    private function invalid(): never
    {
        throw ValidationException::withMessages(['completion' => 'Current clinical sources, a finalized Invoice and zero unapproved due now are required. Reload before completing.']);
    }
}
