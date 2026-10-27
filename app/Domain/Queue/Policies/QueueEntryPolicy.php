<?php

namespace App\Domain\Queue\Policies;

use App\Domain\Access\BranchAccessService;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Queue\Models\QueueEntry;
use App\Models\User;
use Closure;

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

        return $this->viewPermission($actor, $entry);
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

        return $this->callPermission($actor, $entry);
    }

    /** Request-local read hints: evaluate the identical branch scope once for a board page.
     * Mutation policies above still make their own fresh checks.
     *
     * @return Closure(QueueEntry):array{view:bool,call:bool}
     */
    public function boardHints(User $actor, Branch $branch): Closure
    {
        $active = $this->branches->activeBranch($actor);
        $scope = $actor->organisation_id === $branch->organisation_id
            && $active?->id === $branch->id && $this->branches->canSelect($actor, $branch);

        return fn (QueueEntry $entry): array => [
            'view' => $scope && $entry->organisation_id === $actor->organisation_id && $entry->branch_id === $branch->id && $this->viewPermission($actor, $entry),
            'call' => $scope && $entry->organisation_id === $actor->organisation_id && $entry->branch_id === $branch->id && $this->callPermission($actor, $entry),
        ];
    }

    private function viewPermission(User $actor, QueueEntry $entry): bool
    {
        return $actor->can('queue.view.branch') || ($actor->can('queue.view.own') && $entry->visit->assigned_doctor_user_id === $actor->id);
    }

    private function callPermission(User $actor, QueueEntry $entry): bool
    {
        return $actor->can('queue.call.branch') || ($actor->can('queue.call.own') && $entry->visit->assigned_doctor_user_id === $actor->id);
    }
}
