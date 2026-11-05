<?php

namespace Tests\Feature\Agents;

use App\Domain\Access\BranchAccessService;
use App\Domain\Agents\Models\AiAgent;
use App\Domain\Agents\Models\AiAgentTool;
use App\Domain\Agents\Services\AgentRunRecorder;
use App\Domain\Agents\Tools\AgentToolGateway;
use App\Domain\Clinical\Dispensary\Services\DispensaryService;
use App\Domain\Organisation\Inventory\Services\GovernedStockService;
use App\Domain\Organisation\Inventory\Services\InventoryStockReviewService;
use App\Domain\Queue\Services\QueueEntryService;
use Carbon\CarbonImmutable;
use Database\Seeders\KPOneAgentSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Queue\QueueTestCase;
use Tests\Support\GovernedStockFixture;
use Tests\Support\OtcDispensaryFixtures;

/** AG-4: the figures each data source returns, against the screens and rules they mirror. */
class AgentDataSourcesTest extends QueueTestCase
{
    use OtcDispensaryFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(KPOneAgentSeeder::class);
        $agent = new AiAgent;
        $agent->forceFill(['organisation_id' => $this->organisation->id, 'code' => 'test_reader', 'display_name' => 'Test', 'job_title' => 'Test reader (AI agent)', 'kind' => 'runtime'])->save();
        foreach (['insights.today', 'insights.sales_summary', 'inventory.stock_review', 'inventory.branch_balances', 'inventory.dispensing_activity', 'inventory.stock_counts', 'clinic.day_summary', 'money.day_summary', 'panel.claims_summary'] as $code) {
            AiAgentTool::query()->create(['ai_agent_id' => $agent->id, 'tool_code' => $code]);
        }
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    private function read(string $code, $actor, array $input = [], bool $withBranch = true): array
    {
        $run = app(AgentRunRecorder::class)->start('test_reader', 'test_read', $withBranch ? $this->branch : null, organisationId: $this->organisation->id);

        return app(AgentToolGateway::class)->call($run, $code, $actor, $withBranch ? $this->branch : null, $input);
    }

    public function test_stock_review_totals_equal_the_stock_review_page(): void
    {
        $f = (new GovernedStockFixture($this->branch))->build('10.000');
        DB::table('inventory_reorder_levels')->insert(['organisation_id' => $this->organisation->id, 'inventory_location_id' => $f['location']->id, 'inventory_sku_id' => $f['sku']->id, 'reorder_level' => '20.000', 'updated_by_user_id' => $f['proposer']->id, 'created_at' => now(), 'updated_at' => now()]);
        $supervisor = $f['proposer'];
        $this->selectBranch($supervisor);

        $page = app(InventoryStockReviewService::class)->page($supervisor, []);
        $tool = $this->read('inventory.stock_review', $supervisor);

        $this->assertSame([
            'lowStock' => $page['lists']['low_stock']['total'],
            'expiring' => $page['lists']['expiring']['total'],
            'expired' => $page['lists']['expired']['total'],
        ], $tool['totals']);
        $this->assertSame(1, $tool['totals']['lowStock']);
        $this->assertSame(1, $tool['totals']['expiring']);
        $sku = collect($tool['skus'])->firstWhere('sku', $f['sku']->public_id);
        $this->assertSame([1, 1, '10.000', 0], [$sku['lowStockLocations'], $sku['expiringBatches'], $sku['expiringQuantity'], $sku['expiredBatches']]);
    }

