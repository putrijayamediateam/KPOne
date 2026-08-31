<?php

namespace App\Domain\Visit\Policies;

use App\Domain\Access\BranchAccessService;
use App\Domain\Queue\Models\QueueEntry;
use App\Domain\Visit\Models\Visit;
use App\Models\User;

class VisitPolicy
{
    public function __construct(private BranchAccessService $branches) {}

    public function viewAny(User $actor): bool
    {
        return $actor->can('visits.view.branch') && $this->branches->activeBranch($actor) !== null;
    }

    public function view(User $actor, Visit $visit): bool
    {
        $active = $this->branches->activeBranch($actor);

        return $actor->organisation_id === $visit->organisation_id
            && $actor->can('visits.view.branch')
            && $active?->id === $visit->branch_id
            && $this->branches->canView($actor, $visit->branch);
    }

    public function create(User $actor): bool
    {
        return $actor->can('visits.create.branch') && $this->branches->activeBranch($actor) !== null;
    }

    public function update(User $actor, Visit $visit): bool
    {
        return $visit->status === Visit::STATUS_REGISTERED
            && $this->queueAllowsMutation($visit)
            && $actor->organisation_id === $visit->organisation_id
            && $actor->can('visits.update.branch')
            && $this->isActiveVisitBranch($actor, $visit);
    }

    public function cancel(User $actor, Visit $visit): bool
    {
        return $visit->status === Visit::STATUS_REGISTERED
            && $this->queueAllowsMutation($visit)
            && $actor->organisation_id === $visit->organisation_id
            && $actor->can('visits.cancel.branch')
            && $this->isActiveVisitBranch($actor, $visit);
    }

    private function isActiveVisitBranch(User $actor, Visit $visit): bool
    {
        $active = $this->branches->activeBranch($actor);

        return $active !== null && $active->id === $visit->branch_id && $this->branches->canSelect($actor, $active);
    }

    private function queueAllowsMutation(Visit $visit): bool
    {
        $status = $visit->relationLoaded('queueEntry')
            ? $visit->queueEntry?->status
            : $visit->queueEntry()->value('status');

        return $status === null || $status === QueueEntry::STATUS_WAITING;
    }
}
