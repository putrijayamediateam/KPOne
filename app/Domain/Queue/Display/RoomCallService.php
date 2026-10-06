<?php

namespace App\Domain\Queue\Display;

use App\Domain\Access\BranchAccessService;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Clinical\Dispensary\Models\DispensaryCase;
use App\Domain\Clinical\Dispensary\Services\DispensaryAuthorityService;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Queue\Models\BranchRoom;
use App\Domain\Queue\Models\QueueCall;
use App\Domain\Queue\Models\QueueEntry;
use App\Domain\Visit\Models\Visit;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Calls a patient to the dispensary or a treatment room on the waiting-room TV. A call only announces: it never
 * changes the Queue entry, the consultation or the dispensary case.
 */
class RoomCallService
{
    public function __construct(
        private DispensaryAuthorityService $dispensary,
        private BranchAccessService $branches,
        private QueueCallRecorder $calls,
        private AuditRecorder $audit,
    ) {}

    /** @param array<string, mixed> $attributes */
    public function callToDispensary(User $actor, DispensaryCase $case, ?int $roomId, array $attributes): QueueCall
    {
        $branch = $this->dispensary->activeBranch($actor, $attributes);

        return DB::transaction(function () use ($actor, $case, $roomId, $branch): QueueCall {
            $lockedActor = $this->dispensary->lock($actor, $branch, 'dispensary.start.branch');
            $lockedCase = DispensaryCase::query()
                ->whereKey($case->id)
                ->where('organisation_id', $lockedActor->organisation_id)
                ->where('branch_id', $branch->id)
                ->lockForUpdate()
                ->firstOrFail();
            if (! in_array($lockedCase->status, [DispensaryCase::STATUS_PENDING, DispensaryCase::STATUS_DISPENSING], true)) {
                throw ValidationException::withMessages([
                    'queue' => 'Only a dispensary case that is waiting or being dispensed can be called. Reload the case.',
                ]);
            }
            $entry = QueueEntry::query()->where('visit_id', $lockedCase->visit_id)->lockForUpdate()->firstOrFail();

            $room = $this->dispensaryRoom($branch, $roomId);
            $call = $this->announce($entry, QueueCall::SERVICE_DISPENSARY, $room, $lockedActor, $branch);
            $this->audit->record('queue.dispensary_called', $entry, [
                'dispensary_case_public_id' => $lockedCase->public_id,
                'branch_room_id' => $room?->id,
                'is_recall' => $call->is_recall,
            ], $lockedActor, $branch, $lockedActor->organisation_id);

            return $call;
        }, 3);
    }

    /** @param array<string, mixed> $attributes */
    public function callToTreatment(User $actor, Visit $visit, int $roomId, array $attributes): QueueCall
    {
        $branch = $this->branches->activeBranch($actor);
        if (! $branch || $branch->id !== $visit->branch_id || (int) ($attributes['expected_branch_id'] ?? 0) !== $branch->id) {
            throw ValidationException::withMessages([
                'expected_branch_id' => 'The active branch changed or does not own this Visit. Review and retry.',
            ]);
        }

        return DB::transaction(function () use ($actor, $visit, $roomId, $branch, $attributes): QueueCall {
            $lockedActor = User::query()->whereKey($actor->id)->lockForUpdate()->firstOrFail();
            $lockedVisit = Visit::query()
                ->whereKey($visit->id)
                ->where('organisation_id', $lockedActor->organisation_id)
                ->where('branch_id', $branch->id)
                ->lockForUpdate()
                ->firstOrFail();
            $ownsPatient = $lockedActor->can('queue.call.own')
                && $lockedVisit->assigned_doctor_user_id === $lockedActor->id;
            if (! $lockedActor->is_active
                || (! $lockedActor->can('queue.call.branch') && ! $ownsPatient)
                || ! $this->branches->canSelect($lockedActor, $branch)) {
                throw new AuthorizationException('You may not call this Patient to a treatment room.');
            }

            $entry = QueueEntry::query()->where('visit_id', $lockedVisit->id)->lockForUpdate()->firstOrFail();
            if ($entry->lock_version !== (int) ($attributes['queue_lock_version'] ?? 0)) {
                throw ValidationException::withMessages([
                    'lock_version' => 'This Queue entry changed after it was opened. Reload and review the latest state.',
                ]);
            }
            $held = $lockedVisit->clinicalEncounter()->whereHas('activeHold')->exists();
            if ($lockedVisit->status !== Visit::STATUS_REGISTERED || $entry->status !== QueueEntry::STATUS_SERVING || $held) {
                throw ValidationException::withMessages([
                    'queue' => 'Only a patient being served now can be called to a treatment room. Reload the Queue.',
                ]);
            }

            $room = $this->room($branch, $roomId, BranchRoom::KIND_TREATMENT);
            if ($room === null) {
                throw ValidationException::withMessages([
                    'branch_room_id' => 'Choose an active treatment room at this branch.',
                ]);
            }
            $call = $this->announce($entry, QueueCall::SERVICE_TREATMENT, $room, $lockedActor, $branch);
            $this->audit->record('queue.treatment_called', $entry, [
                'branch_room_id' => $room->id,
                'is_recall' => $call->is_recall,
            ], $lockedActor, $branch, $lockedActor->organisation_id);

            return $call;
        }, 3);
    }

    /** @return list<array{id: int, name: string}> */
    public function rooms(Branch $branch, string $kind): array
    {
        return array_values(BranchRoom::query()
            ->where('branch_id', $branch->id)
            ->where('kind', $kind)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (BranchRoom $room): array => ['id' => $room->id, 'name' => $room->name])
            ->all());
    }

    private function announce(QueueEntry $entry, string $service, ?BranchRoom $room, User $actor, Branch $branch): QueueCall
    {
        $this->calls->assertCooldown($entry, $service);

        return $this->calls->record(
            $entry,
            $service,
            $room,
            $actor,
            now()->setTimezone($branch->timezone)->toDateString(),
            now()->utc(),
            $this->calls->hasBeenCalled($entry, $service),
        );
    }

    /** No choice with a single dispensary room; a choice is needed with several; none set up calls without a room. */
    private function dispensaryRoom(Branch $branch, ?int $roomId): ?BranchRoom
    {
        if ($roomId !== null) {
            $room = $this->room($branch, $roomId, BranchRoom::KIND_DISPENSARY);
            if ($room === null) {
                throw ValidationException::withMessages([
                    'branch_room_id' => 'Choose an active dispensary room at this branch.',
                ]);
            }

            return $room;
        }

        $rooms = BranchRoom::query()
            ->where('branch_id', $branch->id)
            ->where('kind', BranchRoom::KIND_DISPENSARY)
            ->where('is_active', true)
            ->sharedLock()
            ->get();
        if ($rooms->count() > 1) {
            throw ValidationException::withMessages([
                'branch_room_id' => 'Choose which dispensary room to call the patient to.',
            ]);
        }

        return $rooms->first();
    }

    private function room(Branch $branch, int $roomId, string $kind): ?BranchRoom
    {
        return BranchRoom::query()
            ->whereKey($roomId)
            ->where('organisation_id', $branch->organisation_id)
            ->where('branch_id', $branch->id)
            ->where('kind', $kind)
            ->where('is_active', true)
            ->sharedLock()
            ->first();
    }
}
