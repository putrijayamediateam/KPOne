<?php

namespace App\Domain\HR\Memos\Services;

use App\Domain\Access\BranchAccessService;
use App\Domain\HR\Memos\MemoPermissions;
use App\Domain\HR\Roster\RosterPermissions;
use App\Domain\HR\Roster\Services\RosterRules;
use App\Domain\HR\Sops\Services\SopService;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * HR-8 page props for /hr/memos: "My memos" for everyone, and the memo list with "N of M expected now have read" and who
 * has not read yet for Director and HR Manager. Read only. Who has not read is plain text, with no ranking. A memo's
 * status is withdrawn, replaced, closed or open, in that order.
 */
final class MemosPageService
{
    private const LIMIT = 100;

    public function __construct(private MemoAudience $audience, private BranchAccessService $branches, private MemosToRead $toRead) {}

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    public function page(User $actor, array $query): array
    {
        $canRead = $actor->can(MemoPermissions::READ_OWN);
        $canPublish = $actor->can(MemoPermissions::PUBLISH);
        $tabs = array_keys(array_filter(['my' => $canRead, 'manage' => $canPublish]));
        $tab = in_array($query['tab'] ?? null, $tabs, true) ? (string) $query['tab'] : ($tabs[0] ?? 'my');
        $zone = $this->zone($actor);

        return [
            'tab' => $tab,
            'tabs' => $tabs,
            'canPublish' => $canPublish,
            'options' => [
                'roles' => collect(RosterRules::ROLE_LABELS)->only(RosterPermissions::HUMAN_ROLES)
                    ->map(fn (string $label, string $value): array => ['value' => $value, 'label' => $label])->values()->all(),
                'branches' => DB::table('branches')->where('organisation_id', $actor->organisation_id)->where('is_active', true)->orderBy('name')->orderBy('id')->get(['id', 'name'])
                    ->map(fn (\stdClass $b): array => ['value' => (string) $b->id, 'label' => (string) $b->name])->all(),
                'withdrawReasons' => collect(MemoService::WITHDRAW_REASONS)->map(fn (string $label, string $value): array => ['value' => $value, 'label' => $label])->values()->all(),
                'maxMb' => intdiv(SopService::MAX_BYTES, 1024 * 1024),
                'maxBody' => MemoService::MAX_BODY,
                'today' => CarbonImmutable::now($zone)->toDateString(),
            ],
            'my' => $canRead && $tab === 'my' ? $this->my($actor, $zone) : null,
            'manage' => $canPublish && $tab === 'manage' ? $this->manage($actor, $zone) : null,
        ];
    }

    /** How many open memos this person still has to read, for My HR details. */
    public function toRead(User $actor): int
    {
        if (! $actor->can(MemoPermissions::READ_OWN)) {
            return 0;
        }

        return $this->toRead->count($actor);
    }

    /** @return list<array<string, mixed>> */
    private function my(User $actor, string $zone): array
    {
        $memos = $this->toRead->expected($actor);
        if ($memos->isEmpty()) {
            return [];
        }
        $ids = $memos->pluck('id')->all();
        $opens = DB::table('hr_memo_opens')->where('user_id', $actor->id)->whereIn('memo_id', $ids)->get(['memo_id', 'kind'])->groupBy('memo_id');
        $acks = DB::table('hr_memo_acknowledgements')->where('user_id', $actor->id)->whereIn('memo_id', $ids)->pluck('created_at', 'memo_id');
        $replacedBy = $this->replacements($ids);
        $freed = $this->freed($ids);
        $names = User::query()->whereIn('id', $memos->pluck('published_by_user_id')->unique()->all())->pluck('name', 'id');

        $rows = $memos->map(function (\stdClass $m) use ($opens, $acks, $replacedBy, $freed, $names, $zone): array {
            $kinds = ($opens->get($m->id) ?? collect())->pluck('kind')->all();

            return [
                'id' => (string) $m->public_id,
                'title' => (string) $m->title,
                'publishedBy' => $names[$m->published_by_user_id] ?? 'Staff member',
                'publishedOn' => $this->date($m->published_at, $zone),
                'status' => $this->status($m, $replacedBy),
                'hasPdf' => $m->pdf_path !== null,
                'opened' => in_array('memo', $kinds, true),
                'pdfOpened' => in_array('pdf', $kinds, true),
                'acknowledgedOn' => isset($acks[$m->id]) ? $this->date($acks[$m->id], $zone) : null,
                'openUntil' => $m->expires_at === null ? null : $this->date(CarbonImmutable::parse((string) $m->expires_at, 'UTC')->subSecond(), $zone),
                'replacedBy' => $replacedBy[$m->id] ?? null,
                'replacementWithdrawnOn' => isset($freed[$m->id]) && ! isset($replacedBy[$m->id]) ? $this->date($freed[$m->id], $zone) : null,
            ];
        })->values();

        // Point to the newer memo only when this reader can see it (it may have gone to a narrower audience).
        $visible = $rows->pluck('id')->all();
        $rows = $rows->map(fn (array $r): array => [...$r, 'replacedBy' => in_array($r['replacedBy'], $visible, true) ? $r['replacedBy'] : null]);

        // To read first, then read, then closed, replaced or withdrawn; newest first inside each group.
        $rank = fn (array $r): int => $r['status'] === 'open' ? ($r['acknowledgedOn'] === null ? 0 : 1) : 2;

        return array_values($rows->sortBy($rank)->all());
    }

