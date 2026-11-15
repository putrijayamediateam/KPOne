<?php

namespace App\Domain\HR\Sops\Services;

use App\Domain\HR\Roster\RosterPermissions;
use App\Domain\HR\Roster\Services\RosterRules;
use App\Domain\HR\Sops\SopPermissions;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * HR-5c page props for /hr/sops: "SOPs to read" for everyone, and the SOP list with "N of M acknowledged" and who has
 * not acknowledged yet for Director and HR Manager. Read only. Who has not acknowledged is plain text: name, role,
 * branch and the published date, with no ranking.
 */
final class SopsPageService
{
    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    public function page(User $actor, array $query): array
    {
        $canRead = $actor->can(SopPermissions::ACKNOWLEDGE_OWN);
        $canManage = $actor->can(SopPermissions::MANAGE);
        $tabs = array_keys(array_filter(['my' => $canRead, 'manage' => $canManage]));
        $tab = in_array($query['tab'] ?? null, $tabs, true) ? (string) $query['tab'] : ($tabs[0] ?? 'my');
        $zone = $this->zone($actor->organisation_id);

        return [
            'tab' => $tab,
            'tabs' => $tabs,
            'canManage' => $canManage,
            'options' => [
                'roles' => collect(RosterRules::ROLE_LABELS)->only(RosterPermissions::HUMAN_ROLES)
                    ->map(fn (string $label, string $value): array => ['value' => $value, 'label' => $label])->values()->all(),
                'maxMb' => intdiv(SopService::MAX_BYTES, 1024 * 1024),
            ],
            'my' => $canRead && $tab === 'my' ? $this->my($actor, $zone) : null,
            'manage' => $canManage && $tab === 'manage' ? $this->manage($actor, $zone) : null,
        ];
    }

    /** How many SOPs this person still has to acknowledge, for My HR details. */
    public function toRead(User $actor): int
    {
        if (! $actor->can(SopPermissions::ACKNOWLEDGE_OWN)) {
            return 0;
        }

        return collect($this->my($actor, $this->zone($actor->organisation_id)))->whereNull('acknowledgedOn')->count();
    }

    /** @return list<array<string, mixed>> */
    private function my(User $actor, string $zone): array
    {
        $roles = SopService::humanRoles($actor);
        if ($roles === []) {
            return [];
        }
        $versions = DB::table('hr_sop_versions as v')->join('hr_sops as s', function ($join): void {
            $join->on('s.id', '=', 'v.sop_id')->on('s.current_version', '=', 'v.version');
        })->where('s.organisation_id', $actor->organisation_id)->where('s.is_active', true)
            ->whereExists(fn ($q) => $q->from('hr_sop_audiences as a')->whereColumn('a.sop_version_id', 'v.id')->whereIn('a.role', $roles))
            ->orderByDesc('v.created_at')->orderByDesc('v.id')->get(['v.id', 'v.public_id', 'v.sop_id', 'v.version', 'v.title', 'v.created_at']);
        if ($versions->isEmpty()) {
            return [];
        }
        $versionIds = $versions->pluck('id')->all();
        $opened = DB::table('hr_sop_opens')->where('user_id', $actor->id)->whereIn('sop_version_id', $versionIds)->distinct()->pluck('sop_version_id')->map(fn ($id): int => (int) $id)->all();
        $acks = DB::table('hr_sop_acknowledgements as k')->join('hr_sop_versions as v', 'v.id', '=', 'k.sop_version_id')
            ->where('k.user_id', $actor->id)->whereIn('v.sop_id', $versions->pluck('sop_id')->all())
            ->orderBy('v.version')->get(['v.sop_id', 'v.version', 'k.sop_version_id', 'k.created_at']);

        $rows = $versions->map(function (object $v) use ($opened, $acks, $zone): array {
            $mine = $acks->where('sop_id', $v->sop_id);
            $current = $mine->firstWhere('sop_version_id', $v->id);
            $earlier = $mine->where('version', '<', $v->version)->last();

            return [
                'versionId' => (string) $v->public_id,
                'title' => (string) $v->title,
                'version' => (int) $v->version,
                'publishedOn' => $this->date($v->created_at, $zone),
                'opened' => in_array((int) $v->id, $opened, true),
                'acknowledgedOn' => $current === null ? null : $this->date($current->created_at, $zone),
                'earlier' => $earlier === null ? null : ['version' => (int) $earlier->version, 'on' => $this->date($earlier->created_at, $zone)],
            ];
        });

        // To read first, newest first; then what is done.
        return array_values($rows->sortBy(fn (array $r): int => $r['acknowledgedOn'] === null ? 0 : 1)->all());
    }

