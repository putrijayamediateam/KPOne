<?php

namespace App\Domain\Queue\Services;

use App\Domain\Access\BranchAccessService;
use App\Domain\Clinical\Services\ConsultationLocks;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Queue\Display\DoctorRoomService;
use App\Domain\Queue\Models\QueueEntry;
use App\Domain\Queue\QueueNumberFormat;
use App\Domain\Visit\Models\Visit;
use App\Domain\Visit\Services\VisitDoctorEligibilityService;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Q-4 Call next (owner decisions 2026-11-05). One button that calls one doctor's next eligible patient: today's
 * Waiting entries first (Urgent first, then by arrival), and the previous-day carry-over only when today's list is
 * empty. Entries with an active not-ready flag are skipped; there is no override here. The call itself is the
 * ordinary Call In (same permission, branch, lock-version, room, busy and audit rules). The person always confirms
 * a named patient first, and the server refuses when that patient is no longer the next one.
 */
class CallNextService
{
    public const STATE_READY = 'ready';

    public const STATE_NO_DOCTOR = 'no_doctor';

    public const STATE_DOCTOR_UNAVAILABLE = 'doctor_unavailable';

    public const STATE_BUSY = 'busy';

    public const STATE_NO_ROOM = 'no_room';

    public const STATE_NONE_WAITING = 'none_waiting';

    public const STATE_ALL_NOT_READY = 'all_not_ready';

    public function __construct(
        private BranchAccessService $branches,
        private VisitDoctorEligibilityService $doctors,
        private DoctorRoomService $rooms,
        private QueueEntryService $queue,
    ) {}

    /**
     * The Call next block for the queue board. Null when the actor may not call from this board. Only the applied
     * doctor filter matters (a branch caller must choose a doctor); search, urgency and tabs never change who is next.
     *
     * @param  list<int>|null  $eligibleDoctorIds  today's eligible doctors when the caller already has them
     * @return array<string, mixed>|null
     */
    public function block(User $actor, Branch $branch, ?int $doctorId, ?array $eligibleDoctorIds = null): ?array
    {
        $mode = $this->mode($actor);
        if ($mode === null) {
            return null;
        }
        $targetId = $mode === 'own' ? $actor->id : $doctorId;
        $base = ['mode' => $mode, 'doctorUserId' => $targetId, 'doctorName' => null, 'candidate' => null];
        if ($targetId === null) {
            return [...$base, 'state' => self::STATE_NO_DOCTOR, 'reason' => 'Choose a doctor and press Apply to call their next patient.'];
        }
        $doctor = User::query()->whereKey($targetId)->where('organisation_id', $actor->organisation_id)->first(['id', 'organisation_id', 'name']);
        $today = now()->setTimezone($branch->timezone)->toDateString();
        if (! $doctor || ! in_array($doctor->id, $eligibleDoctorIds ?? $this->doctors->eligibleDoctorIds($branch, $today), true)) {
            return [...$base, 'state' => self::STATE_DOCTOR_UNAVAILABLE, 'reason' => 'This doctor cannot take consultations at this branch today.'];
        }
        $base['doctorName'] = $doctor->name;
        $own = $mode === 'own';
        if (ConsultationLocks::doctorBusy($doctor)) {
            // Never name the other patient: the busy consultation may be at another branch.
            return [...$base, 'state' => self::STATE_BUSY, 'reason' => $own
                ? 'You are already seeing a patient. Hold or finish that consultation first.'
                : 'This doctor is already seeing a patient. They must hold or finish that consultation first.'];
        }
        $room = $this->rooms->roomFor($doctor->id, $branch, $today);
        if ($room === null && $this->rooms->branchHasConsultationRooms($branch)) {
            return [...$base, 'state' => self::STATE_NO_ROOM, 'reason' => $own
                ? 'Choose "My room today" before calling.'
                : 'This doctor has not chosen a room for today.'];
        }
        $entry = $this->next($actor, $branch, $doctor->id, $today);
        if ($entry === null) {
            return [...$base, ...$this->emptyState($actor, $branch, $doctor->id, $today)];
        }

        return [...$base, 'state' => self::STATE_READY, 'reason' => null, 'candidate' => $this->candidate($entry, $today, $room?->name)];
    }

    /**
     * Q-4b (owner decisions 2026-11-06): the doctor's own Call next block outside the queue board, for the held
     * consultation page. Only the doctor's own queue; null for anyone who calls from the branch board instead.
     *
     * @return array<string, mixed>|null
     */
    public function ownBlock(User $actor, int $expectedBranchId): ?array
    {
        $branch = $this->branches->activeBranch($actor);
        if (! $branch || $branch->id !== $expectedBranchId) {
            throw ValidationException::withMessages([
                'expected_branch_id' => 'The active branch changed. Reload the page.',
            ]);
        }

        return $this->mode($actor) === 'own' ? $this->block($actor, $branch, null) : null;
    }

