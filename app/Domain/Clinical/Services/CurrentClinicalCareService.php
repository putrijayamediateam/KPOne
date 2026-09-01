<?php

namespace App\Domain\Clinical\Services;

use App\Domain\Access\BranchAccessService;
use App\Domain\Clinical\Models\ClinicalEncounter;
use App\Domain\Identity\Models\StaffBranchAssignment;
use App\Domain\Identity\Models\StaffProfile;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Patient\Models\Patient;
use App\Domain\Queue\Models\QueueEntry;
use App\Domain\Visit\Models\Visit;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

class CurrentClinicalCareService
{
    public function __construct(private BranchAccessService $branches) {}

    /** @param array<string, mixed> $attributes */
    public function activeBranch(User $actor, Visit $visit, array $attributes): Branch
    {
        $branch = $this->branches->activeBranch($actor);
        abort_unless(
            $branch
            && $actor->organisation_id === $visit->organisation_id
            && $branch->organisation_id === $visit->organisation_id
            && $branch->id === $visit->branch_id,
            404,
        );

        if ((int) ($attributes['expected_branch_id'] ?? 0) !== $branch->id) {
            throw ValidationException::withMessages([
                'expected_branch_id' => 'The active branch changed after this form was opened. Review and retry.',
            ]);
        }

        return $branch;
    }

    public function lock(User $actor, Visit $visit, Branch $branch, string $permission): CurrentClinicalCareContext
    {
        $lockedActor = User::query()
            ->whereKey($actor->id)
            ->where('organisation_id', $branch->organisation_id)
            ->lockForUpdate()
            ->firstOrFail();
        $lockedActor->load(['roles.permissions', 'permissions']);

        $profile = StaffProfile::query()
            ->where('user_id', $lockedActor->id)
            ->lockForUpdate()
            ->first();
        $assignments = $profile
            ? StaffBranchAssignment::query()
                ->where('staff_profile_id', $profile->id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
            : collect();
        $effectiveDate = now()->setTimezone($branch->timezone)->toDateString();
        $assigned = $assignments->contains(fn (StaffBranchAssignment $assignment): bool => $assignment->branch_id === $branch->id
            && $assignment->valid_from->toDateString() <= $effectiveDate
            && ($assignment->valid_until === null || $assignment->valid_until->toDateString() >= $effectiveDate));

        if (! $lockedActor->is_active || ! $profile || ! $lockedActor->hasRole('resident_doctor')
            || ! $lockedActor->can($permission) || ! $assigned) {
            throw new AuthorizationException('You may not access this clinical safety record.');
        }

        $patient = Patient::query()
            ->whereKey($visit->patient_id)
            ->where('organisation_id', $lockedActor->organisation_id)
            ->lockForUpdate()
            ->firstOrFail();
        $lockedVisit = Visit::query()
            ->whereKey($visit->id)
            ->where('organisation_id', $lockedActor->organisation_id)
            ->where('branch_id', $branch->id)
            ->where('patient_id', $patient->id)
            ->lockForUpdate()
            ->firstOrFail();
        $queue = QueueEntry::query()
            ->where('visit_id', $lockedVisit->id)
            ->lockForUpdate()
            ->firstOrFail();
        $encounter = ClinicalEncounter::query()
            ->where('visit_id', $lockedVisit->id)
            ->where('attending_clinician_user_id', $lockedActor->id)
            ->lockForUpdate()
            ->firstOrFail();

        abort_unless($lockedVisit->assigned_doctor_user_id === $lockedActor->id, 404);

        if ($lockedVisit->status !== Visit::STATUS_REGISTERED
            || $lockedVisit->visit_type !== 'consultation'
            || $queue->status !== QueueEntry::STATUS_SERVING
            || $encounter->status !== ClinicalEncounter::STATUS_IN_PROGRESS
            || $encounter->attending_clinician_user_id !== $lockedActor->id) {
            throw new AuthorizationException('You may not access this clinical safety record.');
        }

        return new CurrentClinicalCareContext(
            $lockedActor,
            $branch,
            $patient,
            $lockedVisit,
            $queue,
            $encounter,
        );
    }
}
