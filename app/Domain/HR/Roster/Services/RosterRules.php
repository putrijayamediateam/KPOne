<?php

namespace App\Domain\HR\Roster\Services;

use App\Domain\HR\Roster\Models\RosterShift;
use App\Domain\HR\Roster\Models\RosterVersion;
use App\Domain\Identity\Models\StaffBranchAssignment;
use App\Domain\Identity\Models\StaffProfile;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Queue\Models\BranchRoom;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * HR-1 shift rules (B.E.R.N.I.S.S for the owner, 2026-11-05). A shift is a planned interval [start, end) in UTC with
 * the branch time zone recorded. Refused: a person who is not an active staff account, a branch-local date the shift
 * touches without an effective assignment to that branch, and an overlap with the same person's shifts in the same
 * draft or in any approved week at any branch. Warnings only: overlap with another week's open draft, a shared
 * proposed room and minimum staffing shortfalls. Read-only: nothing here writes.
 */
final class RosterRules
{
    public const SESSIONS = ['morning' => 'Morning', 'afternoon' => 'Afternoon', 'evening' => 'Evening', 'night' => 'Night', 'full_day' => 'Full day', 'other' => 'Other'];

    /** Roles counted for minimum staffing (B.E.R.N.I.S.S for the owner, 2026-11-05). */
    public const STAFFING_ROLES = ['resident_doctor' => ['Resident Doctor', 'Resident Doctors'], 'ca' => ['CA', 'CAs'], 'ca_supervisor' => ['CA Supervisor', 'CA Supervisors']];

    public const ROLE_LABELS = ['director' => 'Director', 'resident_doctor' => 'Resident Doctor', 'ca' => 'CA', 'ca_supervisor' => 'CA Supervisor',
        'panel_officer' => 'Panel Officer', 'finance_officer' => 'Finance Officer', 'administrator' => 'Administrator',
        'business_development' => 'Business Development', 'marketing' => 'Marketing', 'hr_manager' => 'HR Manager', 'technical_admin' => 'Technical Admin'];

    public const MAX_SHIFT_MINUTES = 16 * 60;

    public const MAX_BREAK_MINUTES = 180;

    /** The Monday (branch-local) of the week holding $date, or a validation error. */
    public function weekStart(Branch $branch, mixed $date, string $field = 'week'): CarbonImmutable
    {
        $day = $this->localDate($branch, $date, $field);

        return $day->subDays($day->dayOfWeekIso - 1);
    }

    public function localDate(Branch $branch, mixed $value, string $field): CarbonImmutable
    {
        $date = is_string($value) && preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $value) === 1 ? CarbonImmutable::createFromFormat('!Y-m-d', $value, $branch->timezone) : false;
        if (! $date || $date->format('Y-m-d') !== $value || $date->year < 2000 || $date->year > 2100) {
            throw ValidationException::withMessages([$field => 'Choose a valid date.']);
        }

