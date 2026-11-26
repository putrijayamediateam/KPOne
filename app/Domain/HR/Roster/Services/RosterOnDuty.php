<?php

namespace App\Domain\HR\Roster\Services;

use App\Domain\Access\StaffKind;
use App\Domain\HR\Leave\Models\LeaveRequest;
use App\Domain\HR\Leave\Services\LeaveCalendar;
use App\Domain\HR\Roster\Models\RosterShift;
use App\Domain\HR\Roster\Models\RosterVersion;
use App\Domain\HR\Roster\Models\RosterWeek;
use App\Domain\HR\Roster\OnDutyShift;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Queue\Models\BranchRoom;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * HR-22a (HR-PLAN 29.2; owner, via B.E.R.N.I.S.S, 24 Nov 2026): who is on the approved roster at a branch. Only doctors on
 * today's approved roster get patients (AUTO-1), so Queue and Registration ask here instead of reading `hr_` tables.
 *
 * Rules, shared by every answer:
 * - Only the currently approved version of a branch week counts; drafts, weeks waiting for approval and replaced versions
 *   never do.
 * - On-call shifts are never on duty (HR-20). Inactive accounts are left out.
 * - Approved leave: for a moment (`onDutyNow`, `isOnDuty`), a person whose approved leave covers that moment is left out;
 *   for a day (`onDutyOn`), a shift is left out only when the person's approved leave (slots joined) covers all of it, so
 *   someone on a half day still shows for the shift they work.
 * - Time zones: the moment check compares leave slots (branch-local wall-clock times) with this branch's local time, as
 *   the HR dashboard always has; the day check converts each slot with its own leave branch's zone. Every branch is in
 *   Kuala Lumpur time today, so both agree; if a branch in another zone is ever added, move the moment check to utcSlots.
 *
 * For callers:
 * - There is no permission check here. A caller must hold its own branch-scoped permission and must never pass this list
 *   to a page without staff access (the TV, the public QR pages).
 * - An empty list means either "not published" or "nobody on duty": call `isPublished` first and decide the fallback.
 * - `isOnDuty` is the moment rule; for "rostered today" use `onDutyOn`.
 *
 * Read only: no lock, no write, no audit and no permission check. The calling domain holds its own branch-scoped
 * permission, and this returns ids, role names, times and room ids only. Batched: shifts, leave, roles and rooms in one
 * query each, never one per person.
 */
final class RosterOnDuty
{
    public function __construct(private readonly LeaveCalendar $leave) {}

    /** True when the branch-local week holding `$day` has an approved version (even one with no shift that day). */
    public function isPublished(Branch $branch, CarbonInterface $day): bool
    {
        $local = $this->localDay($branch, $day);
        $monday = $local->subDays($local->dayOfWeekIso - 1)->toDateString();

        return RosterVersion::query()->where('organisation_id', $branch->organisation_id)->where('branch_id', $branch->id)->where('status', 'approved')
            ->whereIn('roster_week_id', RosterWeek::query()->select('id')->where('branch_id', $branch->id)->whereDate('week_start', $monday))
            ->exists();
    }

    /**
     * AUTO-1c: distinguish an ended/not-started shift from approved leave, before the day lookup removes full-day leave.
     * Read only, batched, and no leave reason or balance leaves HR. Boundaries are start-inclusive/end-exclusive.
     *
     * @param  list<int>  $userIds
     * @return array<int, array{scheduledToday: bool, scheduledNow: bool, onLeave: bool}>
     */
    public function availabilityForWarnings(Branch $branch, CarbonInterface $at, array $userIds): array
    {
        $ids = array_values(array_unique($userIds));
        if ($ids === []) {
            return [];
        }
        $moment = CarbonImmutable::instance($at)->utc();
        $localDay = $this->localDay($branch, $at);
        $shifts = $this->shifts($branch, $localDay->utc(), $localDay->addDay()->utc(), onCall: false, userId: null);
        $away = $this->leave->overlapping($ids, $moment, $moment->addMicrosecond())
            ->where('organisation_id', $branch->organisation_id)->pluck('user_id')->flip();
        $out = [];
        foreach ($ids as $id) {
            $out[$id] = ['scheduledToday' => false, 'scheduledNow' => false, 'onLeave' => $away->has($id)];
        }
        foreach ($shifts as $shift) {
            $id = (int) $shift->staff_user_id;
            if (! isset($out[$id])) {
                continue;
            }
            $out[$id]['scheduledToday'] = true;
            if ($shift->starts_at->lessThanOrEqualTo($moment) && $shift->ends_at->greaterThan($moment)) {
                $out[$id]['scheduledNow'] = true;
            }
        }

        return $out;
    }

