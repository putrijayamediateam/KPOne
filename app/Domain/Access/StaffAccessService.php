<?php

namespace App\Domain\Access;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class StaffAccessService
{
    public function __construct(private BranchAccessService $branches) {}

    /** @return Builder<User> */
    public function visibleUsers(User $viewer): Builder
    {
        $query = User::query()->where('organisation_id', $viewer->organisation_id);

        if ($viewer->can('staff.view.organisation')) {
            return $query;
        }

        if ($viewer->can('staff.view.branch') && ($branch = $this->branches->activeBranch($viewer))) {
            return $query->whereHas('staffProfile.branchAssignments', fn ($assignment) => $assignment
                ->whereDate('valid_from', '<=', now()->toDateString())
                ->where(fn ($period) => $period
                    ->whereNull('valid_until')
                    ->orWhereDate('valid_until', '>=', now()->toDateString()))
                ->where('branch_id', $branch->id));
        }

        return $query->whereKey($viewer->id);
    }

    public function canView(User $viewer, User $subject): bool
    {
        if ($viewer->organisation_id !== $subject->organisation_id) {
            return false;
        }

        if ($viewer->is($subject) && $viewer->can('staff.view.own')) {
            return true;
        }

        return $this->visibleUsers($viewer)->whereKey($subject->id)->exists();
    }
}
