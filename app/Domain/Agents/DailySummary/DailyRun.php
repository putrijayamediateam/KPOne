<?php

namespace App\Domain\Agents\DailySummary;

use App\Domain\Agents\Health\SystemHealthOfficer;
use App\Domain\Agents\Inventory\InventoryAnalyst;
use App\Domain\Agents\Models\AiAgentRun;
use App\Domain\Agents\Proposals\AiProposal;
use App\Domain\Agents\Services\AgentRunRecorder;
use App\Domain\Agents\Stocktake\StocktakeOfficer;
use App\Domain\Agents\Tools\InsightsTodayTool;
use App\Domain\Organisation\Models\Branch;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

/**
 * The 07:00 and 14:00 agent runs (AG-8, contract section 14), as "system": no person's authority, only the
 * agents' allowlisted aggregate data sources, read through the gateway's system path.
 *
 * - 07:00 (full): Lina's report for yesterday (compared with the same weekday last week), Hana's four checks,
 *   Rizal's stock count check, Faris's system health.
 * - 14:00 (update): Lina's today so far, Hana's restock and expiry checks again, Faris.
 *
 * Each branch runs in its own time zone and only once its local clock has reached the slot, so the scheduler may
 * call this every hour. Each section is stored once per branch, slot and local date (Faris once per organisation);
 * a section already stored is skipped, and a failed attempt is retried on the next call, up to three runs. Only one
 * process stores a section at a time (a PostgreSQL advisory lock per section and local date); a second process
 * skips it. Nothing here changes clinic data or sends any message.
 */
final class DailyRun
{
    public const SLOT_HOURS = ['0700' => 7, '1400' => 14];

    private const ATTEMPTS = 3;

    public function __construct(
        private AgentRunRecorder $recorder,
        private ReportAssistant $lina,
        private InventoryAnalyst $hana,
        private StocktakeOfficer $rizal,
        private SystemHealthOfficer $faris,
    ) {}

    /** @return array{stored: list<string>, skipped: list<string>, failed: list<string>} */
    public function run(string $slot, ?string $branchCode = null, ?CarbonImmutable $now = null): array
    {
        if (! array_key_exists($slot, self::SLOT_HOURS)) {
            throw new InvalidArgumentException('Unknown slot.');
        }
        $now ??= CarbonImmutable::now();
        $outcome = ['stored' => [], 'skipped' => [], 'failed' => []];
        $branches = Branch::query()->where('is_active', true)->when($branchCode !== null, fn ($q) => $q->where('code', $branchCode))->orderBy('organisation_id')->orderBy('code')->get();

        foreach ($branches as $branch) {
            $local = $now->setTimezone($branch->timezone);
            if ($local->hour < self::SLOT_HOURS[$slot]) {
                $outcome['skipped'][] = "{$branch->code}: before {$slot}";

                continue;
            }
            $date = $local->toDateString();
            foreach ($this->sections($slot) as $section) {
                $this->section($outcome, $branch->organisation_id, $branch, $date, $slot, $section);
            }
        }

        foreach ($branches->pluck('organisation_id')->unique() as $organisationId) {
            $local = $now->setTimezone(InsightsTodayTool::DEFAULT_TIMEZONE);
            if ($local->hour >= self::SLOT_HOURS[$slot] && $branchCode === null) {
                $this->section($outcome, (int) $organisationId, null, $local->toDateString(), $slot, 'faris');
            }
        }

        return $outcome;
    }

    /** @return list<string> */
    private function sections(string $slot): array
    {
        return $slot === '0700' ? ['lina', 'hana', 'rizal'] : ['lina', 'hana'];
    }

    /** @param array{stored: list<string>, skipped: list<string>, failed: list<string>} $outcome */
    private function section(array &$outcome, int $organisationId, ?Branch $branch, string $date, string $slot, string $section): void
    {
        $label = ($branch->code ?? 'organisation')." {$section} {$slot} {$date}";
        // One process at a time per section and local date, across both slots (AG-12): two daily runs started at
        // the same moment could otherwise share one agent run and log a false "failed", and a late 07:00 run and
        // the 14:00 run could race on Hana's proposal keys. The lock is a PostgreSQL session lock, released below.
        $lock = self::lockKey($organisationId, $branch, $date, $section);
        if (! (bool) DB::selectOne('select pg_try_advisory_lock(hashtextextended(?, 0)) as locked', [$lock])->locked) {
            $outcome['skipped'][] = "{$label}: another run is storing this";

            return;
        }
        try {
            $this->storeSection($outcome, $organisationId, $branch, $date, $slot, $section, $label);
        } finally {
            DB::select('select pg_advisory_unlock(hashtextextended(?, 0))', [$lock]);
        }
    }

    /** The advisory lock name for one section on one local date (public so a test can hold it from another connection). */
    public static function lockKey(int $organisationId, ?Branch $branch, string $date, string $section): string
    {
        return 'ai_daily_summary:'.$organisationId.':'.($branch->id ?? 'org').":{$date}:{$section}";
    }

