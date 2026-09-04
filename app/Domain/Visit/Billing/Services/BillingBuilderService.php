<?php

namespace App\Domain\Visit\Billing\Services;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Visit\Billing\Models\Invoice;
use App\Domain\Visit\Billing\Models\InvoiceLine;
use App\Domain\Visit\Models\Visit;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class BillingBuilderService
{
    public function __construct(private BillingContextService $context, private BillingSourceService $sources, private PriceResolutionService $prices, private BillingNumberGenerator $numbers, private AuditRecorder $audit) {}

    /** @param array<string,mixed> $a */
    public function build(User $actor, Visit $visit, array $a): Invoice
    {
        return DB::transaction(function () use ($actor, $visit, $a): Invoice {
            [$actor, $visit, $branch] = $this->context->lock($actor, $visit, $a['expected_branch_id'] ?? null, 'billing.build.branch');
            $source = $this->sources->locked($visit);
            $lines = $this->prices->price($visit, $source['lines']);
            $hash = hash('sha256', json_encode([$source['manifest'], $lines], JSON_THROW_ON_ERROR));
            $invoice = Invoice::query()->where('current_visit_guard', $visit->id)->lockForUpdate()->first();
            if ($invoice && $invoice->status !== 'draft') {
                $this->stale();
            }
            if ($invoice && $invoice->source_hash === $hash && ! $invoice->source_stale) {
                if (isset($a['lock_version']) && $invoice->lock_version !== filter_var($a['lock_version'], FILTER_VALIDATE_INT)) {
                    $this->stale();
                }

                return $invoice;
            }
            if (($invoice?->lock_version) !== (isset($a['lock_version']) ? filter_var($a['lock_version'], FILTER_VALIDATE_INT) : null)) {
                $this->stale();
            }
            $total = 0;
            foreach ($lines as $line) {
                $total += $line['line_total_sen'];
                if ($total > ExactMoney::MAX_SEN) {
                    $this->stale();
                }
            }
            if (! $invoice) {
                $previous = Invoice::query()->where('visit_id', $visit->id)->where('status', 'voided')->latest('id')->first();
                $invoice = new Invoice;
                $invoice->forceFill(['public_id' => (string) Str::uuid(), 'organisation_id' => $actor->organisation_id, 'branch_id' => $branch->id, 'patient_id' => $visit->patient_id, 'visit_id' => $visit->id, 'current_visit_guard' => $visit->id, 'replaces_public_id' => $previous?->public_id, 'currency' => 'MYR', 'status' => 'draft', 'lock_version' => 0, 'created_by_user_id' => $actor->id]);
            }
            $invoice->forceFill(['consultation_checkout_id' => $source['checkout']->id, 'source_manifest' => $source['manifest'], 'source_hash' => $hash, 'source_stale' => false, 'subtotal_sen' => $total, 'total_sen' => $total, 'lock_version' => $invoice->lock_version + 1])->save();
            DB::table('invoice_lines')->where('invoice_id', $invoice->id)->delete();
            foreach ($lines as $line) {
                $record = new InvoiceLine;
                $record->forceFill([...$line, 'public_id' => (string) Str::uuid(), 'organisation_id' => $actor->organisation_id, 'branch_id' => $branch->id, 'invoice_id' => $invoice->id])->save();
            }
            $this->audit->record('billing.draft_built', $invoice, ['record_version' => $invoice->lock_version], $actor, $branch);

            return $invoice->fresh();
        }, 3);
    }

    /** @param array<string,mixed> $a */
    public function finalize(User $actor, Visit $visit, Invoice $invoice, array $a): Invoice
    {
        return DB::transaction(function () use ($actor, $visit, $invoice, $a): Invoice {
            [$actor, $visit, $branch] = $this->context->lock($actor, $visit, $a['expected_branch_id'] ?? null, 'billing.finalize.branch');
            $source = $this->sources->locked($visit);
            $lines = $this->prices->price($visit, $source['lines']);
            $hash = hash('sha256', json_encode([$source['manifest'], $lines], JSON_THROW_ON_ERROR));
            $invoice = Invoice::query()->whereKey($invoice->id)->where('visit_id', $visit->id)->where('organisation_id', $actor->organisation_id)->lockForUpdate()->firstOrFail();
            if ($invoice->status !== 'draft' || $invoice->lock_version !== filter_var($a['lock_version'] ?? null, FILTER_VALIDATE_INT) || $invoice->source_stale || $invoice->source_hash !== $hash || $invoice->current_visit_guard !== $visit->id) {
                $this->stale();
            }
            $stored = InvoiceLine::query()->where('invoice_id', $invoice->id)->orderBy('id')->lockForUpdate()->get();
            if ($stored->count() !== count($lines) || $stored->sum('line_total_sen') !== $invoice->total_sen) {
                $this->stale();
            }
            foreach ($lines as $line) {
                $record = $stored->firstWhere('source_key', $line['source_key']);
                if (! $record) {
                    $this->stale();
                }
                foreach ($line as $key => $value) {
                    if ($record->getAttribute($key) !== $value) {
                        $this->stale();
                    }
                }
            }
            $invoice->forceFill(['status' => 'finalized', 'invoice_number' => $this->numbers->next($actor->organisation_id, 'invoice'), 'finalized_at' => now()->utc(), 'finalized_by_user_id' => $actor->id, 'lock_version' => $invoice->lock_version + 1])->save();
            $this->audit->record('billing.finalized', $invoice, ['record_version' => $invoice->lock_version], $actor, $branch);

            return $invoice->fresh();
        }, 3);
    }

    private function stale(): never
    {
        throw ValidationException::withMessages(['invoice' => 'The Invoice or its source/price changed. Reload and review before continuing.']);
    }
}