    /**
     * Call the confirmed patient if they are still the doctor's next eligible patient.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function call(User $actor, array $attributes): QueueEntry
    {
        $validated = Validator::make($attributes, [
            'expected_branch_id' => ['required', 'integer'],
            'doctor_user_id' => ['required', 'integer', 'min:1'],
            'visit_number' => ['required', 'string', 'max:40'],
            'visit_lock_version' => ['required', 'integer', 'min:1'],
            'queue_lock_version' => ['required', 'integer', 'min:1'],
        ])->validate();
        $branch = $this->branches->activeBranch($actor);
        if (! $branch || $branch->id !== (int) $validated['expected_branch_id']) {
            throw ValidationException::withMessages([
                'expected_branch_id' => 'The active branch changed. Review and retry.',
            ]);
        }
        $mode = $this->mode($actor);
        $doctorId = (int) $validated['doctor_user_id'];
        if ($mode === null || ($mode === 'own' && $doctorId !== $actor->id)) {
            throw new AuthorizationException('You may not call this doctor\'s next patient.');
        }

        return DB::transaction(function () use ($actor, $branch, $doctorId, $validated): QueueEntry {
            // The doctor's row lock is the one every Call In for this doctor takes first, so nobody else can call
            // one of their patients between this check and the call below.
            ConsultationLocks::users($actor->organisation_id, [$actor->id, $doctorId]);
            $today = now()->setTimezone($branch->timezone)->toDateString();
            $entry = $this->next($actor, $branch, $doctorId, $today);
            if ($entry === null) {
                throw ValidationException::withMessages([
                    'next_none' => 'Nobody is waiting who can be called now.',
                ]);
            }
            if ($entry->visit->visit_number !== $validated['visit_number']
                || $entry->visit->lock_version !== (int) $validated['visit_lock_version']
                || $entry->lock_version !== (int) $validated['queue_lock_version']) {
                throw ValidationException::withMessages([
                    'next_changed' => 'The next patient changed. Check the patient shown and call again.',
                ]);
            }

            return $this->queue->call($actor, $entry->visit, [
                'expected_branch_id' => $branch->id,
                'visit_lock_version' => $entry->visit->lock_version,
                'queue_lock_version' => $entry->lock_version,
            ]);
        }, 3);
    }

    /** 'own' for a doctor's own queue, 'branch' for a branch caller, null when this board cannot call. */
    private function mode(User $actor): ?string
    {
        if ($actor->can('queue.view.branch')) {
            return $actor->can('queue.call.branch') ? 'branch' : null;
        }

        return $actor->can('queue.call.own') ? 'own' : null;
    }

    private function next(User $actor, Branch $branch, int $doctorId, string $today): ?QueueEntry
    {
        return $this->waiting($actor, $branch, $doctorId, $today)
            ->whereNotExists(fn ($flag) => $flag->selectRaw('1')->from('queue_call_holds')
                ->whereColumn('queue_call_holds.queue_entry_id', 'queue_entries.id')
                ->whereNull('queue_call_holds.closed_at'))
            // Today first; carry-over only when today has nobody. Within each: Urgent first, then arrival.
            ->orderByRaw('CASE WHEN queue_entries.operational_date = ? THEN 0 ELSE 1 END', [$today])
            ->orderByRaw("CASE visits.priority WHEN 'urgent' THEN 0 ELSE 1 END")
            ->orderBy('queue_entries.operational_date')
            ->orderBy('queue_entries.queued_at')
            ->orderBy('queue_entries.id')
            ->select('queue_entries.*')
            // QueueEntryService::call() reads only these Visit columns before it re-locks the row; add any column
            // it starts reading earlier here too.
            ->with(['visit:id,organisation_id,branch_id,patient_id,visit_number,priority,assigned_doctor_user_id,status,lock_version', 'visit.patient:id,patient_number,full_name'])
            ->first();
    }

    /** @return Builder<QueueEntry> */
    private function waiting(User $actor, Branch $branch, int $doctorId, string $today)
    {
        return QueueEntry::query()
            ->join('visits', 'visits.id', '=', 'queue_entries.visit_id')
            ->where('queue_entries.organisation_id', $actor->organisation_id)
            ->where('queue_entries.branch_id', $branch->id)
            ->where('queue_entries.status', QueueEntry::STATUS_WAITING)
            ->whereDate('queue_entries.operational_date', '<=', $today)
            ->where('visits.status', Visit::STATUS_REGISTERED)
            ->where('visits.assigned_doctor_user_id', $doctorId);
    }

    /** @return array{state: string, reason: string} */
    private function emptyState(User $actor, Branch $branch, int $doctorId, string $today): array
    {
        return $this->waiting($actor, $branch, $doctorId, $today)->exists()
            ? ['state' => self::STATE_ALL_NOT_READY, 'reason' => 'Everyone waiting is marked not ready to call.']
            : ['state' => self::STATE_NONE_WAITING, 'reason' => 'Nobody is waiting.'];
    }

    /** @return array<string, mixed> */
    private function candidate(QueueEntry $entry, string $today, ?string $roomName): array
    {
        $visit = $entry->visit;

        return [
            'visitNumber' => $visit->visit_number,
            'queueNumber' => QueueNumberFormat::format($entry->queue_number),
            'patientName' => $visit->patient->full_name,
            'patientNumber' => $visit->patient->patient_number,
            'priority' => $visit->priority,
            'carryOver' => $entry->operational_date->toDateString() !== $today,
            'operationalDate' => $entry->operational_date->toDateString(),
            'roomName' => $roomName,
            'visitLockVersion' => $visit->lock_version,
            'queueLockVersion' => $entry->lock_version,
        ];
    }
}