    /** @return list<OnDutyShift> Shifts covering `$at`, in start order. */
    public function onDutyNow(Branch $branch, CarbonInterface $at): array
    {
        return $this->now($branch, CarbonImmutable::instance($at)->utc(), onCall: false, userId: null);
    }

    /**
     * On-call shifts covering `$at`, for HR's own dashboard only (pinned by a test). On call is never on duty.
     *
     * @return list<OnDutyShift>
     */
    public function onCallNowForHrDashboard(Branch $branch, CarbonInterface $at): array
    {
        return $this->now($branch, CarbonImmutable::instance($at)->utc(), onCall: true, userId: null);
    }

    public function isOnDuty(int $userId, Branch $branch, CarbonInterface $at): bool
    {
        return $this->now($branch, CarbonImmutable::instance($at)->utc(), onCall: false, userId: $userId) !== [];
    }

    /**
     * Shifts overlapping the branch-local day of `$day` (an overnight shift from the day before counts until it ends).
     *
     * @return list<OnDutyShift>
     */
    public function onDutyOn(Branch $branch, CarbonInterface $day): array
    {
        return $this->day($branch, $day, userId: null);
    }

    /**
     * HR-22d (HR-PLAN 29.5): one person's shift for their own home: the shift covering `$at`, otherwise their next shift later
     * that branch-local day, or null. Uses the day rule for leave (a shift leave covers in full is left out), so a person on a
     * half day still sees the shift they work.
     */
    public function forUser(int $userId, Branch $branch, CarbonInterface $at): ?OnDutyShift
    {
        $moment = CarbonImmutable::instance($at)->utc();
        foreach ($this->day($branch, $at, $userId) as $shift) {
            if ($shift->endsAt->greaterThan($moment)) {
                return $shift;
            }
        }

        return null;
    }

    /** @return list<OnDutyShift> */
    private function day(Branch $branch, CarbonInterface $day, ?int $userId): array
    {
        $local = $this->localDay($branch, $day);
        $shifts = $this->shifts($branch, $local->utc(), $local->addDay()->utc(), onCall: false, userId: $userId);
        if ($shifts->isEmpty()) {
            return [];
        }
        $from = CarbonImmutable::instance($shifts->min('starts_at'))->utc();
        $to = CarbonImmutable::instance($shifts->max('ends_at'))->utc();
        $requests = $this->leave->overlapping($this->userIds($shifts), $from, $to);
        if ($requests->isNotEmpty()) {
            $zones = Branch::query()->whereIn('id', $requests->pluck('branch_id')->unique())->pluck('timezone', 'id');
            $slots = $requests->groupBy('user_id')->map(fn (Collection $rs): Collection => $rs->map(
                fn (LeaveRequest $r): array => $this->leave->utcSlots($r, (string) ($zones[$r->branch_id] ?? 'UTC'))));
            $merged = $slots->map(fn (Collection $userSlots): array => $this->merge($userSlots));
            $shifts = $shifts->reject(fn (RosterShift $s): bool => collect($merged[$s->staff_user_id] ?? [])->contains(
                fn (array $slot): bool => $slot[0]->lessThanOrEqualTo($s->starts_at) && $slot[1]->greaterThanOrEqualTo($s->ends_at),
            ))->values();
        }

        return $this->build($branch, $shifts);
    }

    /**
     * Join a person's leave slots that overlap or touch, so two half days that together cover a shift count as covering it.
     *
     * @param  Collection<int, array{CarbonImmutable, CarbonImmutable}>  $slots
     * @return list<array{CarbonImmutable, CarbonImmutable}>
     */
    private function merge(Collection $slots): array
    {
        $out = [];
        foreach ($slots->sortBy(fn (array $slot): int => $slot[0]->getTimestamp())->values() as $slot) {
            $last = count($out) - 1;
            if ($last >= 0 && $slot[0]->lessThanOrEqualTo($out[$last][1])) {
                $out[$last] = [$out[$last][0], $slot[1]->greaterThan($out[$last][1]) ? $slot[1] : $out[$last][1]];
            } else {
                $out[] = [$slot[0], $slot[1]];
            }
        }

        return $out;
    }

