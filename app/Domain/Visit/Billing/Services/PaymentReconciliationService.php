<?php

namespace App\Domain\Visit\Billing\Services;

use App\Domain\Access\TransactionalActorAuthority;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Organisation\Models\Organisation;
use App\Domain\Visit\Billing\Models\Payment;
use App\Domain\Visit\Billing\Models\PaymentMethod;
use App\Domain\Visit\Billing\Models\PaymentReconciliation;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PaymentReconciliationService
{
    private const PERMISSION = 'payments.reconcile.branch';

    public function __construct(private TransactionalActorAuthority $authority, private AuditRecorder $audit) {}

    /** @param array<string, mixed> $attributes */
    public function reconcile(User $actor, array $attributes): PaymentReconciliation
    {
        Validator::make($attributes, [
            'expected_branch_id' => ['required', 'integer', 'min:1'],
            'payment_method_id' => ['required', 'integer', 'min:1'],
            'business_date' => ['required', 'date_format:Y-m-d'],
            'idempotency_key' => ['required', 'uuid'],
            'terminal_batch_reference' => ['nullable', 'string', 'max:100'],
            'variance_reason' => ['nullable', 'string', 'max:1000'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ])->validate();
        $salesTotal = $this->money($attributes['terminal_sales_total'] ?? null, 'terminal_sales_total');
        $refundsTotal = $this->money($attributes['terminal_refunds_total'] ?? '0', 'terminal_refunds_total');
        $voidsTotal = $this->money($attributes['terminal_voids_total'] ?? '0', 'terminal_voids_total');
        $salesCount = $this->count($attributes['terminal_sales_count'] ?? null, 'terminal_sales_count');
        $refundsCount = $this->count($attributes['terminal_refunds_count'] ?? '0', 'terminal_refunds_count');
        $voidsCount = $this->count($attributes['terminal_voids_count'] ?? '0', 'terminal_voids_count');
        $businessDate = $this->date($attributes['business_date'] ?? null);

        return DB::transaction(function () use ($actor, $attributes, $salesTotal, $refundsTotal, $voidsTotal, $salesCount, $refundsCount, $voidsCount, $businessDate): PaymentReconciliation {
            $branch = $this->authority->branch($actor, $attributes['expected_branch_id'] ?? null);
            $actor = $this->authority->lock($actor, $branch, self::PERMISSION);
            Organisation::query()->whereKey($actor->organisation_id)->sharedLock()->firstOrFail();
            $localBusinessDate = CarbonImmutable::createFromFormat('!Y-m-d', $businessDate->toDateString(), $branch->timezone);
            if (! $localBusinessDate || $localBusinessDate->startOfDay()->greaterThan(now()->setTimezone($branch->timezone)->startOfDay())) {
                throw ValidationException::withMessages(['business_date' => 'Choose today or an earlier business date.']);
            }

            $method = PaymentMethod::query()
                ->whereKey($attributes['payment_method_id'] ?? null)
                ->where('organisation_id', $actor->organisation_id)
                ->lockForUpdate()
                ->first();
            if (! $method) {
                throw ValidationException::withMessages(['payment_method_id' => 'Select a valid payment method for this organisation.']);
            }

            $hash = hash('sha256', json_encode([
                $actor->id, $branch->id, $method->id, $businessDate->toDateString(), $salesTotal, $salesCount,
                $refundsTotal, $refundsCount, $voidsTotal, $voidsCount,
                $attributes['terminal_batch_reference'] ?? null, $attributes['variance_reason'] ?? null, $attributes['notes'] ?? null,
            ], JSON_THROW_ON_ERROR));
            $idempotencyKey = $attributes['idempotency_key'] ?? null;
            $existing = is_string($idempotencyKey)
                ? PaymentReconciliation::query()->where('organisation_id', $actor->organisation_id)->where('idempotency_key', $idempotencyKey)->first()
                : null;
            if ($existing) {
                if ($existing->payload_hash !== $hash || $existing->branch_id !== $branch->id) {
                    throw ValidationException::withMessages(['reconciliation' => 'This request key was already used for different reconciliation details.']);
                }

                return $existing;
            }

            if (! is_string($idempotencyKey) || ! Str::isUuid($idempotencyKey)) {
                throw ValidationException::withMessages(['idempotency_key' => 'Refresh this page and try again.']);
            }

            $start = $localBusinessDate->startOfDay()->utc();
            $end = $localBusinessDate->addDay()->startOfDay()->utc();
            $payments = Payment::query()
                ->where('organisation_id', $actor->organisation_id)
                ->where('branch_id', $branch->id)
                ->where('payment_method_id', $method->id)
                ->where('status', 'posted')
                ->where('received_at', '>=', $start)
                ->where('received_at', '<', $end);
            $paymentCount = (clone $payments)->count();
            $paymentTotal = (int) ((clone $payments)->sum('amount_sen'));
            $countVariance = $salesCount - $paymentCount;
            $totalVariance = $salesTotal - $paymentTotal;
            $reason = filled($attributes['variance_reason'] ?? null) ? trim((string) $attributes['variance_reason']) : null;
            if (($countVariance !== 0 || $totalVariance !== 0) && $reason === null) {
                throw ValidationException::withMessages(['variance_reason' => 'Explain the difference before recording this close.']);
            }

            $revision = (int) PaymentReconciliation::query()
                ->where('organisation_id', $actor->organisation_id)
                ->where('branch_id', $branch->id)
                ->where('payment_method_id', $method->id)
                ->whereDate('business_date', $businessDate->toDateString())
                ->max('revision') + 1;

            $reconciliation = new PaymentReconciliation;
            $reconciliation->forceFill([
                'public_id' => (string) Str::uuid(),
                'organisation_id' => $actor->organisation_id,
                'branch_id' => $branch->id,
                'payment_method_id' => $method->id,
                'business_date' => $businessDate->toDateString(),
                'revision' => $revision,
                'terminal_sales_count' => $salesCount,
                'terminal_sales_sen' => $salesTotal,
                'terminal_refunds_count' => $refundsCount,
                'terminal_refunds_sen' => $refundsTotal,
                'terminal_voids_count' => $voidsCount,
                'terminal_voids_sen' => $voidsTotal,
                'kpone_payment_count' => $paymentCount,
                'kpone_payment_total_sen' => $paymentTotal,
                'payment_count_variance' => $countVariance,
                'payment_total_variance_sen' => $totalVariance,
                'terminal_batch_reference' => filled($attributes['terminal_batch_reference'] ?? null) ? trim((string) $attributes['terminal_batch_reference']) : null,
                'variance_reason' => $reason,
                'notes' => filled($attributes['notes'] ?? null) ? trim((string) $attributes['notes']) : null,
                'idempotency_key' => $idempotencyKey,
                'payload_hash' => $hash,
                'reconciled_by_user_id' => $actor->id,
                'reconciled_at' => now()->utc(),
            ])->save();
            $this->audit->record('billing.payment_reconciled', $reconciliation, [
                'business_date' => $businessDate->toDateString(),
                'payment_method_id' => $method->id,
                'revision' => $revision,
                'terminal_sales_count' => $salesCount,
                'terminal_sales_sen' => $salesTotal,
                'kpone_payment_count' => $paymentCount,
                'kpone_payment_total_sen' => $paymentTotal,
                'payment_count_variance' => $countVariance,
                'payment_total_variance_sen' => $totalVariance,
            ], $actor, $branch);

            return $reconciliation;
        }, 3);
    }

    private function money(mixed $value, string $field): int
    {
        if ((! is_string($value) && ! is_int($value)) || preg_match('/\A(?:0|[1-9][0-9]{0,9})(?:\.([0-9]{1,2}))?\z/', (string) $value, $parts) !== 1) {
            throw ValidationException::withMessages([$field => 'Enter an amount with no more than two decimal places.']);
        }

        $whole = (int) explode('.', (string) $value)[0];
        $fraction = (int) str_pad($parts[1] ?? '', 2, '0');
        $sen = $whole * 100 + $fraction;
        if ($sen > ExactMoney::MAX_SEN) {
            throw ValidationException::withMessages([$field => 'The amount exceeds the permitted limit.']);
        }

        return $sen;
    }

    private function count(mixed $value, string $field): int
    {
        if ((! is_string($value) && ! is_int($value)) || filter_var($value, FILTER_VALIDATE_INT) === false || (int) $value < 0 || (int) $value > 1_000_000) {
            throw ValidationException::withMessages([$field => 'Enter a whole number between 0 and 1,000,000.']);
        }

        return (int) $value;
    }

    private function date(mixed $value): CarbonImmutable
    {
        if (! is_string($value) || preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $value) !== 1) {
            throw ValidationException::withMessages(['business_date' => 'Select a valid business date.']);
        }

        $date = CarbonImmutable::createFromFormat('!Y-m-d', $value, 'UTC');
        if (! $date || $date->format('Y-m-d') !== $value) {
            throw ValidationException::withMessages(['business_date' => 'Select a valid business date.']);
        }

        return $date;
    }
}
