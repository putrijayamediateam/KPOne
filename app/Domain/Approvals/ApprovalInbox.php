<?php

namespace App\Domain\Approvals;

use App\Domain\Access\BranchAccessService;
use App\Domain\Organisation\Models\Branch;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/**
 * APPR-1: reads the registered sources for one person. It decides nothing and writes nothing.
 *
 * Access is never cached: every call re-checks the person's permissions and effective branch assignment. Only the raw
 * per-source counts are cached, for 60 seconds, under a key that carries the user id and the branch id (the exclusion of the
 * person's own items differs per person, so a count is never shared). A person's own decision clears their counts
 * ({@see forget()}); any other staleness lasts at most 60 seconds and a count never grants or keeps access.
 */
final class ApprovalInbox
{
    public const CACHE_SECONDS = 60;

    public const ITEM_LIMIT = 5;

    public function __construct(private ApprovalSources $sources, private BranchAccessService $branches) {}

    /**
     * The number, for the account button and the menu rows. `showNumber` is true only for people who hold at least one
     * approval decide permission; everyone else sees their "to read" tasks on My day instead.
     *
     * @return array{showNumber: bool, total: int, areas: list<array{area: string, kind: string, count: int}>}
     */
    public function summary(User $actor): array
    {
        $branch = $this->branches->activeBranch($actor);
        $counts = $this->counts($actor, $branch);
        $areas = [];
        $total = 0;
        foreach ($this->sources->visibleTo($actor, $branch) as $source) {
            $count = $counts[$source->key()] ?? 0;
            if ($count < 1) {
                continue;
            }
            $total += $count;
            $slot = $source->kind().'|'.$source->area();
            $areas[$slot] ??= ['area' => $source->area(), 'kind' => $source->kind(), 'count' => 0];
            $areas[$slot]['count'] += $count;
        }

        return ['showNumber' => $actor->hasAnyPermission($this->sources->approvalPermissions()), 'total' => $total, 'areas' => array_values($areas)];
    }

    /**
     * The inbox page: Approvals first, then Tasks; a source with nothing waiting is left out; a source whose items carry a
     * document number lists up to five of them. The "waiting at other branches" lines count only branches where the person
     * has an effective assignment and the source's permission (computed here, never in the number).
     *
     * @return array{branch: ?array{id: int, name: string}, sections: list<array{kind: string, areas: list<array<string, mixed>>}>, otherBranches: list<array{area: string, branches: list<array{name: string, count: int}>}>}
     */
    public function page(User $actor): array
    {
        $branch = $this->branches->activeBranch($actor);
        $now = CarbonImmutable::now('UTC');
        $counts = $this->counts($actor, $branch);
        $sections = [];
        foreach ([ApprovalSource::APPROVAL, ApprovalSource::TASK] as $kind) {
            $areas = [];
            foreach ($this->sources->visibleTo($actor, $branch) as $source) {
                $count = $counts[$source->key()] ?? 0;
                if ($source->kind() !== $kind || $count < 1) {
                    continue;
                }
                $oldest = $source->oldest($actor, $branch);
                $areas[$source->area()]['area'] = $source->area();
                $areas[$source->area()]['sources'][] = [
                    'key' => $source->key(),
                    'label' => $source->label(),
                    'count' => $count,
                    'oldest' => $oldest === null ? null : 'oldest '.AgeWords::days($oldest, $now),
                    'href' => route($source->route(), $source->routeParameters(), absolute: false),
                    'items' => $source->hasReferences() ? array_map(fn (ApprovalItem $item): array => $item->toArray($now), $source->items($actor, $branch, self::ITEM_LIMIT)) : [],
                ];
            }
            if ($areas !== []) {
                $sections[] = ['kind' => $kind, 'areas' => array_values($areas)];
            }
        }

        return ['branch' => $branch === null ? null : ['id' => $branch->id, 'name' => $branch->name], 'sections' => $sections, 'otherBranches' => $this->otherBranches($actor, $branch)];
    }

    /** Clears this person's cached counts (called after the person decides something). */
    public static function forget(User $actor): void
    {
        $key = 'approvals:v:'.$actor->id;
        Cache::put($key, ((int) Cache::get($key, 1)) + 1, 86400 * 7);
    }

    /** @return array<string, int> raw count per visible source key */
    private function counts(User $actor, ?Branch $branch): array
    {
        $key = 'approvals:'.$actor->id.':'.((int) Cache::get('approvals:v:'.$actor->id, 1)).':'.($branch === null ? 0 : $branch->id);
        /** @var array<string, int> $cached */
        $cached = Cache::get($key, []);
        $changed = false;
        foreach ($this->sources->visibleTo($actor, $branch) as $source) {
            if (! array_key_exists($source->key(), $cached)) {
                $cached[$source->key()] = $source->count($actor, $branch);
                $changed = true;
            }
        }
        if ($changed) {
            Cache::put($key, $cached, self::CACHE_SECONDS);
        }

        return $cached;
    }

    /** @return list<array{area: string, branches: list<array{name: string, count: int}>}> */
    private function otherBranches(User $actor, ?Branch $active): array
    {
        $others = $this->branches->availableBranches($actor)->reject(fn (Branch $branch): bool => $active !== null && $branch->id === $active->id);
        $lines = [];
        foreach ($others as $other) {
            if (! $this->branches->hasEffectiveAssignment($actor, $other)) {
                continue;
            }
            foreach ($this->sources->visibleTo($actor, $other) as $source) {
                if (! $source->branchScoped()) {
                    continue;
                }
                $count = $source->count($actor, $other);
                if ($count > 0) {
                    $lines[$source->area()]['area'] = $source->area();
                    $lines[$source->area()]['branches'][$other->name] = ($lines[$source->area()]['branches'][$other->name] ?? 0) + $count;
                }
            }
        }

        return array_values(array_map(fn (array $line): array => [
            'area' => $line['area'],
            'branches' => array_map(fn (string $name, int $count): array => ['name' => $name, 'count' => $count], array_keys($line['branches']), array_values($line['branches'])),
        ], $lines));
    }
}
