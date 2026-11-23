<?php

namespace App\Domain\Visit\Billing\Services;

use App\Domain\Access\BranchAccessService;
use App\Domain\Access\TransactionalActorAuthority;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Identity\Models\StaffBranchAssignment;
use App\Domain\Identity\Models\StaffProfile;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Shared\Services\InvoiceSalesProjection;
use App\Domain\Visit\Billing\Models\SalesTarget;
use App\Domain\Visit\Billing\Models\SalesTargetRevision;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * BIL-30: monthly sales targets per branch and per doctor (design note revision 2 and the owner's answers).
 *
 * - Director and Finance Officer (`sales_targets.manage.organisation`) set, change and clear targets at any branch of
 *   their organisation, for the current or a future month only (answer 1A), and see every target with its progress.
 * - A Resident Doctor (`sales_targets.view.own`) sees only their own line at their active branch (P7, K7).
 * - Achieved is the shared net sales figure (InvoiceSalesProjection::netSales), so it always matches Insights (K1, K2).
 * - Nothing here moves money.
 *
 * @phpstan-type Progress array{targetSen: int|null, achievedSen: int, balanceSen: int|null, overSen: int|null, neededPerDaySen: int|null, daysLeft: int|null, percent: int|null, reached: bool}
 */
final class SalesTargetService
{
    public const MANAGE = 'sales_targets.manage.organisation';

    public const VIEW_OWN = 'sales_targets.view.own';

    public function __construct(private TransactionalActorAuthority $authority, private BranchAccessService $branches, private AuditRecorder $audit) {}

    /**
     * The Sales targets page for one month: every active branch with its target and progress, then its doctors.
     *
     * @return array<string, mixed>
     */
    public function page(User $actor, ?string $month): array
    {
        $this->assertCan($actor, self::MANAGE);
        $active = $this->branches->activeBranch($actor);
        abort_unless($active && $active->organisation_id === $actor->organisation_id, 404);
        $branches = Branch::query()->where('organisation_id', $actor->organisation_id)->where('is_active', true)->orderBy('code')->get();
        $first = $this->monthOrCurrent($active, $month);
        $targets = SalesTarget::query()->where('organisation_id', $actor->organisation_id)->whereDate('month', $first->toDateString())
            ->whereIn('branch_id', $branches->pluck('id'))->get();

        $rows = $branches->map(function (Branch $branch) use ($actor, $first, $targets): array {
            $period = $this->period($branch, $first);
            $own = $targets->where('branch_id', $branch->id);
            $branchTarget = $own->first(fn (SalesTarget $t): bool => $t->doctor_user_id === null);
            $achieved = InvoiceSalesProjection::netSales($actor->organisation_id, [$branch->id => $period])['netSen'];
            $eligible = $this->eligibleDoctorIds($branch, $first);
            $doctorIds = collect($eligible)->merge($own->pluck('doctor_user_id')->filter())->unique()->values();
            $names = User::query()->where('organisation_id', $actor->organisation_id)->whereIn('id', $doctorIds)->pluck('name', 'id');
            $doctors = $doctorIds->map(function (int $doctorId) use ($actor, $branch, $period, $first, $own, $names, $eligible): array {
                $target = $own->first(fn (SalesTarget $t): bool => $t->doctor_user_id === $doctorId);
                $sales = InvoiceSalesProjection::netSales($actor->organisation_id, [$branch->id => $period], $doctorId)['netSen'];

                return ['doctorId' => $doctorId, 'name' => (string) ($names[$doctorId] ?? 'Staff member'), 'canSet' => in_array($doctorId, $eligible, true),
                    ...$this->target($target), ...$this->progress($branch, $first, $target?->amount_sen, $sales)];
            })->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)->values()->all();

            return ['branchId' => $branch->id, 'branch' => $branch->name, 'code' => $branch->code,
                ...$this->target($branchTarget), ...$this->progress($branch, $first, $branchTarget?->amount_sen, $achieved), 'doctors' => $doctors];
        })->values()->all();

        return [
            'month' => $first->format('Y-m'),
            'currentMonth' => CarbonImmutable::now($active->timezone)->format('Y-m'),
            'open' => $this->isOpen($active, $first),
            'expectedBranchId' => $active->id,
            'branches' => $rows,
        ];
    }

    /**
     * "My sales target": the signed-in doctor's own line at their active branch this month, or null when no target is
     * set. The doctor comes only from the signed-in user, never from the request (K7).
     *
     * @return (array{month: string, branch: string}&Progress)|null
     */
    public function own(User $actor): ?array
    {
        if (! $actor->is_active || ! $actor->can(self::VIEW_OWN)) {
            return null;
        }
        $branch = $this->branches->activeBranch($actor);
        if (! $branch || $branch->organisation_id !== $actor->organisation_id) {
            return null;
        }
        $first = CarbonImmutable::now($branch->timezone)->startOfMonth();
        $target = SalesTarget::query()->where('organisation_id', $actor->organisation_id)->where('branch_id', $branch->id)
            ->whereDate('month', $first->toDateString())->where('doctor_user_id', $actor->id)->whereNull('cleared_at')->first();
        if ($target === null) {
            return null;
        }
        $sales = InvoiceSalesProjection::netSales($actor->organisation_id, [$branch->id => $this->period($branch, $first)], $actor->id)['netSen'];

        return ['month' => $first->format('Y-m'), 'branch' => $branch->name, ...$this->progress($branch, $first, $target->amount_sen, $sales)];
    }

    /**
     * Set, change or clear one target. Input: expected_branch_id (the actor's active branch), branch_id, month (Y-m),
     * doctor_user_id (null for the branch target), amount (ringgit, empty to clear), lock_version, reason (needed to
     * change or clear) and idempotency_key.
     *
     * @param  array<string, mixed>  $input
     */
    public function save(User $actor, array $input): SalesTargetRevision
    {
        $key = $this->key($input['idempotency_key'] ?? null);
        $amount = $this->amount($input['amount'] ?? null);
        $reason = $this->reason($input['reason'] ?? null);
        $branchId = filter_var($input['branch_id'] ?? null, FILTER_VALIDATE_INT);
        $doctorId = ($input['doctor_user_id'] ?? null) === null || ($input['doctor_user_id'] ?? null) === '' ? null : filter_var($input['doctor_user_id'], FILTER_VALIDATE_INT);
        $version = filter_var($input['lock_version'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        $month = $input['month'] ?? null;
        if ($branchId === false || $doctorId === false || $version === false || ! is_string($month) || preg_match('/\A\d{4}-\d{2}\z/', $month) !== 1) {
            throw ValidationException::withMessages(['amount' => 'Reload the page and try again.']);
        }
        $active = $this->authority->branch($actor, $input['expected_branch_id'] ?? null);
        $hash = hash_hmac('sha256', json_encode([$branchId, $month, $doctorId, $amount, $reason, $version], JSON_THROW_ON_ERROR), (string) config('app.key'));

        return DB::transaction(function () use ($actor, $active, $key, $hash, $branchId, $month, $doctorId, $amount, $reason, $version): SalesTargetRevision {
            $actor = $this->authority->lock($actor, $active, self::MANAGE);
            if (! $actor->getAllPermissions()->contains('name', self::MANAGE)) {
                throw new AuthorizationException('You may not perform this operation.');
            }
            DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ['sales-target-key:'.$actor->organisation_id.':'.$key]);
            $previous = SalesTargetRevision::query()->where('organisation_id', $actor->organisation_id)->where('idempotency_key', $key)->first();
            if ($previous !== null) {
                if ($previous->actor_user_id !== $actor->id || ! hash_equals($previous->payload_hash, $hash)) {
                    throw ValidationException::withMessages(['idempotency_key' => 'This retry key has already been used for another change. Reload and try again.']);
                }

                return $previous;
            }

            // K6: the branch row first, so two first sets of the same target queue here instead of racing the unique key.
            $branch = Branch::query()->whereKey($branchId)->where('organisation_id', $actor->organisation_id)->where('is_active', true)->lockForUpdate()->first();
            if ($branch === null) {
                throw ValidationException::withMessages(['amount' => 'This branch is not available. Reload the page.']);
            }
            $first = CarbonImmutable::createFromFormat('!Y-m', $month, $branch->timezone);
            if ($first === null || $first->format('Y-m') !== $month || $first->year < 2000) {
                throw ValidationException::withMessages(['amount' => 'Reload the page and try again.']);
            }
            if (! $this->isOpen($branch, $first)) {
                throw ValidationException::withMessages(['amount' => 'This month has ended. Its targets can no longer be changed.']);
            }
            if ($doctorId !== null) {
                $this->lockEligibleDoctor($doctorId, $branch, $first);
            }

            $target = SalesTarget::query()->where('organisation_id', $actor->organisation_id)->where('branch_id', $branch->id)
                ->whereDate('month', $first->toDateString())->where(fn ($q) => $doctorId === null ? $q->whereNull('doctor_user_id') : $q->where('doctor_user_id', $doctorId))
                ->lockForUpdate()->first();
            if (($target?->lock_version ?? 0) !== $version) {
                throw ValidationException::withMessages(['amount' => 'This target changed while you were editing. Reload and try again.']);
            }
            $old = $target?->amount_sen;
            if ($amount === null && $old === null) {
                throw ValidationException::withMessages(['amount' => 'Enter the target in ringgit.']);
            }
            if ($old !== null && $reason === null) {
                throw ValidationException::withMessages(['reason' => $amount === null ? 'Write why the target is cleared.' : 'Write why the target is changed.']);
            }
            if ($old !== null && $amount === $old) {
                throw ValidationException::withMessages(['amount' => 'The target is already this amount.']);
            }
            $action = $old === null ? 'set' : ($amount === null ? 'cleared' : 'changed');
            $now = now()->utc();
            if ($target === null) {
                $target = SalesTarget::query()->forceCreate([
                    'organisation_id' => $actor->organisation_id, 'branch_id' => $branch->id, 'month' => $first->toDateString(), 'doctor_user_id' => $doctorId,
                    'amount_sen' => $amount, 'cleared_at' => null, 'lock_version' => 0, 'created_by_user_id' => $actor->id, 'updated_by_user_id' => $actor->id,
                ]);
            } else {
                $target->forceFill(['amount_sen' => $amount, 'cleared_at' => $amount === null ? $now : null, 'lock_version' => $target->lock_version + 1, 'updated_by_user_id' => $actor->id])->save();
            }
            $revision = SalesTargetRevision::query()->forceCreate([
                'organisation_id' => $actor->organisation_id, 'branch_id' => $branch->id, 'sales_target_id' => $target->id, 'month' => $first->toDateString(),
                'doctor_user_id' => $doctorId, 'action' => $action, 'old_amount_sen' => $old, 'new_amount_sen' => $amount, 'reason' => $action === 'set' ? null : $reason,
                'target_version' => $target->lock_version, 'actor_user_id' => $actor->id, 'idempotency_key' => $key, 'payload_hash' => $hash, 'created_at' => $now,
            ]);
            $this->audit->record('sales_target.'.$action, $target, ['month' => $first->format('Y-m'), 'doctor_target' => $doctorId !== null,
                'old_amount_sen' => $old, 'new_amount_sen' => $amount, 'record_version' => $target->lock_version], $actor, $branch);

            return $revision;
        }, 3);
    }

    /**
     * K4: an active Resident Doctor whose assignment at this branch overlaps the target month.
     *
     * @return list<int>
     */
    public function eligibleDoctorIds(Branch $branch, CarbonImmutable $first): array
    {
        [$from, $until] = [$first->toDateString(), $first->endOfMonth()->toDateString()];

        return User::query()->where('organisation_id', $branch->organisation_id)->where('is_active', true)->role('resident_doctor')
            ->whereHas('staffProfile.branchAssignments', fn ($q) => $q->where('branch_id', $branch->id)->whereDate('valid_from', '<=', $until)
                ->where(fn ($p) => $p->whereNull('valid_until')->orWhereDate('valid_until', '>=', $from)))
            ->orderBy('id')->pluck('id')->map(fn ($id): int => (int) $id)->values()->all();
    }

    private function lockEligibleDoctor(int $doctorId, Branch $branch, CarbonImmutable $first): void
    {
        $doctor = User::query()->whereKey($doctorId)->where('organisation_id', $branch->organisation_id)->lockForUpdate()->first();
        $profile = $doctor ? StaffProfile::query()->where('user_id', $doctor->id)->lockForUpdate()->first() : null;
        $assignments = $profile ? StaffBranchAssignment::query()->where('staff_profile_id', $profile->id)->orderBy('id')->lockForUpdate()->get() : collect();
        [$from, $until] = [$first->toDateString(), $first->endOfMonth()->toDateString()];
        $overlaps = $assignments->contains(fn (StaffBranchAssignment $a): bool => $a->branch_id === $branch->id
            && $a->valid_from->toDateString() <= $until && ($a->valid_until === null || $a->valid_until->toDateString() >= $from));
        if (! $doctor || ! $doctor->is_active || ! $doctor->hasRole('resident_doctor') || ! $overlaps) {
            throw ValidationException::withMessages(['amount' => 'This doctor does not work at this branch in this month.']);
        }
    }

    /** @return array{start: CarbonImmutable, end: CarbonImmutable} */
    private function period(Branch $branch, CarbonImmutable $first): array
    {
        $local = CarbonImmutable::createFromFormat('!Y-m-d', $first->toDateString(), $branch->timezone);

        return ['start' => $local->utc(), 'end' => $local->addMonthNoOverflow()->utc()];
    }

    private function isOpen(Branch $branch, CarbonImmutable $first): bool
    {
        return $first->format('Y-m') >= CarbonImmutable::now($branch->timezone)->format('Y-m');
    }

    private function monthOrCurrent(Branch $branch, ?string $month): CarbonImmutable
    {
        $current = CarbonImmutable::now($branch->timezone)->startOfMonth();
        $first = is_string($month) && preg_match('/\A\d{4}-\d{2}\z/', $month) === 1 ? CarbonImmutable::createFromFormat('!Y-m', $month, $branch->timezone) : null;
        if ($first === null || $first->format('Y-m') !== $month || $first->year < 2000 || $first->greaterThan($current->addYear())) {
            return $current;
        }

        return $first;
    }

    /** @return array{targetSen: int|null, lockVersion: int} */
    private function target(?SalesTarget $target): array
    {
        return ['targetSen' => $target?->amount_sen, 'lockVersion' => $target->lock_version ?? 0];
    }

    /**
     * Balance, the amount over, and needed per day: calendar days left in the month counting today (owner 18 = A),
     * rounded up to the sen; the whole month for a future month; not shown once the month has ended.
     *
     * @return Progress
     */
    public function progress(Branch $branch, CarbonImmutable $first, ?int $targetSen, int $achievedSen): array
    {
        $today = CarbonImmutable::now($branch->timezone)->startOfDay();
        $monthKey = $first->format('Y-m');
        $daysLeft = match (true) {
            $monthKey < $today->format('Y-m') => null,
            $monthKey > $today->format('Y-m') => $first->daysInMonth,
            default => $today->daysInMonth - $today->day + 1,
        };
        if ($targetSen === null) {
            return ['targetSen' => null, 'achievedSen' => $achievedSen, 'balanceSen' => null, 'overSen' => null, 'neededPerDaySen' => null, 'daysLeft' => $daysLeft, 'percent' => null, 'reached' => false];
        }
        $balance = $targetSen - $achievedSen;
        $reached = $balance <= 0;

        return [
            'targetSen' => $targetSen, 'achievedSen' => $achievedSen,
            'balanceSen' => $reached ? 0 : $balance, 'overSen' => $reached ? -$balance : null,
            'neededPerDaySen' => $reached || $daysLeft === null ? null : intdiv($balance + $daysLeft - 1, $daysLeft),
            'daysLeft' => $daysLeft, 'percent' => (int) floor(max(0, $achievedSen) * 100 / $targetSen), 'reached' => $reached,
        ];
    }

    private function assertCan(User $actor, string $permission): void
    {
        if (! $actor->is_active || ! $actor->can($permission)) {
            throw new AuthorizationException('You may not perform this operation.');
        }
    }

    private function amount(mixed $value): ?int
    {
        if ($value === null || (is_string($value) && trim($value) === '')) {
            return null;
        }
        if (is_string($value)) {
            $value = str_replace(',', '', trim($value));
        }
        if ((! is_string($value) && ! is_int($value)) || preg_match('/\A(0|[1-9][0-9]{0,9})(?:\.([0-9]{1,2}))?\z/', (string) $value, $parts) !== 1) {
            throw ValidationException::withMessages(['amount' => 'Enter the target in ringgit, with no more than two decimal places.']);
        }
        $sen = (int) $parts[1] * 100 + (int) str_pad($parts[2] ?? '', 2, '0');
        if ($sen < 1) {
            throw ValidationException::withMessages(['amount' => 'Enter a target above RM 0.00, or leave it empty to clear it.']);
        }
        if ($sen > ExactMoney::MAX_SEN) {
            throw ValidationException::withMessages(['amount' => 'The amount exceeds the permitted limit.']);
        }

        return $sen;
    }

    private function reason(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (! is_string($value) || mb_strlen(trim($value)) > 200 || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $value) === 1) {
            throw ValidationException::withMessages(['reason' => 'Use at most 200 characters.']);
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