    /** @param array{stored: list<string>, skipped: list<string>, failed: list<string>} $outcome */
    private function storeSection(array &$outcome, int $organisationId, ?Branch $branch, string $date, string $slot, string $section, string $label): void
    {
        $exists = AiDailySummarySection::query()->where('organisation_id', $organisationId)->where('local_date', $date)->where('slot', $slot)->where('section', $section)
            ->when($branch !== null, fn ($q) => $q->where('branch_id', $branch?->id), fn ($q) => $q->whereNull('branch_id'))->exists();
        if ($exists) {
            $outcome['skipped'][] = "{$label}: already stored";

            return;
        }
        [$agent, $task] = match ($section) {
            'lina' => [ReportAssistant::AGENT, ReportAssistant::TASK],
            'hana' => [InventoryAnalyst::AGENT, InventoryAnalyst::TASK],
            'rizal' => [StocktakeOfficer::AGENT, StocktakeOfficer::TASK],
            default => [SystemHealthOfficer::AGENT, SystemHealthOfficer::TASK],
        };
        // Its own key, apart from the manual agents:inventory-review: a manual run may cover only some checks, so it
        // never stands in for a section. Hana's proposals keep their own keys, so nothing is proposed twice.
        $base = 'daily:'.$agent.':'.($branch->id ?? 'org'.$organisationId).":{$date}:{$slot}";
        for ($attempt = 1; $attempt <= self::ATTEMPTS; $attempt++) {
            try {
                $run = $this->recorder->start($agent, $task, $branch, idempotencyKey: $base.($attempt > 1 ? ":r{$attempt}" : ''), organisationId: $organisationId);
            } catch (Throwable $exception) {
                // For example a disabled agent: nothing started, so the next call simply tries again.
                report($exception);
                $outcome['failed'][] = "{$label}: not started";

                return;
            }
            if ($run->isFinished()) {
                // A failed attempt, or a run that finished without storing its section: try a fresh run.
                continue;
            }
            try {
                $payload = $this->payload($run, $branch, $date, $slot, $section);
                $this->recorder->complete($run);
                DB::transaction(fn () => AiDailySummarySection::query()->create([
                    'organisation_id' => $organisationId,
                    'branch_id' => $branch?->id,
                    'local_date' => $date,
                    'slot' => $slot,
                    'section' => $section,
                    'ai_agent_run_id' => $run->id,
                    'payload' => $payload,
                    'payload_hash' => hash('sha256', (string) json_encode($payload)),
                ]));
                $outcome['stored'][] = $label;
            } catch (Throwable $exception) {
                if (! AiAgentRun::query()->whereKey($run->id)->value('finished_at')) {
                    $this->recorder->fail($run, 'daily_run.failed');
                }
                report($exception);
                $outcome['failed'][] = $label;
            }

            return;
        }
        $outcome['failed'][] = "{$label}: no attempts left today";
    }

    /** @return array<string, mixed> */
    private function payload(AiAgentRun $run, ?Branch $branch, string $date, string $slot, string $section): array
    {
        if ($section === 'faris') {
            return $this->faris->check($run, null)->toArray();
        }
        assert($branch !== null);
        if ($section === 'lina') {
            return $slot === '0700'
                ? $this->lina->report($run, $branch, CarbonImmutable::parse($date)->subDay()->toDateString(), true)
                : $this->lina->report($run, $branch, $date, false);
        }
        if ($section === 'rizal') {
            return $this->rizal->check($run, null, $branch)->toArray();
        }
        $this->hana->review($run, $branch, $slot === '0700' ? InventoryAnalyst::ALL_CHECKS : ['restock', 'expiry']);

        return self::hanaCounts($run->organisation_id, $branch, $date);
    }

    /**
     * Hana's suggestions made today at this branch, by type: how many, and how many still wait for a decision.
     *
     * @return array<string, mixed>
     */
    public static function hanaCounts(int $organisationId, Branch $branch, string $date): array
    {
        $types = [InventoryAnalyst::RESTOCK => 'restock', InventoryAnalyst::EXPIRY => 'expiry', InventoryAnalyst::TRANSFER => 'transfer', InventoryAnalyst::SLOW => 'slowMoving'];
        $rows = AiProposal::query()->where('organisation_id', $organisationId)->where('branch_id', $branch->id)
            ->where('idempotency_key', 'like', 'hana:%:'.$branch->id.':'.$date.':%')
            ->selectRaw("type, count(*) as total, count(*) filter (where status = 'pending') as pending")->groupBy('type')->get()->keyBy('type');
        $counts = [];
        foreach ($types as $type => $key) {
            $counts[$key] = ['total' => (int) ($rows[$type]->total ?? 0), 'pending' => (int) ($rows[$type]->pending ?? 0)];
        }

        return ['date' => $date, 'counts' => $counts];
    }
}
