<?php

namespace App\Domain\Queue\Display;

use App\Domain\Access\BranchAccessService;
use App\Domain\Organisation\Models\Branch;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;

class QueueDisplayAccess
{
    public function __construct(private BranchAccessService $branches) {}

    public function canManage(User $actor, Branch $branch): bool
    {
        if (! $actor->is_active || $actor->organisation_id !== $branch->organisation_id || ! $branch->is_active) {
            return false;
        }

        return $actor->can('queue_display.manage.organisation')
            || ($actor->can('queue_display.manage.branch') && $this->branches->hasEffectiveAssignment($actor, $branch));
    }

    public function canView(User $actor, Branch $branch): bool
    {
        if ($this->canManage($actor, $branch)) {
            return true;
        }

        return $actor->is_active
            && $actor->organisation_id === $branch->organisation_id
            && $branch->is_active
            && $actor->can('queue.display.branch')
            && $this->branches->hasEffectiveAssignment($actor, $branch);
    }

    public function authorizeManage(User $actor, Branch $branch): void
    {
        if (! $this->canManage($actor, $branch)) {
            throw new AuthorizationException('You may not manage the queue display for this branch.');
        }
    }

    public function authorizeView(User $actor, Branch $branch): void
    {
        if (! $this->canView($actor, $branch)) {
            throw new AuthorizationException('You may not view the queue display for this branch.');
        }
    }

    /** @return Collection<int, Branch> */
    public function manageableBranches(User $actor): Collection
    {
        return Branch::query()
            ->where('organisation_id', $actor->organisation_id)
            ->where('is_active', true)
            ->orderBy('name')
            ->get()
            ->filter(fn (Branch $branch): bool => $this->canManage($actor, $branch))
            ->values();
    }
}