    public function test_branch_balances_cover_every_branch_with_on_hand_eligible_and_reorder_level(): void
    {
        $f = (new GovernedStockFixture($this->branch))->build('10.000');
        DB::table('inventory_reorder_levels')->insert(['organisation_id' => $this->organisation->id, 'inventory_location_id' => $f['location']->id, 'inventory_sku_id' => $f['sku']->id, 'reorder_level' => '4.500', 'updated_by_user_id' => $f['proposer']->id, 'created_at' => now(), 'updated_at' => now()]);

        $tool = $this->read('inventory.branch_balances', $f['proposer']);

        $this->assertSame(['CHERAS', 'PUCHONG', 'SUNGAI_BESI'], $tool['branches']);
        $sku = collect($tool['skus'])->firstWhere('sku', $f['sku']->public_id);
        $this->assertSame(['branch' => 'CHERAS', 'onHand' => '10.000', 'eligible' => '10.000', 'reorderLevel' => '4.500'], $sku['perBranch'][0]);
        $this->assertSame(['branch' => 'PUCHONG', 'onHand' => '0.000', 'eligible' => '0.000', 'reorderLevel' => '0.000'], $sku['perBranch'][1]);
    }

    public function test_dispensing_activity_counts_real_dispensing_inside_the_window(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-05T03:00:00Z'));
        $f = $this->fixture();
        $case = $this->open($f);
        app(DispensaryService::class)->addItem($f['ca'], $case, $this->addPayload($f, $case, '3.000'));
        $this->confirmAllergy($f, $case);
        $this->complete($f, $case);
        $supervisor = $this->actor('ca_supervisor');

        $sku = collect($this->read('inventory.dispensing_activity', $supervisor)['skus'])->firstWhere('sku', $f['sku']->public_id);
        $this->assertSame(['7.000', '2026-11-05', 0, '3.000'], [$sku['onHand'], $sku['lastDispensedDate'], $sku['daysSinceDispensed'], $sku['dispensedInWindow']]);

        $this->travelTo(CarbonImmutable::parse('2027-01-05T03:00:00Z'));
        $later = $this->read('inventory.dispensing_activity', $supervisor, ['window_days' => 60]);
        $sku = collect($later['skus'])->firstWhere('sku', $f['sku']->public_id);
        $this->assertSame(['2026-11-05', 61, '0.000'], [$sku['lastDispensedDate'], $sku['daysSinceDispensed'], $sku['dispensedInWindow']]);
        $this->assertSame('2026-11-07', $later['windowStart']);
    }

    public function test_stock_counts_show_open_cases_and_uncounted_stock(): void
    {
        $f = (new GovernedStockFixture($this->branch))->build('10.000');
        $other = (new GovernedStockFixture($this->branch))->build('5.000');
        app(BranchAccessService::class)->select($f['proposer'], $this->branch);
        app(GovernedStockService::class)->propose($f['proposer'], 'count', ['expected_branch_id' => $this->branch->id, 'lock_version' => 0, 'retry_key' => (string) Str::uuid(), 'balance_id' => $f['balance']->id, 'balance_version' => $f['balance']->lock_version, 'unit' => 'unit']);

        $tool = $this->read('inventory.stock_counts', $f['proposer']);
        $rows = collect($tool['rows'])->keyBy('sku');

        $this->assertSame([1, 0, null], [$rows[$f['sku']->public_id]['openCases'], $rows[$f['sku']->public_id]['unresolvedVariances'], $rows[$f['sku']->public_id]['lastCompletedCountDate']]);
        $this->assertSame([0, 0, null], [$rows[$other['sku']->public_id]['openCases'], $rows[$other['sku']->public_id]['unresolvedVariances'], $rows[$other['sku']->public_id]['lastCompletedCountDate']]);
        $this->assertSame(['openCases' => 1, 'unresolvedVariances' => 0], $tool['totals']);
    }

