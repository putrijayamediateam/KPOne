<?php

namespace App\Domain\HR\Roster\Services;

use App\Domain\HR\Leave\Services\LeaveCalendar;
use App\Domain\HR\Roster\Models\RosterShift;
use App\Domain\HR\Roster\Models\RosterVersion;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Queue\Models\BranchRoom;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Room from the roster (owner, via B.E.R.N.I.S.S, 18 Nov 2026): the one thing Queue may ask HR about a doctor's day.
 * Queue never reads hr_ tables; it calls this and gets a consultation room id and when that shift started, or null.
 *
 * Rules:
 * - Only the currently approved version of a week counts; drafts, weeks waiting for approval and replaced versions never do.
 * - The doctor's shifts at this branch on the branch-local day of `$at`, skipping any shift that overlaps approved leave.
 * - The shift covering `$at` wins (the later start if two overlap); with none covering it, today's nearest shift by time.
 * - Only that shift's room is returned, and only an active consultation room of the same organisation and branch. A
 *   shift with no room, or another kind of room, returns null, so Queue keeps today's manual pick.
 *
 * Read-only: no lock, no write, no audit. `shiftStartedAt` lets Queue decide whether a hand choice made before the current
 * shift started is overridden (the second shift's room takes over when it starts).
 */
final class RosterRoomLookup
{
    public function __construct(private readonly LeaveCalendar $leave) {}

    /** @return array{roomId: int, shiftStartedAt: CarbonImmutable}|null */
    public function roomFor(int $doctorUserId, Branch $branch, CarbonInterface $at): ?array
    {
        $at = CarbonImmutable::instance($at)->utc();
        $local = $at->setTimezone($branch->timezone);
        $dayStart = $local->startOfDay()->utc();
        $dayEnd = $local->startOfDay()->addDay()->utc();

        $shifts = RosterShift::query()
            ->where('organisation_id', $branch->organisation_id)
            ->where('branch_id', $branch->id)
            ->where('staff_user_id', $doctorUserId)
            ->whereIn('roster_version_id', RosterVersion::query()->select('id')
                ->where('organisation_id', $branch->organisation_id)->where('branch_id', $branch->id)->where('status', 'approved'))
            ->where('starts_at', '<', $dayEnd)
            ->where('ends_at', '>', $dayStart)
            ->orderBy('starts_at')->orderBy('id')
            ->get(['id', 'starts_at', 'ends_at', 'branch_room_id']);

        if ($shifts->isEmpty()) {
            return null;
        }

        $leave = $this->leave->overlapping([$doctorUserId], $dayStart, $dayEnd);
        if ($leave->isNotEmpty()) {
            $slots = $leave->map(fn ($request) => $this->leave->utcSlots($request, $this->leaveZone($request->branch_id, $branch)));
            $shifts = $shifts->reject(fn (RosterShift $s): bool => $slots->contains(
                fn (array $slot): bool => $slot[0]->lessThan($s->ends_at) && $slot[1]->greaterThan($s->starts_at),
            ))->values();
        }

        $covering = $shifts->filter(fn (RosterShift $s): bool => $s->starts_at->lessThanOrEqualTo($at) && $s->ends_at->greaterThan($at))->last();
        $shift = $covering ?? $shifts->sortBy(fn (RosterShift $s): int => min(
            abs($s->starts_at->getTimestamp() - $at->getTimestamp()),
            abs($s->ends_at->getTimestamp() - $at->getTimestamp()),
        ))->first();

        if ($shift === null || $shift->branch_room_id === null) {
            return null;
        }

        $room = BranchRoom::query()->whereKey($shift->branch_room_id)
            ->where('organisation_id', $branch->organisation_id)->where('branch_id', $branch->id)
            ->where('kind', BranchRoom::KIND_CONSULTATION)->where('is_active', true)->value('id');

        return $room === null ? null : ['roomId' => (int) $room, 'shiftStartedAt' => $shift->starts_at->utc()];
    }

    private function leaveZone(int $leaveBranchId, Branch $branch): string
    {
        return $leaveBranchId === $branch->id ? $branch->timezone : (string) (Branch::query()->whereKey($leaveBranchId)->value('timezone') ?? 'UTC');
    }
}
