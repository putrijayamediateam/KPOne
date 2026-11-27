<?php

namespace App\Domain\Shared\Services;

use App\Domain\Access\BranchAccessService;
use App\Domain\Organisation\Models\Branch;
use App\Models\User;

/**
 * INS-BM-1 (owner decision 21 Nov 2026, confirmed 27 Nov): Insights pages are opened with the organisation-level
 * `insights.view.organisation`, which every role holds, and take a branch code or "all". An account that cannot switch
 * across the organisation (the Branch Manager) sees only its active branch: the page defaults to that branch, offers
 * no other branch and no "All branches", and refuses any other value. The same rule guards the CSV exports
 * (BranchOnlyExportScope), so a page and its download links always agree.
 */
final class InsightsBranchScope
{
    public function __construct(private BranchAccessService $branches) {}

    public function seesOrganisation(User $actor): bool
    {
        return $actor->can('branch_context.switch.organisation');
    }

    /** The branch value used when the request names none: "all" for an organisation account, else its active branch. */
    public function defaultScope(User $actor): string
    {
        return $this->seesOrganisation($actor) ? 'all' : $this->activeBranch($actor)->code;
    }

    /** Refuses a branch value outside the actor's Insights scope; returns the value to use. */
    public function resolve(User $actor, ?string $requested): string
    {
        $scope = $requested ?? $this->defaultScope($actor);
        if (! $this->seesOrganisation($actor)) {
            BranchOnlyExportScope::enforce($actor, $this->activeBranch($actor), $scope);
        }

        return $scope;
    }

    /**
     * Narrows a branch picker list to the actor's scope.
     *
     * @param  list<array{code: string, name: string}>  $options
     * @return list<array{code: string, name: string}>
     */
    public function pickerBranches(User $actor, array $options): array
    {
        if ($this->seesOrganisation($actor)) {
            return $options;
        }
        $code = $this->activeBranch($actor)->code;

        return array_values(array_filter($options, static fn (array $option): bool => $option['code'] === $code));
    }

    private function activeBranch(User $actor): Branch
    {
        $branch = $this->branches->activeBranch($actor);
        abort_unless($branch instanceof Branch && $branch->organisation_id === $actor->organisation_id, 403);

        return $branch;
    }
}
