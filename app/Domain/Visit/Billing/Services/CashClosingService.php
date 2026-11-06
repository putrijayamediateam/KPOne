<?php

namespace App\Domain\Visit\Billing\Services;

use App\Domain\Access\BranchAccessService;
use App\Domain\Access\StaffAccessService;
use App\Domain\Access\TransactionalActorAuthority;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Visit\Billing\Models\CashClosing;
use App\Domain\Visit\Billing\Models\CashClosingDecision;
use App\Domain\Visit\Billing\Models\Payment;
use App\Domain\Visit\Billing\Models\PaymentRefund;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * BIL-4 (owner decisions 2026-11-05): daily cash closing per branch. Expected cash is the day's posted receipts paid by
 * Cash-classified methods (reversed receipts excluded), by branch-local receipt date. Staff count the takings only,
 * with the float taken out. Anyone with payments.reconcile.branch counts; a holder of
 * payments.cash_closing.approve.branch who did not count approves or returns the latest count. A day can be approved
 * only after it has ended in the branch time zone (owner decision 2026-11-05), so later cash that day is never left
 * outside a closing. Append-only; it never changes a receipt, invoice or payment.
 */
final class CashClosingService
{
    public const COUNT_PERMISSION = 'payments.reconcile.branch';

    public const APPROVE_PERMISSION = 'payments.cash_closing.approve.branch';

    public function __construct(private TransactionalActorAuthority $authority, private BranchAccessService $branches, private StaffAccessService $staff, private AuditRecorder $audit) {}

    /**
     * With $lock, the counted receipts are share-locked so a reversal cannot commit while an approval is decided.
     *
     * @return array{count:int, sen:int}
     */
    public function expected(Branch $branch, CarbonImmutable $date, bool $lock = false): array
    {
        $start = $date->startOfDay()->utc();
        $end = $date->addDay()->startOfDay()->utc();
        $cash = DB::table('payment_methods')->select('id')->where('organisation_id', $branch->organisation_id)->where('classification', 'cash');
        // BIL-6 (owner decision 2026-11-05): a reversed receipt whose money was refunded did come in, so it counts
        // as received on its day; the refund counts as paid out on its own payout day. Rejected refunds count for nothing.
        $refunded = $this->openRefunds()->select('payment_id');
        $query = PostedReceiptProjection::query($branch->organisation_id, $branch->id, $start, $end, ['posted', 'reversed'])->whereIn('payment_method_id', $cash)
            ->where(fn ($q) => $q->where('status', 'posted')->orWhere(fn ($r) => $r->where('status', 'reversed')->whereIn('id', $refunded)));
        $refunds = $this->openRefunds()->where('organisation_id', $branch->organisation_id)->where('branch_id', $branch->id)
            ->where('paid_out_at', '>=', $start)->where('paid_out_at', '<', $end)->whereIn('payment_method_id', clone $cash);
        if ($lock) {
            // Share-lock every Cash receipt of the day (posted or reversed) and the receipts of the day's refunds.
            // Reversals, refund recordings and refund decisions all lock their receipt first, so none of them can
            // change these figures until the approval commits; the figures are then read under those locks.
            PostedReceiptProjection::query($branch->organisation_id, $branch->id, $start, $end, ['posted', 'reversed'])->whereIn('payment_method_id', clone $cash)
                ->orderBy('id')->sharedLock()->get(['id']);
            Payment::query()->whereIn('id', (clone $refunds)->select('payment_id'))->orderBy('id')->sharedLock()->get(['id']);
        }

        return ['count' => (clone $query)->count(), 'sen' => (int) (clone $query)->sum('amount_sen') - (int) (clone $refunds)->sum('amount_sen')];
    }

