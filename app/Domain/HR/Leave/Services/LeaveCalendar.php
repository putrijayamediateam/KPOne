<?php

namespace App\Domain\HR\Leave\Services;

use App\Domain\HR\Leave\Models\LeaveRequest;
use App\Domain\Identity\Models\StaffBranchAssignment;
use App\Domain\Identity\Models\StaffProfile;
use App\Domain\Organisation\Models\Branch;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * HR-2a day counting. A request covers whole branch-local dates of the applicant's primary branch; charged half days
 * are that branch's working days minus its public holidays, with an afternoon start or a midday end allowed where the
 * type allows half days. Slots are local half-day instants so overlap checks are exact.
 */
final class LeaveCalendar
{
    /** The person's primary branch on a date (effective primary assignment), or null. */
    public function primaryBranch(User $person, CarbonImmutable $date): ?Branch
    {
        $profile = StaffProfile::query()->where('user_id', $person->id)->value('id');
        if ($profile === null) {
            return null;
        }
        $day = $date->toDateString();
        $assignment = StaffBranchAssignment::query()->where('staff_profile_id', $profile)->where('is_primary', true)
            ->whereDate('valid_from', '<=', $day)->where(fn ($q) => $q->whereNull('valid_until')->orWhereDate('valid_until', '>=', $day))
            ->orderByDesc('valid_from')->first();

        return $assignment === null ? null : Branch::query()->whereKey($assignment->branch_id)->where('organisation_id', $person->organisation_id)->first();
    }

    /** @return list<int>|null ISO weekdays that count as working days, or null when not set up. */
    public function workingDays(Branch $branch): ?array
    {
        $json = DB::table('hr_branch_working_days')->where('branch_id', $branch->id)->value('weekdays');
        if (! is_string($json)) {
            return null;
        }
        $days = json_decode($json, true);

        return is_array($days) ? array_values(array_map('intval', $days)) : null;
    }

    /** @return array<string, string> date => holiday name */
    public function holidays(Branch $branch, CarbonImmutable $from, CarbonImmutable $to): array
    {
        return DB::table('hr_public_holidays')->where('branch_id', $branch->id)
            ->whereBetween('holiday_date', [$from->toDateString(), $to->toDateString()])->orderBy('holiday_date')
            ->pluck('name', 'holiday_date')->mapWithKeys(fn ($name, $date) => [substr((string) $date, 0, 10) => (string) $name])->all();
    }

    /**
     * @return array{halves:int, slot_start:CarbonImmutable, slot_end:CarbonImmutable, skipped:list<string>}
     */
    public function measure(Branch $branch, CarbonImmutable $start, CarbonImmutable $end, string $startPart, string $endPart): array
    {
        if (DB::transactionLevel() > 0) {
            $this->lockBranch($branch, shared: true);
        }
        $working = $this->workingDays($branch);
        if ($working === null) {
            throw ValidationException::withMessages(['start_date' => "Working days are not set up for {$branch->name} yet. Ask HR."]);
        }
        $holidays = $this->holidays($branch, $start, $end);
        $single = $start->equalTo($end);
        $halves = 0;
        $skipped = [];
        for ($d = $start; $d->lessThanOrEqualTo($end); $d = $d->addDay()) {
            $date = $d->toDateString();
            if (isset($holidays[$date])) {
                $skipped[] = "public holiday on {$d->format('j M')}";

                continue;
            }
            if (! in_array($d->dayOfWeekIso, $working, true)) {
                continue;
            }
            $value = 2;
            if ($single && $startPart !== 'full') {
                $value = 1;
            } elseif (! $single && $d->equalTo($start) && $startPart === 'pm') {
                $value = 1;
            } elseif (! $single && $d->equalTo($end) && $endPart === 'am') {
                $value = 1;
            }
            $halves += $value;
        }
        $slotStart = $start->startOfDay()->addHours($startPart === 'pm' ? 12 : 0);
        $slotEnd = $single
            ? $start->startOfDay()->addHours($startPart === 'am' ? 12 : 24)
            : $end->startOfDay()->addHours($endPart === 'am' ? 12 : 24);

        return ['halves' => $halves, 'slot_start' => $slotStart, 'slot_end' => $slotEnd, 'skipped' => $skipped];
    }

    /**
     * Serialises day counting (shared) against changes to a branch's working days or public holidays (exclusive), so a
     * request is never measured against a calendar that is changing in the same instant.
     */
    public function lockBranch(Branch $branch, bool $shared = false): void
    {
        DB::select($shared ? 'select pg_advisory_xact_lock_shared(hashtextextended(?, 0))' : 'select pg_advisory_xact_lock(hashtextextended(?, 0))', ['hr_leave_calendar:'.$branch->id]);
    }

    /**
     * Approved (and, when asked, pending) leave of people overlapping a UTC interval, converted from each request's
     * branch-local slots. Used by the roster planner and its shift rules.
     *
     * @param  list<int>  $userIds
     * @param  list<string>  $statuses
     * @return Collection<int, LeaveRequest>
     */
    public function overlapping(array $userIds, CarbonImmutable $fromUtc, CarbonImmutable $toUtc, array $statuses = ['approved']): Collection
    {
        if ($userIds === []) {
            return collect();
        }
        // Slots are branch-local wall-clock times; a 14-hour margin covers every time zone before the exact check.
        $candidates = LeaveRequest::query()->whereIn('user_id', $userIds)->whereIn('status', $statuses)
            ->where('slot_start', '<', $toUtc->addHours(14)->format('Y-m-d H:i:s'))->where('slot_end', '>', $fromUtc->subHours(14)->format('Y-m-d H:i:s'))
            ->get();
        $zones = Branch::query()->whereIn('id', $candidates->pluck('branch_id')->unique())->pluck('timezone', 'id');

        return $candidates->filter(function (LeaveRequest $r) use ($zones, $fromUtc, $toUtc): bool {
            [$start, $end] = $this->utcSlots($r, (string) ($zones[$r->branch_id] ?? 'UTC'));

            return $start->lessThan($toUtc) && $end->greaterThan($fromUtc);
        })->values();
    }

    /** @return array{CarbonImmutable, CarbonImmutable} */
    public function utcSlots(LeaveRequest $request, string $timezone): array
    {
        $start = CarbonImmutable::createFromFormat('Y-m-d H:i:s', $request->slot_start->format('Y-m-d H:i:s'), $timezone) ?: throw new \LogicException('Bad slot.');
        $end = CarbonImmutable::createFromFormat('Y-m-d H:i:s', $request->slot_end->format('Y-m-d H:i:s'), $timezone) ?: throw new \LogicException('Bad slot.');

        return [$start->utc(), $end->utc()];
    }
}