    /** @return array<string, mixed> */
    private function manage(User $actor, string $zone): array
    {
        $sops = DB::table('hr_sops')->where('organisation_id', $actor->organisation_id)->orderByDesc('is_active')->orderByDesc('updated_at')->orderByDesc('id')->get();
        if ($sops->isEmpty()) {
            return ['sops' => []];
        }
        $versions = DB::table('hr_sop_versions')->whereIn('sop_id', $sops->pluck('id')->all())->orderByDesc('version')->get();
        $audiences = DB::table('hr_sop_audiences')->whereIn('sop_version_id', $versions->pluck('id')->all())->get()->groupBy('sop_version_id');
        $currentIds = $sops->map(fn (object $s) => $versions->first(fn (object $v): bool => (int) $v->sop_id === (int) $s->id && (int) $v->version === (int) $s->current_version)?->id)->filter()->all();
        $acks = DB::table('hr_sop_acknowledgements')->whereIn('sop_version_id', $currentIds)->get(['sop_version_id', 'user_id'])->groupBy('sop_version_id');
        $people = $this->people($actor);
        $branches = $this->primaryBranches($people->pluck('id')->all(), CarbonImmutable::now($zone)->toDateString());
        $names = User::query()->whereIn('id', [...$versions->pluck('published_by_user_id')->all(), ...$sops->pluck('withdrawn_by_user_id')->filter()->all()])->pluck('name', 'id');

        return ['sops' => $sops->map(function (object $s) use ($versions, $audiences, $acks, $people, $branches, $names, $zone): array {
            $mine = $versions->where('sop_id', $s->id)->values();
            $current = $mine->firstWhere('version', $s->current_version);
            $roles = $current === null ? [] : array_map('strval', $audiences->get($current->id, collect())->pluck('role')->all());
            $audience = $people->filter(fn (User $u): bool => array_intersect(SopService::humanRoles($u), $roles) !== []);
            $done = $current === null ? [] : $acks->get($current->id, collect())->pluck('user_id')->map(fn ($id): int => (int) $id)->all();
            $publishedOn = $current === null ? null : $this->date($current->created_at, $zone);

            return [
                'id' => (string) $s->public_id,
                'title' => $current === null ? '' : (string) $current->title,
                'version' => (int) $s->current_version,
                'versionId' => $current === null ? null : (string) $current->public_id,
                'roles' => array_values(array_map(fn (string $r): string => RosterRules::ROLE_LABELS[$r] ?? $r, $roles)),
                'roleValues' => $roles,
                'publishedOn' => $publishedOn,
                'isActive' => (bool) $s->is_active,
                'withdrawnOn' => $s->withdrawn_at === null ? null : $this->date($s->withdrawn_at, $zone),
                'withdrawnBy' => $s->withdrawn_by_user_id === null ? null : ($names[$s->withdrawn_by_user_id] ?? 'Staff member'),
                'lockVersion' => (int) $s->lock_version,
                'acknowledged' => $audience->whereIn('id', $done)->count(),
                'audience' => $audience->count(),
                'notYet' => $s->is_active ? $audience->whereNotIn('id', $done)->sortBy('name')->values()->map(fn (User $u): array => [
                    'name' => $u->name, 'role' => $this->roleLabel($u, $roles), 'branch' => $branches[$u->id] ?? null, 'publishedOn' => $publishedOn,
                ])->all() : [],
                'versions' => $mine->map(fn (object $v): array => ['versionId' => (string) $v->public_id, 'version' => (int) $v->version, 'title' => (string) $v->title,
                    'publishedOn' => $this->date($v->created_at, $zone), 'publishedBy' => $names[$v->published_by_user_id] ?? 'Staff member',
                    'roles' => array_values(array_map(fn (string $r): string => RosterRules::ROLE_LABELS[$r] ?? $r, $audiences->get($v->id, collect())->pluck('role')->all()))])->all(),
            ];
        })->all()];
    }

    /** @return Collection<int, User> active human staff of the organisation, roles loaded */
    private function people(User $actor): Collection
    {
        return User::query()->with('roles')->where('organisation_id', $actor->organisation_id)->where('is_active', true)
            ->whereHas('roles', fn ($q) => $q->whereIn('name', RosterPermissions::HUMAN_ROLES))->orderBy('name')->get();
    }

    /** @param array<string> $roles the role among the SOP's that the person holds, first by the app's order */
    private function roleLabel(User $user, array $roles): string
    {
        $held = $user->roles->pluck('name')->all();
        foreach (array_keys(RosterRules::ROLE_LABELS) as $role) {
            if (in_array($role, $held, true) && in_array($role, $roles, true)) {
                return RosterRules::ROLE_LABELS[$role];
            }
        }

        return '';
    }

    /**
     * @param  array<int, mixed>  $userIds
     * @return array<int, string>
     */
    private function primaryBranches(array $userIds, string $today): array
    {
        if ($userIds === []) {
            return [];
        }
        $out = [];
        DB::table('staff_branch_assignments as a')->join('staff_profiles as p', 'p.id', '=', 'a.staff_profile_id')->join('branches as b', 'b.id', '=', 'a.branch_id')
            ->whereIn('p.user_id', $userIds)->where('a.is_primary', true)->whereDate('a.valid_from', '<=', $today)
            ->where(fn ($q) => $q->whereNull('a.valid_until')->orWhereDate('a.valid_until', '>=', $today))
            ->orderBy('a.valid_from')->get(['p.user_id', 'b.name'])
            ->each(function (object $r) use (&$out): void {
                $out[(int) $r->user_id] = (string) $r->name;
            });

        return $out;
    }

    private function zone(int $organisationId): string
    {
        $zone = DB::table('branches')->where('organisation_id', $organisationId)->where('is_active', true)->orderBy('id')->value('timezone');

        return is_string($zone) ? $zone : 'UTC';
    }

    private function date(mixed $at, string $zone): string
    {
        return CarbonImmutable::parse((string) $at, 'UTC')->setTimezone($zone)->format('j M Y');
    }
}
