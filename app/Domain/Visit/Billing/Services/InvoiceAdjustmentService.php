<?php

namespace App\Domain\Visit\Billing\Services;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Visit\Billing\Models\Invoice;
use App\Domain\Visit\Billing\Models\InvoiceAdjustment;
use App\Domain\Visit\Billing\Models\InvoiceAdjustmentLimit;
use App\Domain\Visit\Billing\Models\InvoiceAdjustmentReversal;
use App\Domain\Visit\Models\Visit;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class InvoiceAdjustmentService
{
    public function __construct(private BillingContextService $context, private FinancialLedger $ledger, private AuditRecorder $audit) {}

    public static function active(Invoice $invoice): ?InvoiceAdjustment
    {
        return InvoiceAdjustment::query()->where('invoice_id', $invoice->id)->whereNotIn('id', InvoiceAdjustmentReversal::query()->select('invoice_adjustment_id'))->first();
    }

    /** Numeric-only batch equivalent of active(); invoice ids are selected by the caller's scope.
     * @param  list<int>  $invoiceIds
     * @return Collection<int, InvoiceAdjustment>
     */
    public static function activeForInvoices(array $invoiceIds): Collection
    {
        return InvoiceAdjustment::query()->whereIn('invoice_id', $invoiceIds)
            ->whereNotIn('id', InvoiceAdjustmentReversal::query()->select('invoice_adjustment_id'))
            ->orderBy('id')->get(['id', 'invoice_id', 'public_id', 'direction', 'amount_sen'])->keyBy('invoice_id');
    }

    public static function hasHistory(Invoice $invoice): bool
    {
        foreach (['payment_allocations', 'coverage_allocations', 'patient_receivables'] as $table) {
            if (DB::table($table)->where('invoice_id', $invoice->id)->exists()) {
                return true;
            }
        }

        return false;
    }

    /** @return array{configured: bool, limitSen: ?int, lockVersion: int} */
    public static function threshold(int $branchId): array
    {
        $row = InvoiceAdjustmentLimit::query()->where('branch_id', $branchId)->latest('lock_version')->first();

        return ['configured' => $row?->limit_sen !== null, 'limitSen' => $row?->limit_sen, 'lockVersion' => $row === null ? 0 : $row->lock_version];
    }

    /**
     * Numeric-only projection, never the reason, hash or retry key.
     *
     * @return array{subtotalSen: int, adjustmentSen: int, activeId: ?string}
     */
    public static function summary(Invoice $invoice): array
    {
        $active = self::active($invoice);
        $net = self::net($active);

        return ['subtotalSen' => $invoice->subtotal_sen, 'adjustmentSen' => $net, 'activeId' => $active?->public_id];
    }

    public static function net(?InvoiceAdjustment $adjustment): int
    {
        return $adjustment ? ($adjustment->direction === 'add' ? $adjustment->amount_sen : -$adjustment->amount_sen) : 0;
    }

    /** @return array<string,mixed> */
    public static function controls(User $actor, Visit $visit, Invoice $invoice): array
    {
        $active = self::active($invoice);
        $threshold = self::threshold($invoice->branch_id);
        $mutable = $visit->status === Visit::STATUS_REGISTERED && $invoice->status === 'finalized' && $invoice->current_visit_guard === $visit->id && ! $invoice->correction_hold && ! $invoice->source_stale && ! self::hasHistory($invoice);

        return ['url' => route('billing.adjustment', [$visit, $invoice], false), 'threshold' => $threshold, 'override' => $actor->can('billing.adjustments.override_limit.branch'),
            'add' => $mutable && ! $active && $invoice->subtotal_sen > 0 && $actor->can('billing.adjustments.add.branch') && (($threshold['limitSen'] ?? 0) > 0 || $actor->can('billing.adjustments.override_limit.branch')),
            'reverse' => $mutable && $active && $active->actor_user_id !== $actor->id && $actor->can('billing.adjustments.reverse.branch')];
    }

    /** @param array<string,mixed> $input */
    public function add(User $actor, Visit $visit, Invoice $invoice, array $input): InvoiceAdjustment
    {
        $input['reason'] = is_string($input['reason'] ?? null) ? trim($input['reason']) : null;
        Validator::make($input, ['direction' => ['required', 'in:add,deduct'], 'input_type' => ['required', 'in:fixed,percentage'], 'reason' => ['required', 'string', 'max:500'], 'idempotency_key' => ['required', 'uuid'], 'threshold_version' => ['required', 'integer', 'min:0']])->validate();
        $value = ExactMoney::sen($input['input_value'] ?? null, 'input_value');
        if ($value < 1 || ($input['input_type'] === 'percentage' && $value > 10000)) {
            $this->invalid('input_value', 'Enter a positive amount or a percentage up to 100%.');
        }

        return DB::transaction(function () use ($actor, $visit, $invoice, $input, $value): InvoiceAdjustment {
            [$actor, $visit, $branch] = $this->context->lock($actor, $visit, $input['expected_branch_id'] ?? null, 'billing.adjustments.add.branch');
            // The branch is the serialization anchor even when no threshold has ever been configured.
            Branch::query()->whereKey($branch->id)->lockForUpdate()->firstOrFail();
            $invoice = Invoice::query()->whereKey($invoice->id)->where('visit_id', $visit->id)->lockForUpdate()->firstOrFail();
            $hash = hash('sha256', json_encode([$actor->id, $invoice->id, $input['direction'], $input['input_type'], $value, $input['reason'], (int) $input['threshold_version'], $input['confirm_above_limit'] ?? false], JSON_THROW_ON_ERROR));
            $previous = InvoiceAdjustment::query()->where('organisation_id', $actor->organisation_id)->where('idempotency_key', $input['idempotency_key'])->first();
            if ($previous) {
                $this->sameIntent($previous->payload_hash, $hash);

                return $previous;
            }
            $this->requireEligible($visit, $invoice, $input);
            if (self::active($invoice)) {
                $this->invalid('adjustment', 'Reverse the existing adjustment before adding another.');
            }
            $threshold = self::threshold($branch->id);
            if ($threshold['lockVersion'] !== (int) $input['threshold_version']) {
                $this->invalid('adjustment', 'The branch threshold changed. Reload before continuing.');
            }
            $amount = $input['input_type'] === 'fixed' ? $value : intdiv($invoice->subtotal_sen * $value + 5000, 10000);
            $signed = $input['direction'] === 'add' ? $amount : -$amount;
            if ($amount < 1 || $amount > $invoice->subtotal_sen || $invoice->total_sen + $signed > ExactMoney::MAX_SEN) {
                $this->invalid('input_value', 'The adjustment must be at least one sen and at most the original subtotal, within the monetary ceiling.');
            }
            $above = $amount > ($threshold['limitSen'] ?? 0);
            if ($above) {
                abort_unless($actor->can('billing.adjustments.override_limit.branch'), 403);
                Validator::make($input, ['confirm_above_limit' => ['required', 'accepted']])->validate();
            }
            $record = new InvoiceAdjustment;
            $record->forceFill(['public_id' => (string) Str::uuid(), 'organisation_id' => $actor->organisation_id, 'branch_id' => $branch->id, 'invoice_id' => $invoice->id, 'actor_user_id' => $actor->id,
                'direction' => $input['direction'], 'input_type' => $input['input_type'], 'input_value' => $value, 'base_sen' => $invoice->subtotal_sen, 'amount_sen' => $amount, 'reason' => $input['reason'],
                'threshold_version' => $threshold['lockVersion'], 'threshold_sen' => $threshold['limitSen'], 'limit_overridden' => $above, 'invoice_version' => $invoice->lock_version,
                'idempotency_key' => $input['idempotency_key'], 'payload_hash' => $hash])->save();
            $invoice->forceFill(['lock_version' => $invoice->lock_version + 1])->save();
            $this->ledger->state($invoice);
            $this->audit->record('billing.adjustment_added', $invoice, ['record_version' => $invoice->lock_version, 'adjustment_id' => $record->id, 'amount_sen' => $amount, 'direction' => $input['direction'], 'threshold_configured' => $threshold['configured'], 'limit_overridden' => $above], $actor, $branch);

            return $record;
        }, 3);
    }

    /** @param array<string,mixed> $input */
    public function reverse(User $actor, Visit $visit, Invoice $invoice, string $publicId, array $input): InvoiceAdjustmentReversal
    {
        $input['reason'] = is_string($input['reason'] ?? null) ? trim($input['reason']) : null;
        Validator::make($input, ['reason' => ['required', 'string', 'max:500'], 'idempotency_key' => ['required', 'uuid']])->validate();

        return DB::transaction(function () use ($actor, $visit, $invoice, $publicId, $input): InvoiceAdjustmentReversal {
            [$actor, $visit, $branch] = $this->context->lock($actor, $visit, $input['expected_branch_id'] ?? null, 'billing.adjustments.reverse.branch');
            Branch::query()->whereKey($branch->id)->lockForUpdate()->firstOrFail();
            $invoice = Invoice::query()->whereKey($invoice->id)->where('visit_id', $visit->id)->lockForUpdate()->firstOrFail();
            $original = InvoiceAdjustment::query()->where('invoice_id', $invoice->id)->where('public_id', $publicId)->lockForUpdate()->firstOrFail();
            $hash = hash('sha256', json_encode([$actor->id, $invoice->id, $original->id, $input['reason']], JSON_THROW_ON_ERROR));
            $previous = InvoiceAdjustmentReversal::query()->where('organisation_id', $actor->organisation_id)->where('idempotency_key', $input['idempotency_key'])->first();
            if ($previous) {
                $this->sameIntent($previous->payload_hash, $hash);

                return $previous;
            }
            $this->requireEligible($visit, $invoice, $input);
            if ($original->actor_user_id === $actor->id || self::active($invoice)?->id !== $original->id) {
                $this->invalid('adjustment', 'An independent authorised staff member must reverse the active adjustment.');
            }
            $record = new InvoiceAdjustmentReversal;
            $record->forceFill(['public_id' => (string) Str::uuid(), 'organisation_id' => $actor->organisation_id, 'branch_id' => $branch->id, 'invoice_id' => $invoice->id, 'invoice_adjustment_id' => $original->id, 'actor_user_id' => $actor->id,
                'amount_sen' => $original->amount_sen, 'reason' => $input['reason'], 'invoice_version' => $invoice->lock_version, 'idempotency_key' => $input['idempotency_key'], 'payload_hash' => $hash])->save();
            $invoice->forceFill(['lock_version' => $invoice->lock_version + 1])->save();
            $this->audit->record('billing.adjustment_reversed', $invoice, ['record_version' => $invoice->lock_version, 'adjustment_id' => $original->id, 'amount_sen' => $original->amount_sen], $actor, $branch);

            return $record;
        }, 3);
    }

    /** @param array<string,mixed> $input */
    private function requireEligible(Visit $visit, Invoice $invoice, array $input): void
    {
        $this->ledger->requireMutable($invoice, $input['lock_version'] ?? null);
        $this->ledger->lock($invoice);
        if ($visit->status !== Visit::STATUS_REGISTERED || $invoice->current_visit_guard !== $visit->id || self::hasHistory($invoice)) {
            $this->invalid('adjustment', 'Adjustments require the current finalised Invoice before any payment, Panel proposal or pay-later history and before Visit completion.');
        }
    }

    private function sameIntent(string $stored, string $hash): void
    {
        if (! hash_equals($stored, $hash)) {
            $this->invalid('idempotency_key', 'This retry key belongs to a different adjustment request. Reload to start a new request.');
        }
    }

    private function invalid(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
