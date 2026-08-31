<?php

namespace App\Domain\Queue\Policies;

use App\Domain\Access\BranchAccessService;
use App\Domain\Queue\Models\QueueEntry;
use App\Models\User;

class QueueEntryPolicy
{
    public function __construct(private BranchAccessService $branches) {}

    public function viewAny(User $actor): bool
    {
        return ($actor->can('queue.view.branch') || $actor->can('queue.view.own'))
            && $this->branches->activeBranch($actor) !== null;
    }

    public function view(User $actor, QueueEntry $entry): bool
    {
        $active = $this->branches->activeBranch($actor);
        if ($actor->organisation_id !== $entry->organisation_id
            || $active?->id !== $entry->branch_id
            || ! $this->branches->canSelect($actor, $active)) {
            return false;
        }

        if ($actor->can('queue.view.branch')) {
            return true;
        }

        return $actor->can('queue.view.own')
            && $entry->visit->assigned_doctor_user_id === $actor->id;
    }

    public function create(User $actor): bool
    {
        return $actor->can('queue.enter.branch')
            && $this->branches->activeBranch($actor) !== null;
    }

    public function call(User $actor, QueueEntry $entry): bool
    {
        $active = $this->branches->activeBranch($actor);
        if ($actor->organisation_id !== $entry->organisation_id
            || $active?->id !== $entry->branch_id
            || ! $this->branches->canSelect($actor, $active)) {
            return false;
        }

        return $actor->can('queue.call.branch')
            || ($actor->can('queue.call.own')
                && $entry->visit->assigned_doctor_user_id === $actor->id);
    }
}
