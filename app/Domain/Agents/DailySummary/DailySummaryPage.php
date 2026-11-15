<?php

namespace App\Domain\Agents\DailySummary;

use App\Domain\Access\BranchAccessService;
use App\Domain\Agents\AgentPermissions;
use App\Domain\Agents\Tools\InsightsTodayTool;
use App\Domain\Organisation\Models\Branch;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * The daily summary page (AG-8, contract section 14), built from stored sections only (nothing is recomputed).
 * A viewer sees the branches they work at today, and inside each branch only what their existing permissions
 * already let them see elsewhere in KPOne:
 * - Lina's clinic and per-doctor figures: `insights.view.organisation`;
 * - Lina's money figures: `reports.daily_summary.view.branch`;
 * - Lina's Panel figures: `reports.panel_summary.view.branch`;
 * - Lina's stock alerts, Hana and Rizal: `inventory.view.branch`;
 * - Faris: `ai_agents.view.organisation`.
 */
final class DailySummaryPage
{
    public const DAYS_BACK = 31;

    public function __construct(private BranchAccessService $access) {}

    /**
     * Branches the viewer may open the summary for: active branches where they have an effective assignment today.
     *
     * @return Collection<int, Branch>
     */
    public function branchesFor(User $viewer): Collection
    {
        return $this->access->availableBranches($viewer)->where('is_active', true)
            ->filter(fn (Branch $branch): bool => $this->access->hasEffectiveAssignment($viewer, $branch))->sortBy('code')->values();
    }

    /** @return array<string, mixed> */
    public function page(User $viewer, mixed $requestedDate): array
    {
        $today = CarbonImmutable::now(InsightsTodayTool::DEFAULT_TIMEZONE)->toDateString();
        $earliest = CarbonImmutable::parse($today)->subDays(self::DAYS_BACK)->toDateString();
        $date = is_string($requestedDate) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $requestedDate) && CarbonImmutable::createFromFormat('!Y-m-d', $requestedDate)?->toDateString() === $requestedDate
            && $requestedDate <= $today && $requestedDate >= $earliest ? $requestedDate : $today;

        $can = [
            'clinic' => $viewer->can('insights.view.organisation'),
            'money' => $viewer->can('reports.daily_summary.view.branch'),
            'panel' => $viewer->can('reports.panel_summary.view.branch'),
            'stock' => $viewer->can('inventory.view.branch'),
            'faris' => $viewer->can(AgentPermissions::VIEW),
            'inbox' => $viewer->can(AgentPermissions::PROPOSALS_VIEW),
        ];
        $branches = $this->branchesFor($viewer);
        $sections = AiDailySummarySection::query()->where('organisation_id', $viewer->organisation_id)->where('local_date', $date)
            ->where(fn ($q) => $q->whereIn('branch_id', $branches->pluck('id'))->orWhereNull('branch_id'))->orderBy('id')->get();
        $find = fn (?int $branchId, string $section, string $slot): ?AiDailySummarySection => $sections->first(fn (AiDailySummarySection $row): bool => $row->branch_id === $branchId && $row->section === $section && $row->slot === $slot);
        $latest = fn (?int $branchId, string $section): ?AiDailySummarySection => $find($branchId, $section, '1400') ?? $find($branchId, $section, '0700');
        $stamp = fn (AiDailySummarySection $row): array => ['slot' => $row->slot, 'builtAt' => $row->created_at?->toIso8601String()];

        $lina = function (?AiDailySummarySection $row) use ($can, $stamp): ?array {
            if ($row === null) {
                return null;
            }
            $report = $row->payload;
            $parts = [
                'clinic' => $can['clinic'] ? $report['clinic'] : null,
                'doctors' => $can['clinic'] ? $report['doctors'] : null,
                'money' => $can['money'] ? $report['money'] : null,
                'panel' => $can['panel'] ? $report['panel'] : null,
                'stock' => $can['stock'] ? $report['stock'] : null,
            ];
            if (array_filter($parts, fn ($part): bool => $part !== null) === []) {
                return null;
            }

            return ['date' => $report['date'], 'kind' => $report['kind'], 'comparedWith' => $report['comparedWith'], ...$parts, ...$stamp($row)];
        };

        return [
            'date' => $date,
            'today' => $today,
            'earliest' => $earliest,
            'canInbox' => $can['inbox'],
            // The branch the viewer has open opens first on the page; the others start folded.
            'activeBranchCode' => $this->access->activeBranch($viewer)?->code,
            'branches' => $branches->map(fn (Branch $branch): array => [
                'code' => $branch->code,
                'name' => $branch->name,
                'linaMorning' => $lina($find($branch->id, 'lina', '0700')),
                'linaAfternoon' => $lina($find($branch->id, 'lina', '1400')),
                'hana' => $can['stock'] && ($row = $latest($branch->id, 'hana')) ? [...$row->payload, ...$stamp($row)] : null,
                'rizal' => $can['stock'] && ($row = $find($branch->id, 'rizal', '0700')) ? [...self::rizal($row->payload), ...$stamp($row)] : null,
            ])->values()->all(),
            'faris' => $can['faris'] && ($row = $latest(null, 'faris')) ? [...$row->payload, ...$stamp($row)] : null,
        ];
    }

    /**
     * Rizal's counts and the first ten items of each worklist.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private static function rizal(array $payload): array
    {
        return [
            'countsDue' => (int) ($payload['countsDue'] ?? 0),
            'openCases' => (int) ($payload['openCases'] ?? 0),
            'unresolvedVariances' => (int) ($payload['unresolvedVariances'] ?? 0),
            'due' => array_slice((array) ($payload['due'] ?? []), 0, 10),
        ];
    }
}
