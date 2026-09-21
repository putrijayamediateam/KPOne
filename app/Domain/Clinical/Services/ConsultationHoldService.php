<?php

namespace App\Domain\Clinical\Services;

use App\Domain\Access\BranchAccessService;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Clinical\Models\ClinicalEncounter;
use App\Domain\Clinical\Models\ConsultationHold;
use App\Domain\Identity\Models\StaffBranchAssignment;
use App\Domain\Identity\Models\StaffProfile;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Queue\Models\QueueEntry;
use App\Domain\Visit\Models\Visit;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ConsultationHoldService
{
    public function __construct(
        private BranchAccessService $branches,
        private AuditRecorder $audit,
    ) {}

    /** @param array<string, mixed> $attributes */
    public function hold(User $actor, Visit $visit, array $attributes): ConsultationHold
    {
        $validated = $this->validate($attributes);
        $fingerprint = $this->fingerprint([
            ...Arr::except($validated, ['idempotency_key']),
            'visit_id' => $visit->id,
        ]);
        $branch = $this->activeBranch($actor, $visit, $validated);

        try {
            return DB::transaction(function () use ($actor, $visit, $validated, $fingerprint, $branch): ConsultationHold {
                $doctor = $this->lockDoctor($actor, $branch);
                $lockedVisit = $this->lockVisit($visit, $doctor, $branch);
                $queue = QueueEntry::query()->where('visit_id', $lockedVisit->id)->lockForUpdate()->firstOrFail();
                $encounter = ClinicalEncounter::query()->where('visit_id', $lockedVisit->id)->lockForUpdate()->firstOrFail();
                $holds = ConsultationHold::query()
                    ->where('clinical_encounter_id', $encounter->id)
                    ->orderBy('id')->lockForUpdate()->get();

                $replay = $holds->firstWhere('hold_idempotency_key', $validated['idempotency_key']);
                if ($replay instanceof ConsultationHold) {
                    $this->requireFingerprint($replay->hold_fingerprint, $fingerprint);

                    return $replay;
                }

                $this->assertVersions($lockedVisit, $queue, $encounter, $validated);
                $this->assertActiveConsultation($doctor, $lockedVisit, $queue, $encounter);
                if ($holds->contains(fn (ConsultationHold $hold): bool => $hold->resumed_at === null)) {
                    throw ValidationException::withMessages(['hold' => 'This consultation is already On Hold.']);
                }

                $now = now()->utc();
                $hold = new ConsultationHold;
                $hold->forceFill([
                    'organisation_id' => $encounter->organisation_id,
                    'branch_id' => $encounter->branch_id,
                    'clinical_encounter_id' => $encounter->id,
                    'visit_id' => $lockedVisit->id,
                    'queue_entry_id' => $queue->id,
                    'held_by_user_id' => $doctor->id,
                    'held_at' => $now,
                    'hold_idempotency_key' => $validated['idempotency_key'],
                    'hold_fingerprint' => $fingerprint,
                ])->save();

                $this->bumpVersions($encounter, $queue, $doctor);
                $this->audit->record('consultation.held', $hold, [
                    'visit_id' => $lockedVisit->id,
                    'queue_entry_id' => $queue->id,
                    'from_state' => 'active',
                    'to_state' => 'held',
                ], $doctor, $branch, $doctor->organisation_id);

                return $hold->refresh();
            }, 3);
        } catch (QueryException $exception) {
            $replay = ConsultationHold::query()
                ->where('organisation_id', $actor->organisation_id)
                ->where('hold_idempotency_key', $validated['idempotency_key'])
                ->first();
            if ($replay) {
                $this->requireFingerprint($replay->hold_fingerprint, $fingerprint);

                return $replay;
            }

            throw $exception;
        }
    }

    /** @param array<string, mixed> $attributes */
    public function resume(User $actor, Visit $visit, array $attributes): ConsultationHold
    {
        $validated = $this->validate($attributes);
        $fingerprint = $this->fingerprint([
            ...Arr::except($validated, ['idempotency_key']),
            'visit_id' => $visit->id,
        ]);
        $branch = $this->activeBranch($actor, $visit, $validated);

        return DB::transaction(function () use ($actor, $visit, $validated, $fingerprint, $branch): ConsultationHold {
            $doctor = $this->lockDoctor($actor, $branch);
            $lockedVisit = $this->lockVisit($visit, $doctor, $branch);
            $queue = QueueEntry::query()->where('visit_id', $lockedVisit->id)->lockForUpdate()->firstOrFail();
            $encounter = ClinicalEncounter::query()->where('visit_id', $lockedVisit->id)->lockForUpdate()->firstOrFail();
            $holds = ConsultationHold::query()
                ->where('clinical_encounter_id', $encounter->id)
                ->orderBy('id')->lockForUpdate()->get();

            $replay = $holds->firstWhere('resume_idempotency_key', $validated['idempotency_key']);
            if ($replay instanceof ConsultationHold) {
                $this->requireFingerprint((string) $replay->resume_fingerprint, $fingerprint);

                return $replay;
            }

            $this->assertVersions($lockedVisit, $queue, $encounter, $validated);
            $this->assertActiveConsultation($doctor, $lockedVisit, $queue, $encounter);
            $hold = $holds->first(fn (ConsultationHold $candidate): bool => $candidate->resumed_at === null);
            if (! $hold) {
                throw ValidationException::withMessages(['hold' => 'This consultation is not On Hold.']);
            }
            if ($this->hasOtherActiveConsultation($doctor, $lockedVisit)) {
                throw ValidationException::withMessages([
                    'hold' => 'Complete or place the current consultation On Hold before resuming this patient.',
                ]);
            }

            $hold->forceFill([
                'resumed_by_user_id' => $doctor->id,
                'resumed_at' => now()->utc(),
                'resume_idempotency_key' => $validated['idempotency_key'],
                'resume_fingerprint' => $fingerprint,
            ])->save();
            $this->bumpVersions($encounter, $queue, $doctor);
            $this->audit->record('consultation.resumed', $hold, [
                'visit_id' => $lockedVisit->id,
                'queue_entry_id' => $queue->id,
                'from_state' => 'held',
                'to_state' => 'active',
            ], $doctor, $branch, $doctor->organisation_id);

            return $hold->refresh();
        }, 3);
    }

    /**
     * Returns a consultation to Serving as On Hold when its doctor already has another active patient, so a
     * doctor never has two non-held consultations. The caller must already hold the visit, queue and encounter
     * locks in the current transaction; the doctor row lock taken here serialises against Queue call and resume.
     */
    public function holdReturningConsultation(
        User $actor,
        Branch $branch,
        Visit $visit,
        QueueEntry $queue,
        ClinicalEncounter $encounter,
    ): bool {
        $doctor = $visit->assigned_doctor_user_id === null ? null : User::query()
            ->whereKey($visit->assigned_doctor_user_id)
            ->where('organisation_id', $visit->organisation_id)
            ->lockForUpdate()->first();
        if (! $doctor || ! $this->hasOtherActiveConsultation($doctor, $visit)
            || ConsultationHold::query()->where('clinical_encounter_id', $encounter->id)
                ->whereNull('resumed_at')->lockForUpdate()->exists()) {
            return false;
        }

        $hold = new ConsultationHold;
        $hold->forceFill([
            'organisation_id' => $encounter->organisation_id,
            'branch_id' => $encounter->branch_id,
            'clinical_encounter_id' => $encounter->id,
            'visit_id' => $visit->id,
            'queue_entry_id' => $queue->id,
            'held_by_user_id' => $actor->id,
            'held_at' => now()->utc(),
            'hold_idempotency_key' => (string) Str::uuid(),
            'hold_fingerprint' => $this->fingerprint(['visit_id' => $visit->id, 'source' => 'returned_while_doctor_busy']),
        ])->save();
        $encounter->forceFill([
            'updated_by_user_id' => $actor->id,
            'lock_version' => $encounter->lock_version + 1,
        ])->save();
        $this->audit->record('consultation.held', $hold, [
            'visit_id' => $visit->id,
            'queue_entry_id' => $queue->id,
            'from_state' => 'returned',
            'to_state' => 'held',
            'reason' => 'doctor_has_active_consultation',
        ], $actor, $branch, $actor->organisation_id);

        return true;
    }

    /** @param array<string, mixed> $attributes
     * @return array<string, mixed>
     */
    private function validate(array $attributes): array
    {
        return Validator::make($attributes, [
            'expected_branch_id' => ['required', 'integer'],
            'visit_lock_version' => ['required', 'integer', 'min:1'],
            'queue_lock_version' => ['required', 'integer', 'min:1'],
            'encounter_lock_version' => ['required', 'integer', 'min:1'],
            'idempotency_key' => ['required', 'uuid'],
        ])->validate();
    }

    /** @param array<string, mixed> $attributes */
    private function activeBranch(User $actor, Visit $visit, array $attributes): Branch
    {
        $branch = $this->branches->activeBranch($actor);
        if (! $branch || $branch->id !== $visit->branch_id
            || (int) $attributes['expected_branch_id'] !== $branch->id) {
            throw ValidationException::withMessages(['expected_branch_id' => 'The active branch changed. Reload and retry.']);
        }

        return $branch;
    }

    private function lockDoctor(User $actor, Branch $branch): User
    {
        $doctor = User::query()->whereKey($actor->id)
            ->where('organisation_id', $branch->organisation_id)
            ->lockForUpdate()->firstOrFail();
        $doctor->load(['roles.permissions', 'permissions']);
        $profile = StaffProfile::query()->where('user_id', $doctor->id)->lockForUpdate()->first();
        $assignments = $profile
            ? StaffBranchAssignment::query()->where('staff_profile_id', $profile->id)->lockForUpdate()->get()
            : collect();
        $date = now()->setTimezone($branch->timezone)->toDateString();
        $assigned = $assignments->contains(fn (StaffBranchAssignment $assignment): bool => $assignment->branch_id === $branch->id
            && $assignment->valid_from->toDateString() <= $date
            && ($assignment->valid_until === null || $assignment->valid_until->toDateString() >= $date));
        if (! $doctor->is_active || ! $profile || ! $doctor->hasRole('resident_doctor')
            || ! $doctor->can('consultations.hold.own') || ! $assigned) {
            throw new AuthorizationException('You may not hold or resume this consultation.');
        }

        return $doctor;
    }

    private function lockVisit(Visit $visit, User $doctor, Branch $branch): Visit
    {
        return Visit::query()->whereKey($visit->id)
            ->where('organisation_id', $doctor->organisation_id)
            ->where('branch_id', $branch->id)
            ->where('assigned_doctor_user_id', $doctor->id)
            ->lockForUpdate()->firstOrFail();
    }

    /** @param array<string, mixed> $validated */
    private function assertVersions(Visit $visit, QueueEntry $queue, ClinicalEncounter $encounter, array $validated): void
    {
        if ($visit->lock_version !== (int) $validated['visit_lock_version']
            || $queue->lock_version !== (int) $validated['queue_lock_version']
            || $encounter->lock_version !== (int) $validated['encounter_lock_version']) {
            throw ValidationException::withMessages(['lock_version' => 'The consultation changed. Reload before trying again.']);
        }
    }

    private function assertActiveConsultation(User $doctor, Visit $visit, QueueEntry $queue, ClinicalEncounter $encounter): void
    {
        if ($visit->status !== Visit::STATUS_REGISTERED || $visit->visit_type !== 'consultation'
            || $queue->status !== QueueEntry::STATUS_SERVING
            || $encounter->status !== ClinicalEncounter::STATUS_IN_PROGRESS
            || $encounter->attending_clinician_user_id !== $doctor->id) {
            throw ValidationException::withMessages(['hold' => 'Only the active assigned consultation can be held or resumed.']);
        }
    }

    private function hasOtherActiveConsultation(User $doctor, Visit $current): bool
    {
        return QueueEntry::query()
            ->where('queue_entries.organisation_id', $doctor->organisation_id)
            ->where('queue_entries.branch_id', $current->branch_id)
            ->where('queue_entries.status', QueueEntry::STATUS_SERVING)
            ->where('queue_entries.visit_id', '!=', $current->id)
            ->whereHas('visit', fn ($query) => $query->where('assigned_doctor_user_id', $doctor->id))
            ->whereDoesntHave('visit.clinicalEncounter.activeHold')
            ->exists();
    }

    private function bumpVersions(ClinicalEncounter $encounter, QueueEntry $queue, User $doctor): void
    {
        $encounter->forceFill([
            'updated_by_user_id' => $doctor->id,
            'lock_version' => $encounter->lock_version + 1,
        ])->save();
        $queue->forceFill([
            'updated_by_user_id' => $doctor->id,
            'lock_version' => $queue->lock_version + 1,
        ])->save();
    }

    /** @param array<string, mixed> $payload */
    private function fingerprint(array $payload): string
    {
        ksort($payload);

        return hash_hmac('sha256', json_encode($payload, JSON_THROW_ON_ERROR), (string) config('app.key'));
    }

    private function requireFingerprint(string $stored, string $actual): void
    {
        if (! hash_equals($stored, $actual)) {
            throw ValidationException::withMessages(['idempotency_key' => 'This action key was already used with a different request.']);
        }
    }
}
