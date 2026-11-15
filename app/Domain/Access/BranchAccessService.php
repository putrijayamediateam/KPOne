<?php

namespace App\Domain\Access;

use App\Domain\Organisation\Models\Branch;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class BranchAccessService
{
    public const SESSION_KEY = 'active_branch_id';

    /** @var array<int, Collection<int, Branch>> */
    private array $availableBranches = [];

    /** @var array<int, Branch|null> */
    private array $activeBranches = [];

    private ?Request $request = null;

    public function canView(User $user, Branch $branch): bool
    {
        if ($user->organisation_id !== $branch->organisation_id || ! $branch->is_active) {
            return false;
        }

        if ($user->can('branches.view.organisation')) {
            return true;
        }

        return $user->can('branches.view.branch')
            && $this->availableBranches($user)->contains('id', $branch->id);
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
        $this->beginRequest();

        if (isset($this->availableBranches[$user->id])) {
            return $this->availableBranches[$user->id];
        }

        $branches = Branch::query()
            ->where('organisation_id', $user->organisation_id)
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        if ($user->can('branch_context.switch.organisation')) {
            return $this->availableBranches[$user->id] = $branches;
        }

        $assignments = $user->staffProfile?->branchAssignments()
            ->whereIn('branch_id', $branches->pluck('id'))
            ->get(['id', 'branch_id', 'valid_from', 'valid_until'])
            ->groupBy('branch_id') ?? collect();

        return $this->availableBranches[$user->id] = $branches
            ->filter(function (Branch $branch) use ($assignments): bool {
                $effectiveDate = now()->setTimezone($branch->timezone)->toDateString();

                return $assignments->get($branch->id, collect())->contains(
                    fn ($assignment): bool => $assignment->valid_from->toDateString() <= $effectiveDate
                        && ($assignment->valid_until === null
                            || $assignment->valid_until->toDateString() >= $effectiveDate),
                );
            })->values();
    }

    public function activeBranch(User $user): ?Branch
    {
        $this->beginRequest();

        if (array_key_exists($user->id, $this->activeBranches)) {
            return $this->activeBranches[$user->id];
        }

        $available = $this->availableBranches($user);
        $selectedId = session(self::SESSION_KEY);
        $selected = $available->firstWhere('id', $selectedId);

        if ($selected) {
            return $selected;
        }

        $primaryId = $user->staffProfile?->branchAssignments()
            ->whereIn('branch_id', $available->pluck('id'))
            ->where('is_primary', true)
            ->value('branch_id');

        $branch = $available->firstWhere('id', $primaryId) ?? $available->first();

        if ($branch) {
            session([self::SESSION_KEY => $branch->id]);
        }

        return $this->activeBranches[$user->id] = $branch;
    }

    public function select(User $user, Branch $branch): void
    {
        $this->beginRequest();
        abort_unless($this->canSelect($user, $branch), 403);

        session([self::SESSION_KEY => $branch->id]);
        $this->activeBranches[$user->id] = $branch;
    }

    public function hasEffectiveAssignment(User $user, Branch $branch): bool
    {
        $effectiveDate = now()->setTimezone($branch->timezone)->toDateString();

        return (bool) $user->staffProfile?->branchAssignments()
            ->effectiveAt($effectiveDate)
            ->where('branch_id', $branch->id)
            ->exists();
    }

    private function beginRequest(): void
    {
        $request = request();
        if ($this->request === $request) {
            return;
        }

        $this->request = $request;
        $this->availableBranches = [];
        $this->activeBranches = [];
    }
}