    /** @return array{count:int, sen:int, waiting:int} Cash refunds paid out on the day (not rejected), for display. */
    public function cashRefunds(Branch $branch, CarbonImmutable $date): array
    {
        $rows = $this->openRefunds()->with('decision')->where('organisation_id', $branch->organisation_id)->where('branch_id', $branch->id)
            ->where('paid_out_at', '>=', $date->startOfDay()->utc())->where('paid_out_at', '<', $date->addDay()->startOfDay()->utc())
            ->whereIn('payment_method_id', DB::table('payment_methods')->select('id')->where('organisation_id', $branch->organisation_id)->where('classification', 'cash'))->get();

        return ['count' => $rows->count(), 'sen' => (int) $rows->sum('amount_sen'), 'waiting' => $rows->filter(fn (PaymentRefund $r): bool => $r->decision === null)->count()];
    }

    /** Waiting refunds (any payout day) of this day's reversed Cash receipts: their decision moves this day's figure. */
    private function waitingRefundsOfDayReceipts(Branch $branch, CarbonImmutable $date): int
    {
        return PaymentRefund::query()->whereDoesntHave('decision')
            ->whereIn('payment_id', PostedReceiptProjection::query($branch->organisation_id, $branch->id, $date->startOfDay()->utc(), $date->addDay()->startOfDay()->utc(), ['reversed'])
                ->whereIn('payment_method_id', DB::table('payment_methods')->select('id')->where('organisation_id', $branch->organisation_id)->where('classification', 'cash'))->select('id'))
            ->count();
    }

    /** @return Builder<PaymentRefund> */
    private function openRefunds(): Builder
    {
        return PaymentRefund::query()->whereDoesntHave('decision', fn ($d) => $d->where('decision', 'rejected'));
    }

    /** A business date has ended once the branch-local clock has reached the next day. */
    public function dayEnded(Branch $branch, CarbonImmutable $date): bool
    {
        return $date->lessThan(now($branch->timezone)->startOfDay());
    }

    /** @return array<string, mixed> */
    public function page(User $actor, ?string $date): array
    {
        $branch = $this->branches->activeBranch($actor);
        abort_unless($branch && $branch->organisation_id === $actor->organisation_id, 404);
        $local = $this->localDate($branch, $date ?? now($branch->timezone)->toDateString());
        $expected = $this->expected($branch, $local);
        $closings = CashClosing::query()->with('decision')->where('organisation_id', $branch->organisation_id)->where('branch_id', $branch->id)
            ->whereDate('business_date', $local->toDateString())->orderByDesc('revision')->get();
        // Staff names follow the staff-directory visibility rule (as BIL-2 receipts do); others read "Staff member".
        $names = $this->staff->visibleUsers($actor)
            ->whereIn('id', $closings->pluck('counted_by_user_id')->merge($closings->pluck('decision.decided_by_user_id'))->filter()->unique())->pluck('name', 'id');
        $latest = $closings->first();
        $approvedClosing = $closings->first(fn (CashClosing $c): bool => $c->decision?->decision === 'approved');
        $approved = $approvedClosing !== null;
        $canApprove = $actor->can(self::APPROVE_PERMISSION);
        $dayEnded = $this->dayEnded($branch, $local);

        return [
            'branch' => $branch->name, 'branchId' => $branch->id, 'businessDate' => $local->toDateString(), 'today' => now($branch->timezone)->toDateString(),
            'expected' => ['receiptCount' => $expected['count'], 'cashSen' => $expected['sen']],
            'refunds' => $this->cashRefunds($branch, $local),
            'status' => $approved ? 'approved' : ($latest === null ? 'not_counted' : ($latest->decision === null ? 'waiting' : 'returned')),
            'dayEnded' => $dayEnded,
            // The approved closing keeps its snapshot; flag it when today's figures no longer match (e.g. a later reversal).
            'changedSinceApproval' => $approvedClosing !== null && ($approvedClosing->expected_receipt_count !== $expected['count'] || $approvedClosing->expected_cash_sen !== $expected['sen']),
            'can' => ['count' => ! $approved && $actor->can(self::COUNT_PERMISSION), 'approve' => $canApprove],
            'closings' => $closings->map(fn (CashClosing $c): array => [
                'publicId' => $c->public_id, 'revision' => $c->revision,
                'expectedReceiptCount' => $c->expected_receipt_count, 'expectedCashSen' => $c->expected_cash_sen,
                'countedCashSen' => $c->counted_cash_sen, 'varianceSen' => $c->variance_sen, 'varianceReason' => $c->variance_reason,
                'countedBy' => $names[$c->counted_by_user_id] ?? 'Staff member',
                'countedAt' => $c->counted_at->setTimezone($branch->timezone)->format('j M Y, g:i A'),
                'state' => $c->decision !== null ? $c->decision->decision : ($c->is($latest) ? 'waiting' : 'replaced'),
                'decisionReason' => $c->decision?->reason,
                'decidedBy' => $c->decision ? ($names[$c->decision->decided_by_user_id] ?? 'Staff member') : null,
                'decidedAt' => $c->decision?->decided_at->setTimezone($branch->timezone)->format('j M Y, g:i A'),
                'canDecide' => $canApprove && $c->decision === null && $c->is($latest) && $c->counted_by_user_id !== $actor->id,
                'canApproveNow' => $dayEnded,
                'countedByYou' => $c->counted_by_user_id === $actor->id,
            ])->values()->all(),
        ];
    }

