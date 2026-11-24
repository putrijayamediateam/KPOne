<?php

namespace App\Domain\Access;

use App\Domain\Identity\Models\StaffBranchAssignment;
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
            // The branch's own calendar day, as BranchAccessService::hasEffectiveAssignment() uses (BIL-21).
            $today = now()->setTimezone($branch->timezone)->toDateString();

            return $query->whereHas('staffProfile.branchAssignments', function (Builder $assignment) use ($today, $branch): void {
                /** @var Builder<StaffBranchAssignment> $assignment */
                $assignment->effectiveAt($today)->where('branch_id', $branch->id);
            });
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
