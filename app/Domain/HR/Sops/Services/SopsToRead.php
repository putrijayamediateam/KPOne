<?php

namespace App\Domain\HR\Sops\Services;

use App\Domain\HR\Sops\SopPermissions;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The one "SOPs to read" rule (APPR-1d step 0): the current version of every active SOP published to one of the person's
 * human roles, and which of those the person has not acknowledged. Used by the SOPs page ("SOPs to read", My HR details)
 * and by the Approvals inbox task source, so the two cannot drift. Counts and dates only; never a title.
 */
final class SopsToRead
{
    /**
     * Current versions published to the person's roles, newest first.
     *
     * @return Collection<int, \stdClass>
     */
    public function versions(User $actor): Collection
    {
        $roles = SopService::humanRoles($actor);
        if ($roles === []) {
            return collect();
        }

        return DB::table('hr_sop_versions as v')->join('hr_sops as s', function ($join): void {
            $join->on('s.id', '=', 'v.sop_id')->on('s.current_version', '=', 'v.version');
        })->where('s.organisation_id', $actor->organisation_id)->where('s.is_active', true)
            ->whereExists(fn ($q) => $q->from('hr_sop_audiences as a')->whereColumn('a.sop_version_id', 'v.id')->whereIn('a.role', $roles))
            ->orderByDesc('v.created_at')->orderByDesc('v.id')->get(['v.id', 'v.public_id', 'v.sop_id', 'v.version', 'v.title', 'v.created_at']);
    }

    /**
     * The current versions the person has not acknowledged (an acknowledgement of an earlier version does not count).
     *
     * @return Collection<int, \stdClass>
     */
    public function waiting(User $actor): Collection
    {
        if (! $actor->can(SopPermissions::ACKNOWLEDGE_OWN)) {
            return collect();
        }
        $versions = $this->versions($actor);
        if ($versions->isEmpty()) {
            return $versions;
        }
        $acknowledged = DB::table('hr_sop_acknowledgements')->where('user_id', $actor->id)->whereIn('sop_version_id', $versions->pluck('id')->all())
            ->pluck('sop_version_id')->map(fn ($id): int => (int) $id)->all();

        return $versions->reject(fn (\stdClass $v): bool => in_array((int) $v->id, $acknowledged, true))->values();
    }

    public function count(User $actor): int
    {
        return $this->waiting($actor)->count();
    }

    /** When the oldest SOP still to read was published (UTC), or null. */
    public function oldest(User $actor): ?CarbonImmutable
    {
        $at = $this->waiting($actor)->min('created_at');

        return $at === null ? null : CarbonImmutable::parse((string) $at, 'UTC');
    }
}