    /** @param array<string, mixed> $input */
    public function count(User $actor, array $input): CashClosing
    {
        $counted = $this->money($input['counted_cash'] ?? null);
        $reason = $this->text($input['variance_reason'] ?? null, 'variance_reason');
        $key = $this->key($input['idempotency_key'] ?? null);

        return DB::transaction(function () use ($actor, $input, $counted, $reason, $key): CashClosing {
            $branch = $this->authority->branch($actor, $input['expected_branch_id'] ?? null);
            $actor = $this->authority->lock($actor, $branch, self::COUNT_PERMISSION);
            $date = $this->localDate($branch, $input['business_date'] ?? null);
            $this->serialize($branch, $date, $key);
            $hash = hash('sha256', json_encode([$actor->id, $branch->id, $date->toDateString(), $counted, $reason], JSON_THROW_ON_ERROR));
            $existing = CashClosing::query()->where('organisation_id', $branch->organisation_id)->where('idempotency_key', $key)->first();
            if ($existing !== null) {
                return $existing->payload_hash === $hash ? $existing : throw ValidationException::withMessages(['cash_closing' => 'This request was already used for a different count. Reload and try again.']);
            }
            $day = $this->day($branch, $date);
            if ($day->contains(fn (CashClosing $c): bool => $c->decision?->decision === 'approved')) {
                throw ValidationException::withMessages(['cash_closing' => 'This day is already closed and approved. It cannot be counted again.']);
            }
            $expected = $this->expected($branch, $date);
            $variance = $counted - $expected['sen'];
            if ($variance !== 0 && $reason === null) {
                throw ValidationException::withMessages(['variance_reason' => 'The counted cash differs from the expected cash. Explain the difference.']);
            }

            $closing = new CashClosing;
            $closing->forceFill([
                'public_id' => (string) Str::uuid(), 'organisation_id' => $branch->organisation_id, 'branch_id' => $branch->id,
                'business_date' => $date->toDateString(), 'revision' => (int) $day->max('revision') + 1,
                'expected_receipt_count' => $expected['count'], 'expected_cash_sen' => $expected['sen'], 'counted_cash_sen' => $counted,
                'variance_sen' => $variance, 'variance_reason' => $variance === 0 ? null : $reason,
                'counted_by_user_id' => $actor->id, 'counted_at' => now()->utc(), 'idempotency_key' => $key, 'payload_hash' => $hash,
            ])->save();
            $this->audit->record('billing.cash_counted', $closing, ['business_date' => $date->toDateString(), 'revision' => $closing->revision,
                'expected_receipt_count' => $expected['count'], 'expected_cash_sen' => $expected['sen'], 'counted_cash_sen' => $counted, 'variance_sen' => $variance], $actor, $branch);

            return $closing;
        }, 3);
    }

