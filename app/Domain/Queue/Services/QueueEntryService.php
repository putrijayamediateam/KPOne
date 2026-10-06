<?php

namespace App\Domain\Queue\Services;

use App\Domain\Access\BranchAccessService;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Identity\Models\StaffBranchAssignment;
use App\Domain\Identity\Models\StaffProfile;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Queue\Display\DoctorRoomService;
use App\Domain\Queue\Display\QueueCallRecorder;
use App\Domain\Queue\Models\BranchRoom;
use App\Domain\Queue\Models\QueueCall;
use App\Domain\Queue\Models\QueueEntry;
use App\Domain\Visit\Models\Visit;
use App\Domain\Visit\Services\VisitDoctorEligibilityService;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class QueueEntryService
{
    public function __construct(
        private BranchAccessService $branches,
        private VisitDoctorEligibilityService $doctors,
        private QueueNumberGenerator $numbers,
        private AuditRecorder $audit,
        private DoctorRoomService $rooms,
        private QueueCallRecorder $calls,
    ) {}

    /** @param array<string, mixed> $attributes */
    public function enter(User $actor, Visit $visit, array $attributes): QueueEntry
    {
        Gate::forUser($actor)->authorize('create', QueueEntry::class);
        $branch = $this->activeBranch($actor, $visit, $attributes);
        $validated = Validator::make($attributes, [
            'expected_branch_id' => ['required', 'integer'],
            'visit_lock_version' => ['required', 'integer', 'min:1'],
        ])->validate();

        try {
            return DB::transaction(function () use ($actor, $visit, $branch, $validated): QueueEntry {
                $lockedActor = $this->lockActor($actor, $branch, ['queue.enter.branch']);
                $lockedVisit = Visit::query()
                    ->whereKey($visit->id)
                    ->where('organisation_id', $lockedActor->organisation_id)
                    ->where('branch_id', $branch->id)
                    ->lockForUpdate()
                    ->firstOrFail();
                $existing = QueueEntry::query()
                    ->where('visit_id', $lockedVisit->id)
                    ->lockForUpdate()
                    ->first();

                if ($existing) {
                    return $this->existingEntry($existing);
                }

                if ($lockedVisit->status !== Visit::STATUS_REGISTERED) {
                    throw ValidationException::withMessages([
                        'visit' => 'Only a registered Visit can be sent to Waiting.',
                    ]);
                }
                if ($lockedVisit->visit_type !== 'consultation') {
                    throw ValidationException::withMessages([
                        'visit' => 'OTC Visits do not enter the Consultation Queue.',
                    ]);
                }
                if ($lockedVisit->lock_version !== (int) $validated['visit_lock_version']) {
                    $this->stale();
                }
                if ($lockedVisit->assigned_doctor_user_id === null) {
                    throw ValidationException::withMessages([
                        'assigned_doctor_user_id' => 'Please assign an eligible doctor before sending this Visit to Waiting.',
                    ]);
                }

                $effectiveDate = now()->setTimezone($branch->timezone)->toDateString();
                $this->doctors->lockAndValidate(
                    $lockedVisit->assigned_doctor_user_id,
                    $branch,
                    $effectiveDate,
                );

                $now = now()->utc();
                $entry = new QueueEntry;
                $entry->forceFill([
                    'organisation_id' => $lockedVisit->organisation_id,
                    'branch_id' => $lockedVisit->branch_id,
                    'visit_id' => $lockedVisit->id,
                    'operational_date' => $effectiveDate,
                    'queue_number' => $this->numbers->next($branch, $effectiveDate),
                    'status' => QueueEntry::STATUS_WAITING,
                    'queued_at' => $now,
                    'queued_by_user_id' => $lockedActor->id,
                    'called_at' => null,
                    'called_by_user_id' => null,
                    'removed_at' => null,
                    'updated_by_user_id' => $lockedActor->id,
                    'lock_version' => 1,
                ])->save();

                $this->audit->record('queue.entered', $entry, [
                    'to_state' => QueueEntry::STATUS_WAITING,
                    'record_version' => 1,
                ], $lockedActor, $branch, $lockedActor->organisation_id);

                return $entry->refresh();
            }, 3);
        } catch (QueryException $exception) {
            $existing = QueueEntry::query()
                ->where('organisation_id', $actor->organisation_id)
                ->where('branch_id', $branch->id)
                ->where('visit_id', $visit->id)
                ->first();
            if ($existing) {
                return $this->existingEntry($existing);
            }

            throw $exception;
        }
    }

    /** @param array<string, mixed> $attributes */
    public function call(User $actor, Visit $visit, array $attributes): QueueEntry
    {
        $entry = QueueEntry::query()
            ->with('visit')
            ->where('organisation_id', $actor->organisation_id)
            ->where('branch_id', $visit->branch_id)
            ->where('visit_id', $visit->id)
            ->firstOrFail();
        Gate::forUser($actor)->authorize('call', $entry);
        $branch = $this->activeBranch($actor, $visit, $attributes);
        $validated = Validator::make($attributes, [
            'expected_branch_id' => ['required', 'integer'],
            'visit_lock_version' => ['required', 'integer', 'min:1'],
            'queue_lock_version' => ['required', 'integer', 'min:1'],
        ])->validate();

        return DB::transaction(function () use ($actor, $visit, $branch, $validated): QueueEntry {
            $lockedActor = $this->lockActor($actor, $branch, [
                'queue.call.branch',
                'queue.call.own',
            ]);
            $assignedDoctorId = $visit->assigned_doctor_user_id;
            $lockedDoctor = $assignedDoctorId === null ? null : User::query()
                ->whereKey($assignedDoctorId)
                ->where('organisation_id', $lockedActor->organisation_id)
                ->lockForUpdate()
                ->first();
            $lockedVisit = Visit::query()
                ->whereKey($visit->id)
                ->where('organisation_id', $lockedActor->organisation_id)
                ->where('branch_id', $branch->id)
                ->lockForUpdate()
                ->firstOrFail();
            $lockedEntry = QueueEntry::query()
                ->where('visit_id', $lockedVisit->id)
                ->lockForUpdate()
                ->firstOrFail();

            $ownsQueue = $lockedActor->can('queue.call.own')
                && $lockedVisit->assigned_doctor_user_id === $lockedActor->id;
            if (! $lockedActor->can('queue.call.branch') && ! $ownsQueue) {
                throw new AuthorizationException('You may not Call In this Patient.');
            }
            if ($lockedVisit->lock_version !== (int) $validated['visit_lock_version']
                || $lockedEntry->lock_version !== (int) $validated['queue_lock_version']) {
                $this->stale();
            }
            if ($lockedVisit->status !== Visit::STATUS_REGISTERED
                || $lockedEntry->status !== QueueEntry::STATUS_WAITING) {
                throw ValidationException::withMessages([
                    'queue' => 'This Queue entry is no longer Waiting. Reload the Queue.',
                ]);
            }
            if ($lockedVisit->assigned_doctor_user_id === null) {
                throw ValidationException::withMessages([
                    'doctor' => 'Assign an eligible doctor before calling this Patient.',
                ]);
            }
            if (! $lockedDoctor || $lockedDoctor->id !== $lockedVisit->assigned_doctor_user_id) {
                throw ValidationException::withMessages([
                    'doctor' => 'The assigned doctor changed. Reload before calling this Patient.',
                ]);
            }

            $effectiveDate = now()->setTimezone($branch->timezone)->toDateString();
            $this->doctors->lockAndValidate(
                $lockedVisit->assigned_doctor_user_id,
                $branch,
                $effectiveDate,
            );

            $hasActiveConsultation = QueueEntry::query()
                ->where('organisation_id', $lockedActor->organisation_id)
                ->where('branch_id', $branch->id)
                ->where('status', QueueEntry::STATUS_SERVING)
                ->whereHas('visit', fn ($query) => $query
                    ->where('assigned_doctor_user_id', $lockedDoctor->id))
                ->whereDoesntHave('visit.clinicalEncounter.activeHold')
                ->exists();
            if ($hasActiveConsultation) {
                throw ValidationException::withMessages([
                    'queue' => 'This doctor is already serving another patient. Place that consultation On Hold or complete it first.',
                ]);
            }

            $room = $this->requiredRoom($lockedDoctor, $branch, $effectiveDate);
            $calledAt = now()->utc();
            $lockedEntry->forceFill([
                'status' => QueueEntry::STATUS_SERVING,
                'called_at' => $calledAt,
                'called_by_user_id' => $lockedActor->id,
                'updated_by_user_id' => $lockedActor->id,
                'lock_version' => $lockedEntry->lock_version + 1,
            ])->save();
            $this->calls->record($lockedEntry, QueueCall::SERVICE_CONSULTATION, $room, $lockedActor, $effectiveDate, $calledAt, false);
            $this->audit->record('queue.called', $lockedEntry, [
                'from_state' => QueueEntry::STATUS_WAITING,
                'to_state' => QueueEntry::STATUS_SERVING,
                'record_version' => $lockedEntry->lock_version,
                'branch_room_id' => $room?->id,
            ], $lockedActor, $branch, $lockedActor->organisation_id);

            return $lockedEntry->refresh();
        }, 3);
    }

    /**
     * Call the patient being served again, for the waiting-room TV, without changing the Queue entry.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function recall(User $actor, Visit $visit, array $attributes): QueueCall
    {
        $entry = QueueEntry::query()
            ->where('organisation_id', $actor->organisation_id)
            ->where('branch_id', $visit->branch_id)
            ->where('visit_id', $visit->id)
            ->firstOrFail();
        Gate::forUser($actor)->authorize('call', $entry);
        $branch = $this->activeBranch($actor, $visit, $attributes);
        $validated = Validator::make($attributes, [
            'expected_branch_id' => ['required', 'integer'],
            'queue_lock_version' => ['required', 'integer', 'min:1'],
        ])->validate();

        return DB::transaction(function () use ($actor, $visit, $branch, $validated): QueueCall {
            $lockedActor = $this->lockActor($actor, $branch, ['queue.call.branch', 'queue.call.own']);
            $lockedVisit = Visit::query()
                ->whereKey($visit->id)
                ->where('organisation_id', $lockedActor->organisation_id)
                ->where('branch_id', $branch->id)
                ->lockForUpdate()
                ->firstOrFail();
            $lockedEntry = QueueEntry::query()
                ->where('visit_id', $lockedVisit->id)
                ->lockForUpdate()
                ->firstOrFail();

            $ownsQueue = $lockedActor->can('queue.call.own')
                && $lockedVisit->assigned_doctor_user_id === $lockedActor->id;
            if (! $lockedActor->can('queue.call.branch') && ! $ownsQueue) {
                throw new AuthorizationException('You may not call this Patient again.');
            }
            if ($lockedEntry->lock_version !== (int) $validated['queue_lock_version']) {
                $this->stale();
            }
            $held = $lockedVisit->clinicalEncounter()->whereHas('activeHold')->exists();
            if ($lockedEntry->status !== QueueEntry::STATUS_SERVING || $held
                || $lockedVisit->assigned_doctor_user_id === null) {
                throw ValidationException::withMessages([
                    'queue' => 'Only a patient being served now can be called again. Reload the Queue.',
                ]);
            }

            $this->calls->assertCooldown($lockedEntry, QueueCall::SERVICE_CONSULTATION);

            $doctor = User::query()->whereKey($lockedVisit->assigned_doctor_user_id)->firstOrFail();
            $effectiveDate = now()->setTimezone($branch->timezone)->toDateString();
            $room = $this->requiredRoom($doctor, $branch, $effectiveDate);
            $call = $this->calls->record($lockedEntry, QueueCall::SERVICE_CONSULTATION, $room, $lockedActor, $effectiveDate, now()->utc(), true);
            $this->audit->record('queue.recalled', $lockedEntry, [
                'record_version' => $lockedEntry->lock_version,
                'branch_room_id' => $room?->id,
            ], $lockedActor, $branch, $lockedActor->organisation_id);

            return $call;
        }, 3);
    }

    /**
     * A branch that has set up consultation rooms needs the doctor's room for today before a call goes to the TV.
     * A branch without rooms still calls, and the TV sends the patient to the counter.
     */
    private function requiredRoom(User $doctor, Branch $branch, string $operationalDate): ?BranchRoom
    {
        $room = $this->rooms->roomFor($doctor->id, $branch, $operationalDate);
        if ($room === null && $this->rooms->branchHasConsultationRooms($branch)) {
            throw ValidationException::withMessages([
                'doctor' => 'The doctor has not chosen a consultation room for today. Choose "My room today" in the Consultation queue, then call again.',
            ]);
        }

        return $room;
    }

    /** @param array<string, mixed> $attributes */
    private function activeBranch(User $actor, Visit $visit, array $attributes): Branch
    {
        $branch = $this->branches->activeBranch($actor);
        if (! $branch || $branch->id !== $visit->branch_id
            || (int) ($attributes['expected_branch_id'] ?? 0) !== $branch->id) {
            throw ValidationException::withMessages([
                'expected_branch_id' => 'The active branch changed or does not own this Visit. Review and retry.',
            ]);
        }

        return $branch;
    }

    /** @param list<string> $permissions */
    private function lockActor(User $actor, Branch $branch, array $permissions): User
    {
        $locked = User::query()->whereKey($actor->id)->lockForUpdate()->firstOrFail();
        $locked->load('roles.permissions');
        $profile = StaffProfile::query()->where('user_id', $locked->id)->lockForUpdate()->first();
        if ($profile) {
            StaffBranchAssignment::query()
                ->where('staff_profile_id', $profile->id)
                ->lockForUpdate()
                ->get();
            $locked->unsetRelation('staffProfile');
        }
        $permitted = collect($permissions)->contains(fn (string $permission) => $locked->can($permission));
        if (! $locked->is_active || ! $permitted || ! $this->branches->canSelect($locked, $branch)) {
            throw new AuthorizationException('You may not change this Queue.');
        }

        return $locked;
    }

    private function existingEntry(QueueEntry $entry): QueueEntry
    {
        if ($entry->status === QueueEntry::STATUS_REMOVED) {
            throw ValidationException::withMessages([
                'queue' => 'A removed Queue entry cannot be reopened. Register a new Visit if needed.',
            ]);
        }

        return $entry;
    }

    private function stale(): never
    {
        throw ValidationException::withMessages([
            'lock_version' => 'This Visit or Queue entry changed after it was opened. Reload and review the latest state.',
        ]);
    }
}
