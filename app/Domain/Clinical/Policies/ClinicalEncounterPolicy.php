<?php

namespace App\Domain\Clinical\Policies;

use App\Domain\Access\BranchAccessService;
use App\Domain\Clinical\Models\ClinicalEncounter;
use App\Domain\Queue\Models\QueueEntry;
use App\Domain\Visit\Models\Visit;
use App\Models\User;

class ClinicalEncounterPolicy
{
    public function __construct(private BranchAccessService $branches) {}

    public function viewAny(User $actor): bool
    {
        return $actor->is_active
            && $actor->hasRole('resident_doctor')
            && $actor->can('encounters.view.own')
            && $this->branches->activeBranch($actor) !== null;
    }

    public function start(User $actor, Visit $visit): bool
    {
        return $actor->is_active
            && $actor->hasRole('resident_doctor')
            && $actor->can('encounters.start.own')
            && $actor->organisation_id === $visit->organisation_id
            && $actor->id === $visit->assigned_doctor_user_id
            && $this->ownsActiveBranch($actor, $visit->branch_id);
    }

    public function view(User $actor, ClinicalEncounter $encounter): bool
    {
        return $this->ownsEncounter($actor, $encounter, 'encounters.view.own');
    }

    public function update(User $actor, ClinicalEncounter $encounter): bool
    {
        return $this->ownsEncounter($actor, $encounter, 'encounters.update.own');
    }

    public function history(User $actor, ClinicalEncounter $encounter): bool
    {
        return $this->ownsEncounter($actor, $encounter, 'encounters.view.own')
            && $actor->can('encounters.history.view.organisation');
    }

    public function viewAllergies(User $actor, ClinicalEncounter $encounter): bool
    {
        return $this->ownsEncounter($actor, $encounter, 'allergies.view.own');
    }

    public function updateAllergies(User $actor, ClinicalEncounter $encounter): bool
    {
        return $this->ownsEncounter($actor, $encounter, 'allergies.update.own');
    }

    public function reviewAllergies(User $actor, ClinicalEncounter $encounter): bool
    {
        return $this->ownsEncounter($actor, $encounter, 'allergies.review.own');
    }

    public function viewProblems(User $actor, ClinicalEncounter $encounter): bool
    {
        return $this->ownsEncounter($actor, $encounter, 'problems.view.own');
    }

    public function updateProblems(User $actor, ClinicalEncounter $encounter): bool
    {
        return $this->ownsEncounter($actor, $encounter, 'problems.update.own');
    }

    public function viewHistory(
        User $actor,
        ClinicalEncounter $historical,
        ?ClinicalEncounter $currentCare,
    ): bool {
        if (! $this->canViewClinicalHistory($actor)
            || $actor->organisation_id !== $historical->organisation_id) {
            return false;
        }

        if ($historical->attending_clinician_user_id === $actor->id) {
            return true;
        }

        if (! $currentCare
            || $currentCare->organisation_id !== $actor->organisation_id
            || $currentCare->attending_clinician_user_id !== $actor->id
            || $currentCare->status !== ClinicalEncounter::STATUS_IN_PROGRESS
            || $currentCare->visit->patient_id !== $historical->visit->patient_id
            || $currentCare->visit->assigned_doctor_user_id !== $actor->id
            || $currentCare->visit->status !== Visit::STATUS_REGISTERED
            || $currentCare->visit->visit_type !== 'consultation'
            || $currentCare->visit->queueEntry?->status !== QueueEntry::STATUS_SERVING) {
            return false;
        }

        return $this->ownsActiveBranch($actor, $currentCare->branch_id);
    }

    private function ownsEncounter(User $actor, ClinicalEncounter $encounter, string $permission): bool
    {
        return $actor->is_active
            && $actor->hasRole('resident_doctor')
            && $actor->can($permission)
            && $actor->organisation_id === $encounter->organisation_id
            && $actor->id === $encounter->attending_clinician_user_id
            && $this->ownsActiveBranch($actor, $encounter->branch_id);
    }

    private function canViewClinicalHistory(User $actor): bool
    {
        $active = $this->branches->activeBranch($actor);

        return $actor->is_active
            && $actor->hasRole('resident_doctor')
            && $actor->can('encounters.view.own')
            && $actor->can('encounters.history.view.organisation')
            && $actor->staffProfile()->exists()
            && $active !== null
            && $this->branches->hasEffectiveAssignment($actor, $active);
    }

    private function ownsActiveBranch(User $actor, int $branchId): bool
    {
        $active = $this->branches->activeBranch($actor);

        return $active?->id === $branchId
            && $this->branches->canSelect($actor, $active)
            && $this->branches->hasEffectiveAssignment($actor, $active);
    }
}