    public function test_stock_counts_map_a_matching_count_to_completed_and_a_difference_to_unresolved_variance(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-05T03:00:00Z'));
        $matching = (new GovernedStockFixture($this->branch))->build('10.000');
        $different = (new GovernedStockFixture($this->branch))->build('10.000');
        $service = app(GovernedStockService::class);
        foreach ([[$matching, '10.000'], [$different, '8.000']] as [$f, $counted]) {
            app(BranchAccessService::class)->select($f['proposer'], $this->branch);
            $id = $service->propose($f['proposer'], 'count', ['expected_branch_id' => $this->branch->id, 'lock_version' => 0, 'retry_key' => (string) Str::uuid(), 'balance_id' => $f['balance']->id, 'balance_version' => $f['balance']->lock_version, 'unit' => 'unit']);
            $service->act($f['proposer'], $id, 'counted', ['expected_branch_id' => $this->branch->id, 'lock_version' => 1, 'retry_key' => (string) Str::uuid(), 'quantity' => $counted, 'unit' => 'unit']);
        }
        $rows = collect($this->read('inventory.stock_counts', $matching['proposer'])['rows'])->keyBy('sku');

        $this->assertSame(['closed_zero', 'awaiting_recount'], DB::table('inventory_governed_actions')->orderBy('id')->whereIn('lock_version', [2])->pluck('state')->all());
        $this->assertSame(['2026-11-05', 0, 0], [$rows[$matching['sku']->public_id]['lastCompletedCountDate'], $rows[$matching['sku']->public_id]['openCases'], $rows[$matching['sku']->public_id]['unresolvedVariances']]);
        $this->assertSame([null, 1, 1], [$rows[$different['sku']->public_id]['lastCompletedCountDate'], $rows[$different['sku']->public_id]['openCases'], $rows[$different['sku']->public_id]['unresolvedVariances']]);
    }

    public function test_clinic_day_summary_counts_one_registered_cohort_per_doctor(): void
    {
        $ca = $this->actor('ca');
        $doctor = $this->doctor();
        $called = $this->consultationVisit($ca, $doctor);
        $queue = $this->send($ca, $called);
        $this->send($ca, $this->consultationVisit($ca, $doctor));
        $this->selectBranch($doctor);
        app(QueueEntryService::class)->call($doctor, $called->refresh(), ['expected_branch_id' => $this->branch->id, 'visit_lock_version' => $called->lock_version, 'queue_lock_version' => $queue->refresh()->lock_version]);

        $branch = $this->read('clinic.day_summary', $this->actor('director'))['branches'][0];

        $this->assertSame([2, 1, 1], [$branch['registered'], $branch['served'], $branch['waitingNow']]);
        $this->assertSame([['doctorId' => $doctor->id, 'doctor' => $doctor->name, 'seen' => 1]], array_map(fn (array $row) => array_diff_key($row, ['averageConsultationMinutes' => 0]), $branch['doctors']));
        $yesterday = CarbonImmutable::now($this->branch->timezone)->subDay()->toDateString();
        $this->assertNull($this->read('clinic.day_summary', $this->actor('director'), ['date' => $yesterday])['branches'][0]['waitingNow'], 'waiting now is for today only');
    }

    public function test_clinic_money_panel_and_insights_summaries_count_a_registered_visit_and_stay_zero_without_money(): void
    {
        $director = $this->actor('director');
        $this->register($this->actor('ca'), $this->patient($director));

        $clinic = $this->read('clinic.day_summary', $director);
        $this->assertSame('CHERAS', $clinic['branches'][0]['branch']);
        $this->assertSame(1, $clinic['branches'][0]['registered']);
        $this->assertSame([0, 0, []], [$clinic['branches'][0]['served'], $clinic['branches'][0]['averageWaitMinutes'], $clinic['branches'][0]['doctors']]);
        $this->assertCount(3, $this->read('clinic.day_summary', $director, [], false)['branches']);

        $money = $this->read('money.day_summary', $director)['branches'][0];
        $this->assertSame([0, 0, 0, 0, 0, []], [$money['netSalesSen'], $money['cashNetSen'], $money['nonCashNetSen'], $money['totalReceivedSen'], $money['payLaterDueSen'], $money['receiptsByMethod']]);

        $panel = $this->read('panel.claims_summary', $director)['branches'][0];
        $this->assertSame(0, array_sum($panel['totals']));
        $this->assertSame([], $panel['panels']);

        $today = $this->read('insights.today', $director);
        $this->assertSame(0, $today['sales']['netSen']);
        $sales = $this->read('insights.sales_summary', $director, ['from' => '2026-11-01', 'to' => '2026-11-04']);
        $this->assertSame(['2026-11-01', '2026-11-04', 0], [$sales['from'], $sales['to'], $sales['netSen']]);
    }
}
