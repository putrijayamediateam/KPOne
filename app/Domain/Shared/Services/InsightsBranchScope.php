<?php

namespace App\Domain\Shared\Services;

use App\Domain\Access\BranchAccessService;
use App\Domain\Organisation\Models\Branch;
use App\Models\User;

/**
 * INS-BM-1 (owner decision 27 Nov 2026, option A): Insights pages are opened with the organisation-level
 * `insights.view.organisation`, which every role holds, and take a branch code or "all". A branch-only account (one
 * without `branch_context.switch.organisation`: today the Branch Manager, CA, CA Supervisor, Resident Doctor and
 * Medical Assistant) sees only its active branch: the page defaults to that branch, offers no other branch and no
 * "All branches", and refuses any other value. The same rule guards the CSV exports
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

    /**
     * INS-BM-3 (owner decision 28 Nov 2026): the doctor filter's choices. An organisation account sees every doctor; a
     * branch-only account sees the doctors of its active branch (assigned there today, or who saw patients there in the
     * last year).
     *
     * @return list<array{id: int, name: string, branch: string}>
     */
    public function doctorOptions(User $actor, ClinicInsightsReportService $reports): array
    {
        return $reports->doctors($actor, $this->seesOrganisation($actor) ? null : $this->activeBranch($actor));
    }

    /**
     * Refuses a branch-only account's doctor filter that is not among its choices (owner decision 28 Nov 2026: refuse
     * with 403 rather than show zeros).
     *
     * The choices are loaded only when there is something to check; a page that already has them passes them in.
     *
     * @param  list<array{id: int, name: string, branch: string}>|null  $options
     */
    public function enforceDoctor(User $actor, ?int $doctorId, ClinicInsightsReportService $reports, ?array $options = null): void
    {
        if ($doctorId === null || $this->seesOrganisation($actor)) {
            return;
        }
        $options ??= $this->doctorOptions($actor, $reports);
        abort_unless(in_array($doctorId, array_column($options, 'id'), true), 403);
    }

    private function activeBranch(User $actor): Branch
    {
        $branch = $this->branches->activeBranch($actor);
        abort_unless($branch instanceof Branch && $branch->organisation_id === $actor->organisation_id, 403);

        return $branch;
    }
}
