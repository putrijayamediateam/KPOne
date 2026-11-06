<?php

namespace Tests\Feature\Agents;

use App\Domain\Agents\Inventory\InventoryAnalyst;
use App\Domain\Agents\Models\AiAgent;
use App\Domain\Agents\Models\AiAgentRun;
use App\Domain\Agents\Models\AiAgentTool;
use App\Domain\Agents\Proposals\AiProposal;
use App\Domain\Agents\Services\AgentRunRecorder;
use App\Domain\Agents\Tools\AgentToolGateway;
use App\Domain\Organisation\Inventory\Models\InventoryLocation;
use App\Domain\Organisation\Inventory\Services\InventoryMovementService;
use App\Domain\Organisation\Models\Branch;
use Carbon\CarbonImmutable;
use Database\Seeders\KPOneAgentSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use ReflectionProperty;
use Tests\Feature\Queue\QueueTestCase;
use Tests\Support\GovernedStockFixture;

/** AG-6: Hana reads the inventory data sources as a scheduled run and proposes by the fixed rules, once a day. */
class InventoryAnalystTest extends QueueTestCase
{
    /** @var array<string, mixed> */
    private array $f;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-11-06T02:00:00Z'));
        $this->seed(KPOneAgentSeeder::class);
        $this->f = (new GovernedStockFixture($this->branch))->build('10.000');
        $this->level($this->f['location'], '20.000');
    }

    private function level(InventoryLocation $location, string $level): void
    {
        DB::table('inventory_reorder_levels')->insert(['organisation_id' => $this->organisation->id, 'inventory_location_id' => $location->id, 'inventory_sku_id' => $this->f['sku']->id, 'reorder_level' => $level, 'updated_by_user_id' => $this->f['proposer']->id, 'created_at' => now(), 'updated_at' => now()]);
    }

    /** @return array<string, list<array<string, mixed>>> */
    private function proposals(): array
    {
        return AiProposal::query()->where('organisation_id', $this->organisation->id)->orderBy('id')->get()
            ->groupBy('type')->map(fn ($rows): array => $rows->map(fn (AiProposal $p): array => $p->payload)->values()->all())->all();
    }

    private function puchong(): Branch
    {
        return Branch::query()->where('organisation_id', $this->organisation->id)->where('code', 'PUCHONG')->firstOrFail();
    }

    /** Stock of the same SKU and batch at another branch, with its own reorder level. */
    private function stockAt(Branch $branch, string $quantity, string $level): void
    {
        $actor = (new GovernedStockFixture($branch))->actor('ca_supervisor');
        $this->selectBranch($actor, $branch);
        $location = new InventoryLocation;
        $location->forceFill(['public_id' => (string) Str::uuid(), 'organisation_id' => $this->organisation->id, 'branch_id' => $branch->id, 'code' => 'SYN-'.Str::upper(Str::random(8)), 'name' => 'Synthetic other dispensary', 'type' => 'dispensary', 'is_active' => true])->save();
        app(InventoryMovementService::class)->openingBalance($actor, ['expected_branch_id' => $branch->id, 'sku_public_id' => $this->f['sku']->public_id, 'location_public_id' => $location->public_id, 'batch_public_id' => $this->f['batch']->public_id, 'quantity' => $quantity]);
        $this->level($location, $level);
    }

    private function systemRun(?Branch $branch = null): AiAgentRun
    {
        return app(AgentRunRecorder::class)->start(InventoryAnalyst::AGENT, InventoryAnalyst::TASK, $branch ?? $this->branch);
    }

    public function test_the_morning_run_proposes_restock_and_expiry_through_the_inbox(): void
    {
        $this->artisan('agents:inventory-review', ['branch' => 'CHERAS'])
            ->expectsOutputToContain('CHERAS: 1 restock, 1 expiry, 0 transfer, 0 slow-moving suggestions')
            ->assertSuccessful();

        $proposals = $this->proposals();
        $this->assertSame([InventoryAnalyst::RESTOCK, InventoryAnalyst::EXPIRY], array_keys($proposals));
        $this->assertPayload([
            'sku_public_id' => $this->f['sku']->public_id, 'location_public_id' => $this->f['location']->public_id, 'current_quantity' => '10.000',
            'reorder_level' => '20.000', 'suggested_quantity' => '10.000', 'reason_code' => 'at_or_below_reorder_level',
        ], $proposals[InventoryAnalyst::RESTOCK][0]);
        $this->assertPayload([
            'sku_public_id' => $this->f['sku']->public_id, 'batch_public_id' => $this->f['batch']->public_id, 'location_public_id' => $this->f['location']->public_id,
            'quantity' => '10.000', 'expiry_date' => '2027-02-04', 'days_left' => 90, 'suggested_action' => 'use_first',
        ], $proposals[InventoryAnalyst::EXPIRY][0]);

        $run = AiAgentRun::query()->sole();
        $this->assertSame(['completed', null, $this->branch->id], [$run->status, $run->requested_by_user_id, $run->branch_id]);
        $this->assertSame(
            ['data_read', 'data_read', 'data_read', 'decision', 'decision', 'decision'],
            DB::table('ai_agent_run_steps')->where('ai_agent_run_id', $run->id)->orderBy('id')->pluck('kind')->all(),
        );
        $this->assertSame(['pending', 'pending'], AiProposal::query()->pluck('status')->all());
        // Hana changes no stock.
        $this->assertSame('10.000', DB::table('inventory_stock_balances')->where('inventory_location_id', $this->f['location']->id)->value('quantity'));
    }

    public function test_the_same_slot_runs_once_and_the_afternoon_update_repeats_nothing(): void
    {
        $this->artisan('agents:inventory-review', ['branch' => 'CHERAS'])->assertSuccessful();
        $this->artisan('agents:inventory-review', ['branch' => 'CHERAS'])->expectsOutputToContain('already ran')->assertSuccessful();
        $this->assertSame(1, AiAgentRun::query()->count());

        $this->travelTo(CarbonImmutable::parse('2026-11-06T06:00:00Z'));
        $this->artisan('agents:inventory-review', ['branch' => 'CHERAS', '--slot' => '1400', '--checks' => 'restock,expiry'])
            ->expectsOutputToContain('CHERAS: 1 restock, 1 expiry, 0 transfer, 0 slow-moving suggestions (2 already proposed today).')
            ->assertSuccessful();
        $this->assertSame(2, AiAgentRun::query()->count());
        $this->assertSame(2, AiProposal::query()->count());

        // A new local day proposes again.
        $this->travelTo(CarbonImmutable::parse('2026-11-07T01:00:00Z'));
        $this->artisan('agents:inventory-review', ['branch' => 'CHERAS'])->assertSuccessful();
        $this->assertSame(4, AiProposal::query()->count());
    }

    public function test_transfer_comes_first_and_restock_covers_the_rest(): void
    {
        $this->stockAt($this->puchong(), '15.000', '8.000');

        $this->artisan('agents:inventory-review', ['branch' => 'CHERAS', '--checks' => 'transfer,restock'])->assertSuccessful();

        $proposals = $this->proposals();
        $this->assertPayload([
            'sku_public_id' => $this->f['sku']->public_id, 'from_branch_code' => 'PUCHONG', 'to_branch_code' => 'CHERAS', 'quantity' => '7.000',
            'from_current_quantity' => '15.000', 'from_reorder_level' => '8.000', 'to_current_quantity' => '10.000', 'to_reorder_level' => '20.000',
        ], $proposals[InventoryAnalyst::TRANSFER][0]);
        $this->assertSame('3.000', $proposals[InventoryAnalyst::RESTOCK][0]['suggested_quantity']);
        $this->assertArrayNotHasKey(InventoryAnalyst::EXPIRY, $proposals);
    }

    public function test_a_sender_at_its_reorder_level_sends_nothing(): void
    {
        $this->stockAt($this->puchong(), '8.000', '8.000');

        $this->artisan('agents:inventory-review', ['branch' => 'CHERAS', '--checks' => 'transfer,restock'])->assertSuccessful();

        $proposals = $this->proposals();
        $this->assertArrayNotHasKey(InventoryAnalyst::TRANSFER, $proposals);
        $this->assertSame('10.000', $proposals[InventoryAnalyst::RESTOCK][0]['suggested_quantity']);
    }

    public function test_slow_moving_starts_at_60_days_without_dispensing(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-06T02:00:00Z')->addDays(59));
        $this->artisan('agents:inventory-review', ['branch' => 'CHERAS', '--checks' => 'slow_moving'])->assertSuccessful();
        $this->assertSame([], $this->proposals());

        $this->travelTo(CarbonImmutable::parse('2026-11-06T02:00:00Z')->addDays(60));
        $this->artisan('agents:inventory-review', ['branch' => 'CHERAS', '--checks' => 'slow_moving,expiry'])->assertSuccessful();
        $proposals = $this->proposals();
        $this->assertPayload([
            'sku_public_id' => $this->f['sku']->public_id, 'location_public_id' => $this->f['location']->public_id, 'quantity' => '10.000',
            'last_dispensed_date' => null, 'days_without_dispensing' => 60, 'suggested_action' => 'review',
        ], $proposals[InventoryAnalyst::SLOW][0]);
        $this->assertSame([30, 'write_off_review'], [$proposals[InventoryAnalyst::EXPIRY][0]['days_left'], $proposals[InventoryAnalyst::EXPIRY][0]['suggested_action']]);
    }

    public function test_bad_input_is_refused_without_a_run(): void
    {
        $this->artisan('agents:inventory-review', ['branch' => 'NOWHERE'])->assertFailed();
        $this->artisan('agents:inventory-review', ['branch' => 'CHERAS', '--checks' => 'restock,guess'])->assertFailed();
        $this->artisan('agents:inventory-review', ['branch' => 'CHERAS', '--slot' => '7am'])->assertFailed();
        $this->assertSame(0, AiAgentRun::query()->count());
    }

    public function test_a_person_asked_run_reads_with_that_persons_authority(): void
    {
        $supervisor = $this->f['proposer'];
        $run = app(AgentRunRecorder::class)->start(InventoryAnalyst::AGENT, InventoryAnalyst::TASK, $this->branch, requester: $supervisor, permission: 'inventory.view.branch');

        $review = app(InventoryAnalyst::class)->review($run, $this->branch, ['restock'], $supervisor);

        $this->assertSame([InventoryAnalyst::RESTOCK => 1], $review->proposed);
        $this->refused(fn () => app(AgentToolGateway::class)->callAsSystem($run, 'inventory.stock_review', $this->branch), 'a run asked for by a person');
    }

    public function test_only_the_inventory_analyst_runs_the_review(): void
    {
        $run = app(AgentRunRecorder::class)->start('stocktake_officer', InventoryAnalyst::TASK, $this->branch);
        $this->refused(fn () => app(InventoryAnalyst::class)->review($run, $this->branch), 'another agent');
        $other = app(AgentRunRecorder::class)->start(InventoryAnalyst::AGENT, 'something_else', $this->branch);
        $this->refused(fn () => app(InventoryAnalyst::class)->review($other, $this->branch), 'another task');
        $this->assertSame(0, AiProposal::query()->count());
    }

    public function test_the_system_path_has_narrow_limits(): void
    {
        $gateway = app(AgentToolGateway::class);
        $run = $this->systemRun();

        $this->assertSame('CHERAS', $gateway->callAsSystem($run, 'inventory.stock_review', $this->branch)['branch']);

        $this->refused(fn () => $gateway->callAsSystem($run, 'clinic.day_summary', $this->branch), 'a data source that needs a person');
        $this->refused(fn () => $gateway->callAsSystem($run, 'inventory.stock_review', $this->puchong()), 'another branch');

        AiAgentTool::query()->where('ai_agent_id', $run->ai_agent_id)->where('tool_code', 'inventory.branch_balances')->delete();
        $this->refused(fn () => $gateway->callAsSystem($run, 'inventory.branch_balances', $this->branch), 'a data source off the allowlist');

        AiAgent::query()->whereKey($run->ai_agent_id)->update(['is_enabled' => false]);
        $this->refused(fn () => $gateway->callAsSystem($run, 'inventory.stock_review', $this->branch), 'a disabled agent');
        AiAgent::query()->whereKey($run->ai_agent_id)->update(['is_enabled' => true]);

        $console = new ReflectionProperty($this->app, 'isRunningInConsole');
        $console->setValue($this->app, false);
        try {
            $this->refused(fn () => $gateway->callAsSystem($run, 'inventory.stock_review', $this->branch), 'a web request');
        } finally {
            $console->setValue($this->app, true);
        }

        app(AgentRunRecorder::class)->complete($run);
        $this->refused(fn () => $gateway->callAsSystem($run, 'inventory.stock_review', $this->branch), 'a finished run');
    }

    /** Stored payloads are kept in key order (the proposal hash is over a canonical form). @param array<string, mixed> $expected @param array<string, mixed> $actual */
    private function assertPayload(array $expected, array $actual): void
    {
        ksort($expected);
        $this->assertSame($expected, $actual);
    }

    private function refused(callable $call, string $case): void
    {
        try {
            $call();
            $this->fail("Expected a refusal: {$case}.");
        } catch (AuthorizationException) {
            $this->addToAssertionCount(1);
        }
    }
}