    /** @return array<string, mixed> */
    private function manage(User $actor, string $zone): array
    {
        $memos = $this->memos($actor);
        if ($memos->isEmpty()) {
            return ['memos' => []];
        }
        $ids = $memos->pluck('id')->all();
        $people = $this->audience->people($actor->organisation_id);
        $effective = $this->audience->effective($actor->organisation_id, $people->pluck('id')->map(fn ($id): int => (int) $id)->all());
        $branchSets = DB::table('hr_memo_branches as mb')->join('branches as b', 'b.id', '=', 'mb.branch_id')->whereIn('mb.memo_id', $ids)->get(['mb.memo_id', 'mb.branch_id', 'b.name'])->groupBy('memo_id');
        $roleSets = DB::table('hr_memo_roles')->whereIn('memo_id', $ids)->get(['memo_id', 'role'])->groupBy('memo_id');
        $done = DB::table('hr_memo_acknowledgements')->whereIn('memo_id', $ids)->get(['memo_id', 'user_id'])->groupBy('memo_id');
        $replacedBy = $this->replacements($ids);
        $freed = $this->freed($ids);
        $titles = $memos->pluck('title', 'public_id');
        $replaces = $memos->filter(fn (\stdClass $m): bool => $m->replaces_memo_id !== null)->mapWithKeys(fn (\stdClass $m): array => [(int) $m->id => (string) ($memos->firstWhere('id', $m->replaces_memo_id)->title ?? '')]);
        $names = User::query()->whereIn('id', [...$memos->pluck('published_by_user_id')->all(), ...$memos->pluck('withdrawn_by_user_id')->filter()->all()])->pluck('name', 'id');

        return ['memos' => $memos->map(function (\stdClass $m) use ($people, $effective, $branchSets, $roleSets, $done, $replacedBy, $freed, $replaces, $names, $zone, $titles): array {
            $branchRows = $branchSets->get($m->id) ?? collect();
            $branchIds = array_map('intval', $branchRows->pluck('branch_id')->all());
            $roles = array_map('strval', ($roleSets->get($m->id) ?? collect())->pluck('role')->all());
            $expected = $people->filter(fn (User $u): bool => $this->audience->expects($u, $branchIds, $roles, $effective));
            $read = ($done->get($m->id) ?? collect())->pluck('user_id')->map(fn ($id): int => (int) $id)->all();
            $status = $this->status($m, $replacedBy);
            $publishedOn = $this->date($m->published_at, $zone);

            return [
                'id' => (string) $m->public_id,
                'title' => (string) $m->title,
                'status' => $status,
                'publishedOn' => $publishedOn,
                'publishedBy' => $names[$m->published_by_user_id] ?? 'Staff member',
                'hasPdf' => $m->pdf_path !== null,
                'audienceLabel' => $this->audienceLabel($branchRows->pluck('name')->sort()->values()->all(), $roles),
                'openUntil' => $m->expires_at === null ? null : $this->date(CarbonImmutable::parse((string) $m->expires_at, 'UTC')->subSecond(), $zone),
                'replacesTitle' => $replaces[(int) $m->id] ?? null,
                'replacedBy' => $replacedBy[$m->id] ?? null,
                'replacementWithdrawnOn' => isset($freed[$m->id]) && ! isset($replacedBy[$m->id]) ? $this->date($freed[$m->id], $zone) : null,
                'replacedByTitle' => isset($replacedBy[$m->id]) ? (string) ($titles[$replacedBy[$m->id]] ?? '') : null,
                'withdrawnOn' => $m->withdrawn_at === null ? null : $this->date($m->withdrawn_at, $zone),
                'withdrawnBy' => $m->withdrawn_by_user_id === null ? null : ($names[$m->withdrawn_by_user_id] ?? 'Staff member'),
                'withdrawnReason' => $m->withdrawn_reason === null ? null : (MemoService::WITHDRAW_REASONS[$m->withdrawn_reason] ?? null),
                'lockVersion' => (int) $m->lock_version,
                'read' => $expected->whereIn('id', $read)->count(),
                'expected' => $expected->count(),
                'notYet' => $status === 'withdrawn' ? [] : $expected->whereNotIn('id', $read)->values()->map(fn (User $u): array => [
                    'name' => $u->name, 'role' => $this->roleLabel($u, $roles), 'branch' => $this->primaryBranch($effective[$u->id] ?? []), 'publishedOn' => $publishedOn,
                ])->all(),
            ];
        })->all()];
    }

