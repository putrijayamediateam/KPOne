<?php

namespace App\Domain\Access;

use App\Domain\Organisation\Models\Branch;
use App\Models\User;
use Illuminate\Support\Collection;

class BranchAccessService
{
    public const SESSION_KEY = 'active_branch_id';

    public function canView(User $user, Branch $branch): bool
    {
        if ($user->organisation_id !== $branch->organisation_id || ! $branch->is_active) {
            return false;
        }

        if ($user->can('branches.view.organisation')) {
            return true;
        }

        return $user->can('branches.view.branch') && $this->hasEffectiveAssignment($user, $branch);
    }

    public function canSelect(User $user, Branch $branch): bool
    {
        if ($user->organisation_id !== $branch->organisation_id || ! $branch->is_active) {
            return false;
        }

        return $user->can('branch_context.switch.organisation')
            || ($user->can('branch_context.switch.branch') && $this->hasEffectiveAssignment($user, $branch));
    }

    /** @return Collection<int, Branch> */
    public function availableBranches(User $user): Collection
    {
        $query = Branch::query()
            ->where('organisation_id', $user->organisation_id)
            ->where('is_active', true)
            ->orderBy('name');

        if (! $user->can('branch_context.switch.organisation')) {
            $query->whereHas('staffAssignments', fn ($assignment) => $assignment
                ->whereDate('valid_from', '<=', now()->toDateString())
                ->where(fn ($period) => $period
                    ->whereNull('valid_until')
                    ->orWhereDate('valid_until', '>=', now()->toDateString()))
                ->whereHas('staffProfile', fn ($profile) => $profile->where('user_id', $user->id)));
        }

        return $query->get();
    }

    public function activeBranch(User $user): ?Branch
    {
        $available = $this->availableBranches($user);
        $selectedId = session(self::SESSION_KEY);
        $selected = $available->firstWhere('id', $selectedId);

        if ($selected) {
            return $selected;
        }

        $primaryId = $user->staffProfile?->branchAssignments()
            ->effectiveAt()
            ->where('is_primary', true)
            ->value('branch_id');

        $branch = $available->firstWhere('id', $primaryId) ?? $available->first();

        if ($branch) {
            session([self::SESSION_KEY => $branch->id]);
        }

        return $branch;
    }

    public function select(User $user, Branch $branch): void
    {
        abort_unless($this->canSelect($user, $branch), 403);

        session([self::SESSION_KEY => $branch->id]);
    }

    public function hasEffectiveAssignment(User $user, Branch $branch): bool
    {
        return (bool) $user->staffProfile?->branchAssignments()
            ->effectiveAt()
            ->where('branch_id', $branch->id)
            ->exists();
    }
}