    /** @return list<OnDutyShift> */
    private function now(Branch $branch, CarbonImmutable $at, bool $onCall, ?int $userId): array
    {
        $shifts = $this->shifts($branch, $at, $at, $onCall, $userId);
        if ($shifts->isEmpty()) {
            return [];
        }
        // Leave slots are branch-local wall-clock times (LeaveCalendar::utcSlots), so compare with the branch's local now,
        // exactly as the HR dashboard always has.
        $localNow = $at->setTimezone($branch->timezone)->format('Y-m-d H:i:s');
        $away = LeaveRequest::query()->where('organisation_id', $branch->organisation_id)->whereIn('user_id', $this->userIds($shifts))->where('status', 'approved')
            ->where('slot_start', '<=', $localNow)->where('slot_end', '>', $localNow)->pluck('user_id')->map(fn ($id): int => (int) $id)->flip();

        return $this->build($branch, $shifts->reject(fn (RosterShift $s): bool => $away->has((int) $s->staff_user_id))->values());
    }

    /**
     * Approved shifts at the branch overlapping [from, to); with from = to, the shifts covering that moment.
     *
     * @return Collection<int, RosterShift>
     */
    private function shifts(Branch $branch, CarbonImmutable $from, CarbonImmutable $to, bool $onCall, ?int $userId): Collection
    {
        $query = RosterShift::query()
            ->where('organisation_id', $branch->organisation_id)
            ->where('branch_id', $branch->id)
            ->whereIn('roster_version_id', RosterVersion::query()->select('id')
                ->where('organisation_id', $branch->organisation_id)->where('branch_id', $branch->id)->where('status', 'approved'))
            ->whereIn('staff_user_id', User::query()->select('id')->where('organisation_id', $branch->organisation_id)->where('is_active', true))
            ->where($onCall ? fn ($q) => $q->where('session_type', RosterRules::ON_CALL) : fn ($q) => $q->where('session_type', '<>', RosterRules::ON_CALL));
        if ($from->equalTo($to)) {
            $query->where('starts_at', '<=', $from)->where('ends_at', '>', $from);
        } else {
            $query->where('starts_at', '<', $to)->where('ends_at', '>', $from);
        }
        if ($userId !== null) {
            $query->where('staff_user_id', $userId);
        }

        return $query->orderBy('starts_at')->orderBy('id')->get(['id', 'staff_user_id', 'starts_at', 'ends_at', 'timezone', 'branch_room_id', 'station']);
    }

    /**
     * @param  Collection<int, RosterShift>  $shifts
     * @return list<OnDutyShift>
     */
    private function build(Branch $branch, Collection $shifts): array
    {
        if ($shifts->isEmpty()) {
            return [];
        }
        $roles = User::query()->with('roles:id,name')->whereIn('id', $this->userIds($shifts))->get(['id'])
            ->mapWithKeys(fn (User $u): array => [(int) $u->id => array_values($u->roles->pluck('name')->map(fn ($n): string => (string) $n)->sort()->values()->all())]);
        $rooms = BranchRoom::query()->whereIn('id', $shifts->pluck('branch_room_id')->filter()->unique()->values())
            ->where('organisation_id', $branch->organisation_id)->where('branch_id', $branch->id)->where('is_active', true)
            ->pluck('id')->map(fn ($id): int => (int) $id)->flip();

        return array_values($shifts->map(function (RosterShift $s) use ($roles, $rooms): OnDutyShift {
            /** @var list<string> $held */
            $held = $roles[(int) $s->staff_user_id] ?? [];
            $room = $s->branch_room_id !== null && $rooms->has((int) $s->branch_room_id) ? (int) $s->branch_room_id : null;

            return new OnDutyShift((int) $s->staff_user_id, $held, StaffKind::rolesIncludeLocum($held), StaffKind::rolesIncludeDoctor($held),
                $s->starts_at->utc(), $s->ends_at->utc(), (string) $s->timezone, $room, $s->station);
        })->all());
    }

    /**
     * @param  Collection<int, RosterShift>  $shifts
     * @return list<int>
     */
    private function userIds(Collection $shifts): array
    {
        return array_values($shifts->pluck('staff_user_id')->map(fn ($id): int => (int) $id)->unique()->values()->all());
    }

    private function localDay(Branch $branch, CarbonInterface $day): CarbonImmutable
    {
        return CarbonImmutable::instance($day)->setTimezone($branch->timezone)->startOfDay();
    }
}