    /**
     * Managers get the latest 100 of all memos; readers' memos come from {@see MemosToRead::expected()}.
     *
     * @return Collection<int, \stdClass>
     */
    private function memos(User $actor): Collection
    {
        return DB::table('hr_memos')->where('organisation_id', $actor->organisation_id)->orderByDesc('published_at')->orderByDesc('id')->limit(self::LIMIT)
            ->get(['id', 'public_id', 'title', 'pdf_path', 'published_by_user_id', 'published_at', 'expires_at', 'replaces_memo_id', 'withdrawn_at',
                'withdrawn_by_user_id', 'withdrawn_reason', 'lock_version']);
    }

    /**
     * @param  array<int|string, mixed>  $ids
     * @return array<int, string> replaced memo id => public id of the memo that replaces it
     */
    private function replacements(array $ids): array
    {
        return $this->toRead->replacements($ids);
    }

    /**
     * @param  array<int|string, mixed>  $ids
     * @return array<int, string> memo id => when its latest replacement was withdrawn (UTC), for a memo that applies again
     */
    private function freed(array $ids): array
    {
        return DB::table('hr_memos as n')->join('hr_memos as o', 'o.id', '=', 'n.replaces_memo_id')->whereIn('o.id', $ids)->whereNotNull('n.withdrawn_at')
            ->selectRaw('o.id as old_id, max(n.withdrawn_at) as at')->groupBy('o.id')->pluck('at', 'old_id')->map(fn ($v): string => (string) $v)->all();
    }

    /** @param array<int, string> $replacedBy */
    private function status(\stdClass $memo, array $replacedBy): string
    {
        return MemosToRead::status($memo, $replacedBy);
    }

    /**
     * @param  array<int, mixed>  $branchNames
     * @param  array<int, string>  $roles
     */
    private function audienceLabel(array $branchNames, array $roles): string
    {
        if ($branchNames === [] && $roles === []) {
            return 'All staff';
        }
        $roleNames = array_map(fn (string $r): string => RosterRules::ROLE_LABELS[$r] ?? $r, $roles);

        return implode(' · ', array_filter([$branchNames === [] ? null : implode(', ', $branchNames), $roleNames === [] ? null : implode(', ', $roleNames)]));
    }

    /** @param array<int, string> $roles */
    private function roleLabel(User $user, array $roles): string
    {
        $held = $user->roles->pluck('name')->all();
        foreach (array_keys(RosterRules::ROLE_LABELS) as $role) {
            if (in_array($role, $held, true) && ($roles === [] || in_array($role, $roles, true))) {
                return RosterRules::ROLE_LABELS[$role];
            }
        }

        return '';
    }

    /** @param list<array{branch_id:int, name:string, primary:bool}> $assignments */
    private function primaryBranch(array $assignments): ?string
    {
        foreach ($assignments as $a) {
            if ($a['primary']) {
                return $a['name'];
            }
        }

        return $assignments[0]['name'] ?? null;
    }

    private function zone(User $actor): string
    {
        $branch = $this->branches->activeBranch($actor);
        $zone = $branch !== null ? $branch->timezone
            : DB::table('branches')->where('organisation_id', $actor->organisation_id)->where('is_active', true)->orderBy('id')->value('timezone');

        return is_string($zone) && $zone !== '' ? $zone : 'UTC';
    }

    private function date(mixed $at, string $zone): string
    {
        return CarbonImmutable::parse((string) ($at instanceof CarbonImmutable ? $at->toIso8601String() : $at), 'UTC')->setTimezone($zone)->format('j M Y');
    }
}
