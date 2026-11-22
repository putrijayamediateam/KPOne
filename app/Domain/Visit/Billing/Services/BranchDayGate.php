<?php

declare(strict_types=1);

namespace App\Domain\Visit\Billing\Services;

use App\Domain\Organisation\Models\Branch;
use App\Domain\Visit\Billing\Models\Invoice;
use App\Domain\Visit\Billing\Models\Payment;
use App\Domain\Visit\Billing\Models\PaymentMethod;
use App\Domain\Visit\Billing\Models\PaymentRefund;
use App\Domain\Visit\Models\Visit;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * BIL-32 (owner decisions 2026-11-20 to 2026-11-22): one shared guard for money actions.
 *
 * - Paused: while a branch-day's latest count waits for a decision, no money action that belongs to that day (by the
 *   original day of what it changes, or by today) is accepted. Registration and consultation are never gated (19 = A),
 *   and only the counted day pauses (Q2 = A).
 * - Locked: once a day's close is approved, no void, receipt reversal or correction, or invoice adjustment of that
 *   original day. A reversal inside a refund is allowed (Q5 = A); the caller says so with $lockable = false.
 *
 * Call it right after BillingContextService::lock and before any invoice or payment row lock: it takes the same
 * per-branch-day advisory lock as the cash count and approval (CashClosingService), in date order, so a money action
 * and an approval never deadlock. PostgreSQL triggers repeat the rules for direct writes.
 */
final class BranchDayGate
{
    public const PAUSED = 'Today\'s cash has been counted and is waiting for the Branch Manager\'s approval. New payments are paused until it is approved or returned for a recount.';

    public const PAUSED_EARLIER = 'This day\'s cash has been counted and is waiting for the Branch Manager\'s approval. Changes to that day\'s bills are paused until it is approved or returned for a recount.';

    public const LOCKED = 'This day\'s cash is closed and locked. A bill or receipt of that day cannot be changed or voided; only a refund is possible.';

    /** The advisory lock key shared with CashClosingService. */
    public static function lockKey(Branch $branch, string $date): string
    {
        return 'cash_closing:'.$branch->organisation_id.':'.$branch->id.':'.$date;
    }

    /**
     * @param  CarbonInterface|null  $original  when the changed receipt was received or the changed invoice finalised
     * @param  bool  $lockable  whether a locked original day refuses this action
     */
    public function guard(Branch $branch, ?CarbonInterface $original, bool $lockable, string $field = 'billing'): void
    {
        $today = now($branch->timezone)->toDateString();
        $originalDay = $original === null ? null : CarbonImmutable::instance($original)->setTimezone($branch->timezone)->toDateString();
        $days = array_values(array_unique(array_filter([$today, $originalDay])));
        sort($days);
        foreach ($days as $day) {
            DB::select('select pg_advisory_xact_lock(hashtextextended(?, 0))', [self::lockKey($branch, $day)]);
        }

        if ($lockable && $originalDay !== null && $this->locked($branch, $originalDay)) {
            throw ValidationException::withMessages([$field => self::LOCKED]);
        }
        if ($this->paused($branch, $today)) {
            throw ValidationException::withMessages([$field => self::PAUSED]);
        }
        if ($originalDay !== null && $originalDay !== $today && $this->paused($branch, $originalDay)) {
            throw ValidationException::withMessages([$field => self::PAUSED_EARLIER]);
        }
    }

    public function locked(Branch $branch, string $date): bool
    {
        return DB::table('cash_closing_locks')->where('organisation_id', $branch->organisation_id)->where('branch_id', $branch->id)
            ->where('business_date', $date)->exists();
    }

    public function paused(Branch $branch, string $date): bool
    {
        $latest = DB::table('cash_closings')->where('organisation_id', $branch->organisation_id)->where('branch_id', $branch->id)
            ->where('business_date', $date)->orderByDesc('revision')->first(['id']);

        return $latest !== null && ! DB::table('cash_closing_decisions')->where('cash_closing_id', $latest->id)->exists();
    }

    /**
     * BIL-32: the Patient Billing notice and the locked-day refund. Read only; the services still decide.
     *
     * @param  iterable<Payment>  $payments
     * @return array{paused: bool, message: string|null, lockedReceipts: list<array{publicId: string, number: string, amountSen: int, lockVersion: int}>, canRefundLocked: bool, methods: list<array{id: int, name: string}>, reasons: array<string, string>}|null
     */
    public function billingState(User $actor, Branch $branch, Visit $visit, ?Invoice $invoice, iterable $payments): ?array
    {
        $today = now($branch->timezone)->toDateString();
        $invoiceDay = $invoice?->finalized_at?->setTimezone($branch->timezone)->toDateString();
        $paused = $this->paused($branch, $today) || ($invoiceDay !== null && $this->paused($branch, $invoiceDay));
        $locked = [];
        foreach ($payments as $p) {
            if ($p->status === 'posted' && $this->locked($branch, $p->received_at->setTimezone($branch->timezone)->toDateString())) {
                $locked[] = ['publicId' => (string) $p->public_id, 'number' => (string) $p->receipt_number, 'amountSen' => (int) $p->amount_sen, 'lockVersion' => (int) $p->lock_version];
            }
        }
        $invoiceLocked = $invoiceDay !== null && $this->locked($branch, $invoiceDay);
        if (! $paused && $locked === [] && ! $invoiceLocked) {
            return null;
        }
        $canRefund = $visit->status === Visit::STATUS_REGISTERED && $actor->can('payments.reverse.branch') && $actor->can('payments.refunds.record.branch');

        return [
            'paused' => $paused,
            'message' => $paused ? ($this->paused($branch, $today) ? self::PAUSED : self::PAUSED_EARLIER) : self::LOCKED,
            'lockedReceipts' => $locked,
            'canRefundLocked' => $canRefund && $locked !== [] && ! $paused,
            'methods' => $canRefund ? PaymentMethod::query()->where('organisation_id', $actor->organisation_id)->operational()->orderBy('sort_order')->orderBy('name')->get()
                ->map(fn ($m): array => ['id' => (int) $m->id, 'name' => (string) $m->name])->all() : [],
            'reasons' => PaymentRefund::REASONS,
        ];
    }

    /**
     * For screens: whether money actions are paused today, and whether a given original day is locked or paused.
     *
     * @return array{pausedToday: bool, originalLocked: bool, originalPaused: bool}
     */
    public function state(Branch $branch, ?CarbonInterface $original): array
    {
        $originalDay = $original === null ? null : CarbonImmutable::instance($original)->setTimezone($branch->timezone)->toDateString();

        return [
            'pausedToday' => $this->paused($branch, now($branch->timezone)->toDateString()),
            'originalLocked' => $originalDay !== null && $this->locked($branch, $originalDay),
            'originalPaused' => $originalDay !== null && $this->paused($branch, $originalDay),
        ];
    }
}
