<?php

namespace App\Domain\HR\Memos\Services;

use App\Domain\HR\Roster\RosterPermissions;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * HR-8: who is expected to read a memo, computed live. Roles come from the account's current roles; branches from
 * `staff_branch_assignments` valid today in each branch's own time zone. Nothing ticked means everyone; branches and
 * roles ticked together mean both must match.
 */
final class MemoAudience
{
    /** @return Collection<int, User> active human staff of the organisation, roles loaded */
    public function people(int $organisationId): Collection
    {
        return User::query()->with('roles')->where('organisation_id', $organisationId)->where('is_active', true)
            ->whereHas('roles', fn ($q) => $q->whereIn('name', RosterPermissions::HUMAN_ROLES))->orderBy('name')->orderBy('id')->get();
    }

    /**
     * @param  array<int, int>  $userIds
     * @return array<int, list<array{branch_id:int, name:string, primary:bool}>> effective assignments today, per user
     */
    public function effective(int $organisationId, array $userIds): array
    {
        $out = [];
        if ($userIds === []) {
            return $out;
        }
        foreach (DB::table('branches')->where('organisation_id', $organisationId)->get(['id', 'name', 'timezone']) as $branch) {
            $zone = is_string($branch->timezone) && $branch->timezone !== '' ? $branch->timezone : 'UTC';
            $today = CarbonImmutable::now($zone)->toDateString();
            DB::table('staff_branch_assignments as a')->join('staff_profiles as p', 'p.id', '=', 'a.staff_profile_id')
                ->whereIn('p.user_id', $userIds)->where('a.branch_id', $branch->id)->whereDate('a.valid_from', '<=', $today)
                ->where(fn ($q) => $q->whereNull('a.valid_until')->orWhereDate('a.valid_until', '>=', $today))
                ->get(['p.user_id', 'a.is_primary'])
                ->each(function (\stdClass $r) use (&$out, $branch): void {
                    $out[(int) $r->user_id][] = ['branch_id' => (int) $branch->id, 'name' => (string) $branch->name, 'primary' => (bool) $r->is_primary];
                });
        }

        return $out;
    }

    /**
     * @param  array<int, int>  $branchIds
     * @param  array<int, string>  $roles
     * @param  array<int, list<array{branch_id:int, name:string, primary:bool}>>  $effective
     */
    public function expects(User $user, array $branchIds, array $roles, array $effective): bool
    {
        if (! $user->is_active) {
            return false;
        }
        $held = array_values(array_intersect(RosterPermissions::HUMAN_ROLES, $user->roles->pluck('name')->all()));
        if ($held === [] || ($roles !== [] && array_intersect($roles, $held) === [])) {
            return false;
        }
        if ($branchIds !== [] && array_intersect($branchIds, array_column($effective[$user->id] ?? [], 'branch_id')) === []) {
            return false;
        }

        return true;
    }

    /**
     * @param  array<int, int>  $branchIds
     * @param  array<int, string>  $roles
     * @return Collection<int, User>
     */
    public function users(int $organisationId, array $branchIds, array $roles): Collection
    {
        $people = $this->people($organisationId);
        $effective = $branchIds === [] ? [] : $this->effective($organisationId, $people->pluck('id')->map(fn ($id): int => (int) $id)->all());

        return $people->filter(fn (User $u): bool => $this->expects($u, $branchIds, $roles, $effective))->values();
    }

    /** True when this one account is expected to read a memo with that audience. */
    /**
     * @param  array<int, int>  $branchIds
     * @param  array<int, string>  $roles
     */
    public function includes(User $user, array $branchIds, array $roles): bool
    {
        $user->loadMissing('roles');

        return $this->expects($user, $branchIds, $roles, $branchIds === [] ? [] : $this->effective((int) $user->organisation_id, [(int) $user->id]));
    }
}