        return $date;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{starts_at: CarbonImmutable, ends_at: CarbonImmutable, break_minutes: int, session_type: string, branch_room_id: int|null, staff_user_id: int}
     */
    public function parse(Branch $branch, CarbonImmutable $weekStart, array $input): array
    {
        $errors = [];
        $staff = filter_var($input['staff_user_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($staff === false) {
            $errors['staff_user_id'] = 'Choose a staff member.';
        }
        $date = null;
        try {
            $date = $this->localDate($branch, $input['date'] ?? null, 'date');
            if ($date->lessThan($weekStart) || $date->greaterThan($weekStart->addDays(6))) {
                $errors['date'] = 'Choose a date in this week ('.$this->dayLabel($weekStart).' to '.$this->dayLabel($weekStart->addDays(6)).').';
            }
        } catch (ValidationException) {
            $errors['date'] = 'Choose a valid date.';
        }
        $start = $this->time($input['start_time'] ?? null);
        $end = $this->time($input['end_time'] ?? null);
        if ($start === null) {
            $errors['start_time'] = 'Enter a start time, for example 08:00.';
        }
        if ($end === null) {
            $errors['end_time'] = 'Enter an end time, for example 14:00.';
        }
        $break = $input['break_minutes'] ?? 0;
        $break = $break === null || $break === '' ? 0 : filter_var($break, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => self::MAX_BREAK_MINUTES]]);
        if ($break === false) {
            $errors['break_minutes'] = 'Enter a break from 0 to 180 minutes.';
        }
        $session = $input['session_type'] ?? null;
        if (! is_string($session) || ! array_key_exists($session, self::SESSIONS)) {
            $errors['session_type'] = 'Choose a session.';
        }
        $room = $input['branch_room_id'] ?? null;
        $room = $room === null || $room === '' ? null : filter_var($room, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($room === false) {
            $errors['branch_room_id'] = 'Choose a room at this branch.';
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
        /** @var CarbonImmutable $date */
        /** @var array{int, int} $start */
        /** @var array{int, int} $end */
        $startsAt = $date->setTime($start[0], $start[1]);
        $endsAt = $date->setTime($end[0], $end[1]);
        if ($endsAt->lessThanOrEqualTo($startsAt)) {
            $endsAt = $endsAt->addDay(); // An end at or before the start ends the next day.
        }
        $minutes = (int) $startsAt->diffInMinutes($endsAt, true);
        if ($minutes > self::MAX_SHIFT_MINUTES) {
            throw ValidationException::withMessages(['end_time' => 'The shift is longer than 16 hours.']);
        }
        if ((int) $break >= $minutes) {
            throw ValidationException::withMessages(['break_minutes' => 'The break must be shorter than the shift.']);
        }
        if ($room !== null && ! BranchRoom::query()->whereKey($room)->where('organisation_id', $branch->organisation_id)->where('branch_id', $branch->id)->where('is_active', true)->exists()) {
            throw ValidationException::withMessages(['branch_room_id' => 'Choose a room at this branch.']);
        }

        return ['starts_at' => $startsAt->utc(), 'ends_at' => $endsAt->utc(), 'break_minutes' => (int) $break, 'session_type' => (string) $session,
            'branch_room_id' => $room === null ? null : (int) $room, 'staff_user_id' => (int) $staff];
    }

    /** @return array{int, int}|null */
    private function time(mixed $value): ?array
    {
        if (! is_string($value) || preg_match('/\A([01]\d|2[0-3]):([0-5]\d)\z/', $value, $m) !== 1) {
            return null;
        }

        return [(int) $m[1], (int) $m[2]];
    }

    /** The staff member may be rostered: an active human staff account of this organisation. */
    public function assertRosterable(User $subject, Branch $branch, string $field = 'staff_user_id'): void
    {
        if ($subject->organisation_id !== $branch->organisation_id || ! $subject->is_active || $subject->hasRole('queue_display')
            || ! StaffProfile::query()->where('user_id', $subject->id)->exists()) {
            throw ValidationException::withMessages([$field => 'Choose an active staff member.']);
        }
    }

    /** Every branch-local date the shift touches needs an effective assignment of the person to the branch. */
    public function assertAssigned(User $subject, Branch $branch, CarbonImmutable $startsAt, CarbonImmutable $endsAt, string $field = 'staff_user_id'): void
    {
        $profileId = StaffProfile::query()->where('user_id', $subject->id)->value('id');
        $assignments = $profileId === null ? collect() : StaffBranchAssignment::query()->where('staff_profile_id', $profileId)->where('branch_id', $branch->id)->get();
        foreach ($this->touchedDates($branch, $startsAt, $endsAt) as $date) {
            $covered = $assignments->contains(fn (StaffBranchAssignment $a): bool => $a->valid_from->toDateString() <= $date
                && ($a->valid_until === null || $a->valid_until->toDateString() >= $date));
            if (! $covered) {
                $label = $this->dayLabel(CarbonImmutable::createFromFormat('!Y-m-d', $date, $branch->timezone) ?: $startsAt);
                throw ValidationException::withMessages([$field => "{$subject->name} is not assigned to {$branch->name} on {$label}."]);
            }
        }
    }

    /** @return list<string> Branch-local dates (Y-m-d) from the start up to the last minute before the end. */
    public function touchedDates(Branch $branch, CarbonImmutable $startsAt, CarbonImmutable $endsAt): array
    {
        $first = $startsAt->setTimezone($branch->timezone)->startOfDay();
        $last = $endsAt->subSecond()->setTimezone($branch->timezone)->startOfDay();
        $dates = [];
        for ($d = $first; $d->lessThanOrEqualTo($last); $d = $d->addDay()) {
            $dates[] = $d->toDateString();
        }

        return $dates;
    }

    /**
     * The same person's shifts that block this one: in the same version, and in the approved version of every other
     * roster week (any branch). The approved version of this version's own week is being replaced, so it does not count.
     *
     * @return Collection<int, RosterShift>
     */
    public function blockingOverlaps(RosterVersion $version, int $subjectId, CarbonImmutable $startsAt, CarbonImmutable $endsAt, ?int $exceptShiftId = null): Collection
    {
        return RosterShift::query()->with(['version.week.branch'])
            ->where('organisation_id', $version->organisation_id)->where('staff_user_id', $subjectId)
            ->where('starts_at', '<', $endsAt)->where('ends_at', '>', $startsAt)
            ->when($exceptShiftId !== null, fn ($q) => $q->whereKeyNot($exceptShiftId))
            ->where(fn ($q) => $q->where('roster_version_id', $version->id)
                ->orWhereIn('roster_version_id', RosterVersion::query()->select('id')->where('organisation_id', $version->organisation_id)
                    ->where('status', 'approved')->where('roster_week_id', '<>', $version->roster_week_id)))
            ->orderBy('starts_at')->get();
    }

    /**
     * Shifts of the same person in other weeks' open (draft or sent) versions: they may still change, so warn only.
     *
     * @return Collection<int, RosterShift>
     */
    public function draftOverlaps(RosterVersion $version, int $subjectId, CarbonImmutable $startsAt, CarbonImmutable $endsAt): Collection
    {
        return RosterShift::query()->with(['version.week.branch'])
            ->where('organisation_id', $version->organisation_id)->where('staff_user_id', $subjectId)
            ->where('starts_at', '<', $endsAt)->where('ends_at', '>', $startsAt)
            ->whereIn('roster_version_id', RosterVersion::query()->select('id')->where('organisation_id', $version->organisation_id)
                ->whereIn('status', ['draft', 'submitted'])->where('roster_week_id', '<>', $version->roster_week_id))
            ->orderBy('starts_at')->get();
    }

    public function overlapMessage(User $subject, RosterShift $other): string
    {
        $branch = $other->version->week->branch;
        $start = $other->starts_at->setTimezone($branch->timezone);
        $end = $other->ends_at->setTimezone($branch->timezone);

        return "{$subject->name} already has a shift from {$this->timeLabel($start)} to {$this->timeLabel($end)} at {$branch->name} on {$this->dayLabel($start)}.";
    }

    /** Full validation of one shift in a draft; throws on the first refusal. */
    public function assertShiftAllowed(RosterVersion $version, Branch $branch, User $subject, CarbonImmutable $startsAt, CarbonImmutable $endsAt, ?int $exceptShiftId = null, string $field = 'staff_user_id'): void
    {
        $this->assertRosterable($subject, $branch, $field);
        $this->assertAssigned($subject, $branch, $startsAt, $endsAt, $field);
        $overlap = $this->blockingOverlaps($version, $subject->id, $startsAt, $endsAt, $exceptShiftId)->first();
        if ($overlap !== null) {
            throw ValidationException::withMessages([$field => $this->overlapMessage($subject, $overlap)]);
        }
    }

    public function dayLabel(CarbonImmutable $date): string
    {
        return $date->format('j M Y');
    }

    public function shortDayLabel(CarbonImmutable $date): string
    {
        return $date->format('D j M');
    }

    public function timeLabel(CarbonImmutable $time): string
    {
        return $time->format('g:i A');
    }
}
