<?php

namespace App\Domain\Queue\Display;

use App\Domain\Access\BranchAccessService;
use App\Domain\Audit\AuditRecorder;
use App\Domain\HR\Roster\Services\RosterRoomLookup;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Queue\Models\BranchRoom;
use App\Domain\Queue\Models\DoctorRoomAssignment;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * A doctor's consultation room for one operational day at one branch. The TV shows this room when the doctor's patient
 * is called in.
 *
 * Which room counts (owner decision 2026-11-18, via B.E.R.N.I.S.S): (1) a room the doctor chose by hand at or after the start
 * of the shift they are on now; (2) the room on that shift of the approved roster (HR's RosterRoomLookup: the only thing
 * Queue asks HR); (3) an older hand choice; (4) none. So a doctor with a morning and an afternoon room gets the afternoon
 * room when that shift starts, and picking a room by hand always overrides the roster (the choice is recorded). With no
 * approved shift or no room on it, the hand choice stands exactly as before.
 */
class DoctorRoomService
{
    public const PERMISSION = 'queue.room.select.own';

    public function __construct(
        private BranchAccessService $branches,
        private AuditRecorder $audit,
        private RosterRoomLookup $roster,
    ) {}

    public function select(User $actor, ?int $roomId, int $expectedBranchId): ?BranchRoom
    {
        $branch = $this->branches->activeBranch($actor);
        if (! $branch || $branch->id !== $expectedBranchId) {
            throw ValidationException::withMessages([
                'expected_branch_id' => 'The active branch changed. Review and retry.',
            ]);
        }

        try {
            return $this->write($actor, $branch, $roomId);
        } catch (UniqueConstraintViolationException) {
            return $this->write($actor, $branch, $roomId);
        }
    }

