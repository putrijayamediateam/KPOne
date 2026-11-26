<?php

namespace App\Domain\Clinical\TreatmentRoom;

use App\Domain\Access\BranchAccessService;
use App\Domain\Clinical\Models\ClinicalEncounter;
use App\Domain\Clinical\Policies\ClinicalEncounterPolicy;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Queue\Models\QueueEntry;
use App\Domain\Visit\Models\Visit;
use App\Models\User;
use Illuminate\Support\Collection;

/** Clinical record access is distinct from viewing a queue row or ordinary visit history. */
final class TreatmentRoomPolicy
{
    public function __construct(private BranchAccessService $branches, private ClinicalEncounterPolicy $clinical) {}

    public function view(User $actor, Visit $visit, bool $history = false): bool
    {
        if (! $actor->is_active || $actor->organisation_id !== $visit->organisation_id) {
            return false;
        }
        if ($history && $actor->can('encounters.history.view.organisation') && $visit->clinicalEncounter) {
            $active = $this->branches->activeBranch($actor);
            $current = $active ? ClinicalEncounter::query()
                ->where('organisation_id', $actor->organisation_id)
                ->where('branch_id', $active->id)
                ->where('attending_clinician_user_id', $actor->id)
                ->where('status', ClinicalEncounter::STATUS_IN_PROGRESS)
                ->whereHas('visit', fn ($query) => $query->where('patient_id', $visit->patient_id)
                    ->where('assigned_doctor_user_id', $actor->id)
                    ->where('status', Visit::STATUS_REGISTERED)->where('visit_type', 'consultation')
                    ->whereHas('queueEntry', fn ($queue) => $queue->where('status', QueueEntry::STATUS_SERVING)))
                ->first() : null;
            if ($this->clinical->viewHistory($actor, $visit->clinicalEncounter, $current)) {
                return true;
            }
        }
        $branch = $this->branches->activeBranch($actor);
        if (! $branch || $branch->id !== $visit->branch_id || ! $this->branches->canSelect($actor, $branch)) {
            return false;
        }

        return $this->viewCurrentAtBranch($actor, $visit, $branch, $history);
    }

    /** Current-record policy for an already bounded visit set; history still uses view().
     * @param  Collection<int,Visit>  $visits
     * @return list<int>
     */
    public function viewableCurrentVisitIds(User $actor, Collection $visits): array
    {
        if (! $actor->is_active || $visits->isEmpty()) {
            return [];
        }
        $branch = $this->branches->activeBranch($actor);
        if (! $branch || ! $this->branches->canSelect($actor, $branch)) {
            return [];
        }
        $ids = [];
        foreach ($visits as $visit) {
            if ($this->viewCurrentAtBranch($actor, $visit, $branch, false)) {
                $ids[$visit->id] = $visit->id;
            }
        }

        return array_values($ids);
    }

    private function viewCurrentAtBranch(User $actor, Visit $visit, Branch $branch, bool $history): bool
    {
        if (! $actor->is_active || $actor->organisation_id !== $visit->organisation_id || $branch->id !== $visit->branch_id) {
            return false;
        }
        if ($actor->can(TreatmentRoomPermissions::VIEW_BRANCH)) {
            return true;
        }
        if ($history || ! $actor->can('encounters.view.own')) {
            return false;
        }
        $attending = $visit->clinicalEncounter->attending_clinician_user_id ?? $visit->assigned_doctor_user_id;

        return $attending === $actor->id && $visit->status === Visit::STATUS_REGISTERED
            && $visit->queueEntry?->status === QueueEntry::STATUS_SERVING;
    }
}
