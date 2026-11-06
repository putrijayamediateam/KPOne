<?php

namespace Tests\Feature\Agents;

use App\Domain\Access\BranchAccessService;
use App\Domain\Agents\Models\AiAgentRun;
use App\Domain\Agents\Services\AgentRunRecorder;
use App\Domain\Agents\Stocktake\StocktakeOfficer;
use App\Domain\Organisation\Inventory\Services\GovernedStockService;
use App\Domain\Organisation\Models\Branch;
use Carbon\CarbonImmutable;
use Database\Seeders\KPOneAgentSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Visit\VisitTestCase;
use Tests\Support\GovernedStockFixture;

/** AG-9: the stocktake officer (Rizal) reads stock counts and reports counts due, open cases and variances. */
class StocktakeOfficerTest extends VisitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(KPOneAgentSeeder::class);
    }

    private function startRun(?Branch $branch = null, string $agent = StocktakeOfficer::AGENT): AiAgentRun
    {
        return app(AgentRunRecorder::class)->start($agent, StocktakeOfficer::TASK, $branch ?? $this->branch, organisationId: $this->organisation->id);
    }

    /** @param array<string, mixed> $f */
    private function countStock(array $f, ?string $counted = null): void
    {
        $service = app(GovernedStockService::class);
        app(BranchAccessService::class)->select($f['proposer'], $f['branch']);
        $id = $service->propose($f['proposer'], 'count', ['expected_branch_id' => $f['branch']->id, 'lock_version' => 0, 'retry_key' => (string) Str::uuid(), 'balance_id' => $f['balance']->id, 'balance_version' => $f['balance']->lock_version, 'unit' => 'unit']);
        if ($counted !== null) {
            $service->act($f['proposer'], $id, 'counted', ['expected_branch_id' => $f['branch']->id, 'lock_version' => 1, 'retry_key' => (string) Str::uuid(), 'quantity' => $counted, 'unit' => 'unit']);
        }
    }

    public function test_a_count_exactly_thirty_days_old_is_not_due_and_one_a_day_older_or_never_counted_is(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-05T03:00:00Z'));
        $recent = (new GovernedStockFixture($this->branch))->build('10.000');
        $older = (new GovernedStockFixture($this->branch))->build('10.000');
        $never = (new GovernedStockFixture($this->branch))->build('10.000');
        $this->countStock($older, '10.000');
        $this->travelTo(CarbonImmutable::parse('2026-10-06T03:00:00Z'));
        $this->countStock($recent, '10.000');

        $this->travelTo(CarbonImmutable::parse('2026-11-05T03:00:00Z'));
        $result = app(StocktakeOfficer::class)->check($this->startRun(), $recent['proposer'], $this->branch);

        $this->assertSame(['CHERAS', '2026-11-05', '2026-10-06'], [$result->branch, $result->date, $result->dueBefore]);
        $this->assertSame(2, $result->countsDue);
        $this->assertSame([$never['sku']->public_id, $older['sku']->public_id], array_column($result->due, 'sku'));
        $this->assertSame([null, '2026-10-05'], array_column($result->due, 'lastCompletedCountDate'));
        $this->assertSame([0, 0, [], []], [$result->openCases, $result->unresolvedVariances, $result->open, array_filter($result->due, fn (array $row) => $row['sku'] === $recent['sku']->public_id)]);

        // One day later the count from 2026-10-06 is 31 days old and becomes due.
        $this->travelTo(CarbonImmutable::parse('2026-11-06T03:00:00Z'));
        $this->assertSame(3, app(StocktakeOfficer::class)->check($this->startRun(), $recent['proposer'], $this->branch)->countsDue);
    }

    public function test_the_window_follows_the_branch_date_not_the_utc_date(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-06T03:00:00Z'));
        $f = (new GovernedStockFixture($this->branch))->build('10.000');
        $this->countStock($f, '10.000');

        // 2026-11-05T17:00Z is 2026-11-06 01:00 in Kuala Lumpur: the count is now 31 branch days old.
        $this->travelTo(CarbonImmutable::parse('2026-11-05T17:00:00Z'));
        $result = app(StocktakeOfficer::class)->check($this->startRun(), $f['proposer'], $this->branch);

        $this->assertSame(['2026-11-06', '2026-10-07', 1], [$result->date, $result->dueBefore, $result->countsDue]);
    }

    public function test_an_approved_count_completes_the_pair_on_its_branch_local_approval_date(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-05T03:00:00Z'));
        $f = (new GovernedStockFixture($this->branch))->build('10.000');
        $fixture = new GovernedStockFixture($this->branch);
        $recounter = $fixture->actor('ca_supervisor');
        $approver = $fixture->actor('finance_officer');
        $service = app(GovernedStockService::class);
        $act = function ($actor, int $id, string $action, int $version, ?string $quantity = null) use ($service): void {
            app(BranchAccessService::class)->select($actor, $this->branch);
            $service->act($actor, $id, $action, ['expected_branch_id' => $this->branch->id, 'lock_version' => $version, 'retry_key' => (string) Str::uuid(), ...($quantity === null ? [] : ['quantity' => $quantity, 'unit' => 'unit'])]);
        };
        app(BranchAccessService::class)->select($f['proposer'], $this->branch);
        $id = $service->propose($f['proposer'], 'count', ['expected_branch_id' => $this->branch->id, 'lock_version' => 0, 'retry_key' => (string) Str::uuid(), 'balance_id' => $f['balance']->id, 'balance_version' => $f['balance']->lock_version, 'unit' => 'unit']);
        $act($f['proposer'], $id, 'counted', 1, '8.000');
        $act($recounter, $id, 'recounted', 2, '8.000');
        // 2026-10-05T16:30Z is 2026-10-06 00:30 in Kuala Lumpur.
        $this->travelTo(CarbonImmutable::parse('2026-10-05T16:30:00Z'));
        $act($approver, $id, 'approved', 3);
        $this->assertSame('posted', DB::table('inventory_governed_actions')->where('case_id', $id)->orderByDesc('lock_version')->value('state'));

        $this->travelTo(CarbonImmutable::parse('2026-11-05T03:00:00Z'));
        $result = app(StocktakeOfficer::class)->check($this->startRun(), $f['proposer'], $this->branch);
        $this->assertSame([0, 0, 0], [$result->countsDue, $result->openCases, $result->unresolvedVariances]);

        $this->travelTo(CarbonImmutable::parse('2026-11-06T03:00:00Z'));
        $result = app(StocktakeOfficer::class)->check($this->startRun(), $f['proposer'], $this->branch);
        $this->assertSame([[$f['sku']->public_id, '2026-10-06']], array_map(fn (array $row) => [$row['sku'], $row['lastCompletedCountDate']], $result->due));
    }

    public function test_open_cases_and_unresolved_variances_are_counted(): void
    {
        $open = (new GovernedStockFixture($this->branch))->build('10.000');
        $variance = (new GovernedStockFixture($this->branch))->build('10.000');
        $this->countStock($open);
        $this->countStock($variance, '8.000');

        $result = app(StocktakeOfficer::class)->check($this->startRun(), $open['proposer'], $this->branch);

        $this->assertSame([2, 1], [$result->openCases, $result->unresolvedVariances]);
        $rows = collect($result->open)->keyBy('sku');
        $this->assertSame([1, 0], [$rows[$open['sku']->public_id]['openCases'], $rows[$open['sku']->public_id]['unresolvedVariances']]);
        $this->assertSame([1, 1], [$rows[$variance['sku']->public_id]['openCases'], $rows[$variance['sku']->public_id]['unresolvedVariances']]);
        $this->assertSame(2, $result->countsDue, 'an open case is not a completed count');
    }

    public function test_another_branch_is_never_counted_and_a_requester_without_that_branch_is_refused(): void
    {
        $puchong = Branch::query()->where('organisation_id', $this->organisation->id)->where('code', 'PUCHONG')->firstOrFail();
        $cheras = (new GovernedStockFixture($this->branch))->build('10.000');
        $elsewhere = (new GovernedStockFixture($puchong))->build('10.000');
        $this->countStock($elsewhere);

        $result = app(StocktakeOfficer::class)->check($this->startRun(), $cheras['proposer'], $this->branch);
        $this->assertSame([1, 0], [$result->countsDue, $result->openCases]);
        $this->assertSame([$cheras['sku']->public_id], array_column($result->due, 'sku'));

        $atPuchong = app(StocktakeOfficer::class)->check($this->startRun($puchong), $elsewhere['proposer'], $puchong);
        $this->assertSame(['PUCHONG', 1, 1], [$atPuchong->branch, $atPuchong->countsDue, $atPuchong->openCases]);

        $this->expectException(AuthorizationException::class);
        app(StocktakeOfficer::class)->check($this->startRun($puchong), $cheras['proposer'], $puchong);
    }

    public function test_a_run_for_another_branch_or_agent_or_a_requester_without_stock_access_is_refused(): void
    {
        $f = (new GovernedStockFixture($this->branch))->build('10.000');
        $puchong = Branch::query()->where('organisation_id', $this->organisation->id)->where('code', 'PUCHONG')->firstOrFail();
        $refusals = 0;
        foreach ([
            fn () => app(StocktakeOfficer::class)->check($this->startRun($puchong), $f['proposer'], $this->branch),
            fn () => app(StocktakeOfficer::class)->check($this->startRun(agent: 'inventory_analyst'), $f['proposer'], $this->branch),
            fn () => app(StocktakeOfficer::class)->check($this->startRun(), $this->actor('marketing'), $this->branch),
        ] as $attempt) {
            try {
                $attempt();
            } catch (AuthorizationException) {
                $refusals++;
            }
        }

        $this->assertSame(3, $refusals);
        $this->assertSame(0, DB::table('ai_agent_run_steps')->count(), 'a refused check records nothing');
    }

    public function test_the_check_records_a_read_and_a_counts_only_decision_and_changes_nothing(): void
    {
        $f = (new GovernedStockFixture($this->branch))->build('10.000');
        $this->countStock($f, '8.000');
        $tables = ['inventory_governed_cases', 'inventory_governed_actions', 'inventory_stock_balances', 'stock_movements', 'ai_proposals', 'ai_agent_messages'];
        $before = array_map(fn (string $table): int => DB::table($table)->count(), $tables);
        $run = $this->startRun();

        app(StocktakeOfficer::class)->check($run, $f['proposer'], $this->branch);

        $this->assertSame($before, array_map(fn (string $table): int => DB::table($table)->count(), $tables));
        $steps = DB::table('ai_agent_run_steps')->where('ai_agent_run_id', $run->id)->orderBy('sequence')->get(['kind', 'tool_code', 'summary']);
        $this->assertSame([['data_read', 'inventory.stock_counts', 'Read inventory.stock_counts for CHERAS'], ['decision', null, 'CHERAS: 1 count due, 1 open case, 1 unresolved variance.']], $steps->map(fn ($step) => [$step->kind, $step->tool_code, $step->summary])->all());
        $this->assertSame('working', $run->refresh()->status, 'the caller finishes its own run');
    }

    public function test_empty_pairs_are_never_due_and_lists_are_capped(): void
    {
        $row = fn (int $i, ?string $last, bool $stocked, int $open = 0): array => ['sku' => "s{$i}", 'skuCode' => sprintf('C%03d', $i), 'item' => 'Synthetic', 'location' => 'l', 'locationLabel' => 'Shelf', 'lastCompletedCountDate' => $last, 'openCases' => $open, 'unresolvedVariances' => 0, 'stocked' => $stocked];
        $rows = [$row(0, null, false, 1), $row(1, '2026-01-01', false)];
        foreach (range(2, 40) as $i) {
            $rows[] = $row($i, $i % 2 === 0 ? null : '2026-09-01', true, 1);
        }

        $result = StocktakeOfficer::evaluate(['branch' => 'CHERAS', 'date' => '2026-11-05', 'rows' => $rows]);

        $this->assertSame([39, 40, 0], [$result->countsDue, $result->openCases, $result->unresolvedVariances]);
        $this->assertCount(StocktakeOfficer::LIST_LIMIT, $result->due);
        $this->assertCount(StocktakeOfficer::LIST_LIMIT, $result->open);
        $this->assertNotContains('s0', array_column($result->due, 'sku'));
        $this->assertNotContains('s1', array_column($result->due, 'sku'));
        $this->assertNull($result->due[0]['lastCompletedCountDate'], 'never counted comes first');
        $this->assertSame('s0', $result->open[0]['sku'], 'an empty pair with an open case is still an open case');
    }
}