    /** @return array{rooms: list<array{id: int, name: string}>, currentRoomId: int|null, currentRoomSource: 'chosen'|'roster'|null, operationalDate: string}|null */
    public function choice(User $actor): ?array
    {
        $branch = $this->branches->activeBranch($actor);
        if (! $branch || ! $actor->can(self::PERMISSION)) {
            return null;
        }

        $date = $this->operationalDate($branch);
        $current = $this->resolve($actor->id, $branch, $date);

        return [
            'rooms' => array_values(BranchRoom::query()
                ->where('branch_id', $branch->id)
                ->where('kind', BranchRoom::KIND_CONSULTATION)
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (BranchRoom $room): array => ['id' => $room->id, 'name' => $room->name])
                ->all()),
            'currentRoomId' => $current['room']?->id,
            'currentRoomSource' => $current['source'],
            'operationalDate' => $date,
        ];
    }

    public function roomFor(int $doctorUserId, Branch $branch, string $operationalDate): ?BranchRoom
    {
        return $this->resolve($doctorUserId, $branch, $operationalDate)['room'];
    }

    /**
     * The room and where it came from: "chosen" (the doctor's own pick), "roster" (today's approved shift) or null.
     *
     * @return array{room: BranchRoom|null, source: 'chosen'|'roster'|null}
     */
    public function resolve(int $doctorUserId, Branch $branch, string $operationalDate): array
    {
        $assignment = DoctorRoomAssignment::query()
            ->with('room')
            ->where('branch_id', $branch->id)
            ->where('doctor_user_id', $doctorUserId)
            ->whereDate('operational_date', $operationalDate)
            ->first();
        $chosen = $assignment?->room;
        $chosen = $chosen && $chosen->is_active && $chosen->kind === BranchRoom::KIND_CONSULTATION ? $chosen : null;

        // The roster is only asked about the branch-local day it is today: a past or future date has no "current shift".
        $shift = $operationalDate === $this->operationalDate($branch) && $this->rosterExists()
            ? $this->roster->roomFor($doctorUserId, $branch, now()->toImmutable()->utc())
            : null;
        $rostered = $shift === null ? null : BranchRoom::query()->whereKey($shift['roomId'])
            ->where('branch_id', $branch->id)->where('kind', BranchRoom::KIND_CONSULTATION)->where('is_active', true)->first();

        if ($rostered === null) {
            return ['room' => $chosen, 'source' => $chosen ? 'chosen' : null];
        }
        if ($chosen !== null && $assignment->updated_at->greaterThanOrEqualTo($shift['shiftStartedAt'])) {
            return ['room' => $chosen, 'source' => 'chosen'];
        }

        return ['room' => $rostered, 'source' => 'roster'];
    }

    /**
     * HR's roster tables come with HR's own migrations. Every deployment migrates before it serves, so this is only ever false
     * in a test that rolls the schema back to a point before them (the dispensary regression does): there a Call In must keep
     * working on the hand-chosen room, as it did before the roster, instead of failing on a table that does not exist yet.
     * The check is a catalogue read, so it is safe inside the Call In transaction.
     */
    private function rosterExists(): bool
    {
        return Schema::hasTable('hr_roster_shifts');
    }

    public function branchHasConsultationRooms(Branch $branch): bool
    {
        return BranchRoom::query()
            ->where('branch_id', $branch->id)
            ->where('kind', BranchRoom::KIND_CONSULTATION)
            ->where('is_active', true)
            ->exists();
    }

    public function operationalDate(Branch $branch): string
    {
        return now()->setTimezone($branch->timezone)->toDateString();
    }

    private function write(User $actor, Branch $branch, ?int $roomId): ?BranchRoom
    {
        return DB::transaction(function () use ($actor, $branch, $roomId): ?BranchRoom {
            $lockedActor = User::query()->whereKey($actor->id)->lockForUpdate()->firstOrFail();
            if (! $lockedActor->is_active || ! $lockedActor->can(self::PERMISSION)
                || $lockedActor->organisation_id !== $branch->organisation_id
                || ! $this->branches->hasEffectiveAssignment($lockedActor, $branch)) {
                throw new AuthorizationException('You may not choose a consultation room at this branch.');
            }

            $date = $this->operationalDate($branch);
            $assignment = DoctorRoomAssignment::query()
                ->where('branch_id', $branch->id)
                ->where('doctor_user_id', $lockedActor->id)
                ->whereDate('operational_date', $date)
                ->lockForUpdate()
                ->first();

            if ($roomId === null) {
                if ($assignment) {
                    $previousRoomId = $assignment->branch_room_id;
                    $assignment->delete();
                    $this->audit->record('queue.room.cleared', $lockedActor, [
                        'branch_room_id' => $previousRoomId,
                        'operational_date' => $date,
                    ], $lockedActor, $branch, $branch->organisation_id);
                }

                return null;
            }

            $room = BranchRoom::query()
                ->whereKey($roomId)
                ->where('organisation_id', $branch->organisation_id)
                ->where('branch_id', $branch->id)
                ->where('kind', BranchRoom::KIND_CONSULTATION)
                ->where('is_active', true)
                ->sharedLock()
                ->first();
            if (! $room) {
                throw ValidationException::withMessages([
                    'branch_room_id' => 'Choose an active consultation room at this branch.',
                ]);
            }
            // Choosing the room already chosen is a no-op, unless today's roster shift is the one in force: then it is a fresh
            // hand choice (recorded, and newer than the shift start) that overrides the roster again.
            if ($assignment?->branch_room_id === $room->id
                && $this->resolve($lockedActor->id, $branch, $date)['room']?->id === $room->id) {
                return $room;
            }

            $assignment ??= (new DoctorRoomAssignment)->forceFill([
                'organisation_id' => $branch->organisation_id,
                'branch_id' => $branch->id,
                'doctor_user_id' => $lockedActor->id,
                'operational_date' => $date,
                'lock_version' => 0,
            ]);
            $assignment->forceFill([
                'branch_room_id' => $room->id,
                'lock_version' => $assignment->lock_version + 1,
                'updated_at' => now(),
            ])->save();
            $this->audit->record('queue.room.selected', $lockedActor, [
                'branch_room_id' => $room->id,
                'room_name' => $room->name,
                'operational_date' => $date,
            ], $lockedActor, $branch, $branch->organisation_id);

            return $room;
        }, 3);
    }
}
