<?php

namespace App\Domain\Queue\Display;

use App\Domain\Access\BranchAccessService;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Queue\Models\BranchRoom;
use App\Domain\Queue\Models\DoctorRoomAssignment;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A doctor's consultation room for one operational day at one branch, chosen by the doctor per shift.
 * The TV shows this room when the doctor's patient is called in.
 */
class DoctorRoomService
{
    public const PERMISSION = 'queue.room.select.own';

    public function __construct(
        private BranchAccessService $branches,
        private AuditRecorder $audit,
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

    /** @return array{rooms: list<array{id: int, name: string}>, currentRoomId: int|null, operationalDate: string}|null */
    public function choice(User $actor): ?array
    {
        $branch = $this->branches->activeBranch($actor);
        if (! $branch || ! $actor->can(self::PERMISSION)) {
            return null;
        }

        $date = $this->operationalDate($branch);
        $current = $this->roomFor($actor->id, $branch, $date);

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
            'currentRoomId' => $current?->id,
            'operationalDate' => $date,
        ];
    }

    public function roomFor(int $doctorUserId, Branch $branch, string $operationalDate): ?BranchRoom
    {
        $assignment = DoctorRoomAssignment::query()
            ->with('room')
            ->where('branch_id', $branch->id)
            ->where('doctor_user_id', $doctorUserId)
            ->whereDate('operational_date', $operationalDate)
            ->first();
        $room = $assignment?->room;

        return $room && $room->is_active && $room->kind === BranchRoom::KIND_CONSULTATION ? $room : null;
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
            if ($assignment?->branch_room_id === $room->id) {
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