    /** @param array<string, mixed> $input */
    public function decide(User $actor, string $publicId, string $decision, array $input): CashClosingDecision
    {
        abort_unless(in_array($decision, ['approved', 'returned'], true), 404);
        $reason = $this->text($input['reason'] ?? null, 'reason');
        if ($decision === 'returned' && $reason === null) {
            throw ValidationException::withMessages(['reason' => 'Say why the count is returned, so it can be counted again.']);
        }
        $key = $this->key($input['idempotency_key'] ?? null);

        return DB::transaction(function () use ($actor, $publicId, $decision, $input, $reason, $key): CashClosingDecision {
            $branch = $this->authority->branch($actor, $input['expected_branch_id'] ?? null);
            $actor = $this->authority->lock($actor, $branch, self::APPROVE_PERMISSION);
            $closing = CashClosing::query()->where('organisation_id', $branch->organisation_id)->where('branch_id', $branch->id)->where('public_id', $publicId)->first();
            abort_if($closing === null, 404);
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $closing->business_date->toDateString(), $branch->timezone) ?: throw new \LogicException('Invalid stored business date.');
            $this->serialize($branch, $date, $key);
            $hash = hash('sha256', json_encode([$actor->id, $closing->id, $decision, $decision === 'returned' ? $reason : null], JSON_THROW_ON_ERROR));
            $existing = CashClosingDecision::query()->where('organisation_id', $branch->organisation_id)->where('idempotency_key', $key)->first();
            if ($existing !== null) {
                return $existing->payload_hash === $hash ? $existing : throw ValidationException::withMessages(['cash_closing' => 'This request was already used for a different decision. Reload and try again.']);
            }
            if ($closing->counted_by_user_id === $actor->id) {
                throw ValidationException::withMessages(['cash_closing' => 'You counted this cash. Someone else must approve or return it.']);
            }
            $day = $this->day($branch, $date);
            if ($closing->decision()->exists() || (int) $day->max('revision') !== $closing->revision) {
                throw ValidationException::withMessages(['cash_closing' => 'This count was already decided or replaced by a newer count. Reload the page.']);
            }
            if ($decision === 'approved' && ! $this->dayEnded($branch, $date)) {
                throw ValidationException::withMessages(['cash_closing' => 'A day can be approved only after it ends (from midnight, branch time). Return it now if it needs a recount.']);
            }
            if ($decision === 'approved' && ($this->cashRefunds($branch, $date)['waiting'] > 0 || $this->waitingRefundsOfDayReceipts($branch, $date) > 0)) {
                throw ValidationException::withMessages(['cash_closing' => 'A refund on this day\'s cash is still waiting for approval. Approve or reject it first.']);
            }
            if ($decision === 'approved' && $this->expected($branch, $date, lock: true) !== ['count' => $closing->expected_receipt_count, 'sen' => $closing->expected_cash_sen]) {
                throw ValidationException::withMessages(['cash_closing' => 'Cash receipts or refunds (or which payment methods count as Cash) changed after the count. Return it so the cash can be counted again.']);
            }

            $row = new CashClosingDecision;
            $row->forceFill(['organisation_id' => $branch->organisation_id, 'cash_closing_id' => $closing->id, 'decision' => $decision,
                'reason' => $decision === 'returned' ? $reason : null, 'decided_by_user_id' => $actor->id, 'decided_at' => now()->utc(),
                'idempotency_key' => $key, 'payload_hash' => $hash])->save();
            $this->audit->record($decision === 'approved' ? 'billing.cash_closing_approved' : 'billing.cash_closing_returned', $closing,
                ['business_date' => $date->toDateString(), 'revision' => $closing->revision, 'variance_sen' => $closing->variance_sen], $actor, $branch);

            return $row;
        }, 3);
    }

    /**
     * BIL-7: read-only month view of the active branch, one row per day up to today. A missing, malformed or future
     * month shows the current branch month. Figures are each day's latest count (an approved day's approved count);
     * "changed after approval" uses the same rule as the daily page.
     *
     * @return array<string, mixed>
     */
    public function month(User $actor, ?string $month): array
    {
        $branch = $this->branches->activeBranch($actor);
        abort_unless($branch && $branch->organisation_id === $actor->organisation_id, 404);
        $today = CarbonImmutable::now($branch->timezone)->startOfDay();
        $first = is_string($month) && preg_match('/\A\d{4}-\d{2}\z/', $month) === 1 ? CarbonImmutable::createFromFormat('!Y-m', $month, $branch->timezone) : false;
        if (! $first || $first->format('Y-m') !== $month || $first->greaterThan($today) || $first->year < 2000) {
            $first = $today->startOfMonth();
        }
        $monthEnd = $first->endOfMonth()->startOfDay();
        $last = $monthEnd->lessThan($today) ? $monthEnd : $today;
        $closings = CashClosing::query()->with('decision')->where('organisation_id', $branch->organisation_id)->where('branch_id', $branch->id)
            ->whereDate('business_date', '>=', $first->toDateString())->whereDate('business_date', '<=', $last->toDateString())
            ->orderByDesc('revision')->get();
        $byDay = $closings->groupBy(fn (CashClosing $c): string => $c->business_date->toDateString());
        $names = $this->staff->visibleUsers($actor)
            ->whereIn('id', $closings->pluck('counted_by_user_id')->merge($closings->pluck('decision.decided_by_user_id'))->filter()->unique())->pluck('name', 'id');

        $rows = [];
        $summary = ['approved' => 0, 'waiting' => 0, 'returned' => 0, 'notCounted' => 0, 'countedSen' => 0, 'expectedSen' => 0, 'differenceSen' => 0, 'countsShown' => 0];
        for ($day = $first; $day->lessThanOrEqualTo($last); $day = $day->addDay()) {
            /** @var Collection<int, CashClosing> $dayClosings */
            $dayClosings = $byDay->get($day->toDateString(), new Collection);
            $latest = $dayClosings->first();
            $approvedClosing = $dayClosings->first(fn (CashClosing $c): bool => $c->decision?->decision === 'approved');
            $shown = $approvedClosing ?? $latest;
            $ended = $day->lessThan($today); // dayEnded()'s rule, from the one clock reading above.
            $status = $approvedClosing !== null ? 'approved' : (! $ended ? 'open' : ($latest === null ? 'not_counted' : ($latest->decision === null ? 'waiting' : 'returned')));
            $changed = false;
            if ($approvedClosing !== null) {
                $expected = $this->expected($branch, $day);
                $changed = $approvedClosing->expected_receipt_count !== $expected['count'] || $approvedClosing->expected_cash_sen !== $expected['sen'];
            }
            $flags = array_values(array_filter([
                $status === 'not_counted' ? 'no_count' : null,
                in_array($status, ['waiting', 'returned'], true) ? 'not_approved' : null,
                $changed ? 'changed_after_approval' : null,
            ]));
            $key = ['approved' => 'approved', 'waiting' => 'waiting', 'returned' => 'returned', 'not_counted' => 'notCounted'][$status] ?? null;
            if ($key !== null) {
                $summary[$key]++;
            }
            if ($shown !== null) {
                $summary['countedSen'] += $shown->counted_cash_sen;
                $summary['expectedSen'] += $shown->expected_cash_sen;
                $summary['differenceSen'] += $shown->variance_sen;
                $summary['countsShown']++;
            }
            $rows[] = [
                'date' => $day->toDateString(),
                'status' => $status,
                'countedCashSen' => $shown?->counted_cash_sen,
                'expectedCashSen' => $shown?->expected_cash_sen,
                'differenceSen' => $shown?->variance_sen,
                'countedBy' => $shown ? ($names[$shown->counted_by_user_id] ?? 'Staff member') : null,
                'decision' => $shown?->decision?->decision,
                'decidedBy' => $shown?->decision ? ($names[$shown->decision->decided_by_user_id] ?? 'Staff member') : null,
                'flags' => $flags,
            ];
        }
        $current = $first->format('Y-m') === $today->format('Y-m');

        return [
            'branch' => $branch->name, 'month' => $first->format('Y-m'), 'today' => $today->toDateString(), 'isCurrentMonth' => $current,
            'previousMonth' => $first->year === 2000 && $first->month === 1 ? null : $first->subMonth()->format('Y-m'), 'nextMonth' => $current ? null : $first->addMonth()->format('Y-m'),
            'summary' => $summary, 'hasClosings' => $closings->isNotEmpty(), 'rows' => $rows,
        ];
    }

    /** One writer at a time per branch and business date, then per request key (so a reused key never races). */
    private function serialize(Branch $branch, CarbonImmutable $date, string $key): void
    {
        DB::select('select pg_advisory_xact_lock(hashtextextended(?, 0))', ['cash_closing:'.$branch->organisation_id.':'.$branch->id.':'.$date->toDateString()]);
        DB::select('select pg_advisory_xact_lock(hashtextextended(?, 0))', ['cash_closing_key:'.$branch->organisation_id.':'.$key]);
    }

    /** @return Collection<int, CashClosing> */
    private function day(Branch $branch, CarbonImmutable $date): Collection
    {
        return CashClosing::query()->with('decision')->where('organisation_id', $branch->organisation_id)->where('branch_id', $branch->id)
            ->whereDate('business_date', $date->toDateString())->get();
    }

    private function localDate(Branch $branch, mixed $value): CarbonImmutable
    {
        $date = is_string($value) && preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $value) === 1 ? CarbonImmutable::createFromFormat('!Y-m-d', $value, $branch->timezone) : false;
        if (! $date || $date->format('Y-m-d') !== $value) {
            throw ValidationException::withMessages(['business_date' => 'Select a valid business date.']);
        }
        if ($date->greaterThan(now($branch->timezone)->startOfDay())) {
            throw ValidationException::withMessages(['business_date' => 'Choose today or an earlier business date.']);
        }

        return $date;
    }

    private function money(mixed $value): int
    {
        // A minus sign is allowed: with refunds paid out, the day's takings can be below zero.
        if (is_string($value)) {
            $value = str_replace("\u{2212}", '-', trim($value)); // The page shows a true minus sign; accept it back.
        }
        if ((! is_string($value) && ! is_int($value)) || preg_match('/\A(-?)(0|[1-9][0-9]{0,9})(?:\.([0-9]{1,2}))?\z/', (string) $value, $parts) !== 1) {
            throw ValidationException::withMessages(['counted_cash' => 'Enter the counted cash in ringgit, with no more than two decimal places.']);
        }
        $sen = ((int) $parts[2] * 100 + (int) str_pad($parts[3] ?? '', 2, '0')) * ($parts[1] === '-' ? -1 : 1);
        if (abs($sen) > ExactMoney::MAX_SEN) {
            throw ValidationException::withMessages(['counted_cash' => 'The amount exceeds the permitted limit.']);
        }

        return $sen;
    }

    private function text(mixed $value, string $field): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (! is_string($value) || mb_strlen($value) > 1000) {
            throw ValidationException::withMessages([$field => 'Use at most 1,000 characters.']);
        }

        return trim($value) === '' ? null : trim($value);
    }

    private function key(mixed $value): string
    {
        if (! is_string($value) || ! Str::isUuid($value)) {
            throw ValidationException::withMessages(['idempotency_key' => 'Refresh this page and try again.']);
        }

        return $value;
    }
}
