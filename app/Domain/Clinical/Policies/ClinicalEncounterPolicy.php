<?php

namespace App\Domain\Clinical\Policies;

use App\Domain\Access\BranchAccessService;
use App\Domain\Clinical\Models\ClinicalEncounter;
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

    private function ownsEncounter(User $actor, ClinicalEncounter $encounter, string $permission): bool
    {
        return $actor->is_active
            && $actor->hasRole('resident_doctor')
            && $actor->can($permission)
            && $actor->organisation_id === $encounter->organisation_id
            && $actor->id === $encounter->attending_clinician_user_id
            && $this->ownsActiveBranch($actor, $encounter->branch_id);
    }

    private function ownsActiveBranch(User $actor, int $branchId): bool
    {
        $active = $this->branches->activeBranch($actor);

        return $active?->id === $branchId && $this->branches->canSelect($actor, $active);
    }
}
