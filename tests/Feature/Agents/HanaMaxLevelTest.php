<?php

namespace Tests\Feature\Agents;

use App\Domain\Agents\Models\AiAgent;
use App\Domain\Agents\Models\AiAgentTool;
use App\Domain\Agents\Proposals\ProposalService;
use App\Domain\Agents\Services\AgentRunRecorder;
use App\Domain\Agents\Tools\AgentToolGateway;
use Database\Seeders\KPOneAgentSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Queue\QueueTestCase;
use Tests\Support\GovernedStockFixture;

/** AG-14: the maximum level (STK-30) reaches Hana's restock rows and proposals, and the inbox says "to bring stock up to the maximum of N". */
class HanaMaxLevelTest extends QueueTestCase
{
    /** @var array<string, mixed> */
    private array $f;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(KPOneAgentSeeder::class);
        $agent = new AiAgent;
        $agent->forceFill(['organisation_id' => $this->organisation->id, 'code' => 'test_reader', 'display_name' => 'Test', 'job_title' => 'Test reader (AI agent)', 'kind' => 'runtime'])->save();
        AiAgentTool::query()->create(['ai_agent_id' => $agent->id, 'tool_code' => 'inventory.stock_review']);
        $this->f = (new GovernedStockFixture($this->branch))->build('3.000');
    }

    private function setLevels(string $reorder, ?string $max): void
    {
        DB::table('inventory_reorder_levels')->updateOrInsert(
            ['organisation_id' => $this->organisation->id, 'inventory_location_id' => $this->f['location']->id, 'inventory_sku_id' => $this->f['sku']->id],
            ['reorder_level' => $reorder, 'max_level' => $max, 'updated_by_user_id' => $this->f['proposer']->id, 'updated_at' => now(), 'created_at' => now()],
        );
    }

    /** @return array<string, mixed> */
    private function lowStockRow(): array
    {
        $supervisor = $this->f['proposer'];
        $this->selectBranch($supervisor);
        $run = app(AgentRunRecorder::class)->start('test_reader', 'test_read', $this->branch, organisationId: $this->organisation->id);
        $review = app(AgentToolGateway::class)->call($run, 'inventory.stock_review', $supervisor, $this->branch, []);

        return $review['rows']['lowStock'][0];
    }

    public function test_the_stock_review_row_carries_max_level_or_null(): void
    {
        $this->setLevels('10.000', null);
        $this->assertArrayHasKey('maxLevel', $this->lowStockRow());
        $this->assertNull($this->lowStockRow()['maxLevel']);
        $this->assertSame('10.000', $this->lowStockRow()['reorderLevel']);

        $this->setLevels('10.000', '25.000');
        $this->assertSame('25.000', $this->lowStockRow()['maxLevel']);
    }

    public function test_a_restock_proposal_may_carry_max_level_and_the_inbox_names_it(): void
    {
        $run = app(AgentRunRecorder::class)->start('inventory_analyst', 'daily_stock_review', $this->branch);
        $payload = ['sku_public_id' => $this->f['sku']->public_id, 'location_public_id' => $this->f['location']->public_id, 'current_quantity' => '3.000',
            'reorder_level' => '10.000', 'suggested_quantity' => '22.000', 'reason_code' => 'at_or_below_reorder_level', 'max_level' => '25.000'];
        $proposal = app(ProposalService::class)->propose($run, 'inventory.restock_suggestion', $this->branch, $payload, 'hana-max-0001');
        $this->assertSame('25.000', $proposal->refresh()->payload['max_level']);

        $supervisor = $this->actor('ca_supervisor');
        $this->selectBranch($supervisor);
        $this->get(route('agents.inbox'))->assertInertia(fn (Assert $page) => $page->component('Agents/Inbox')
            ->where('proposals.0.lines', fn ($lines) => collect($lines)->pluck('label')->contains('Suggested order')
                && collect($lines)->pluck('value')->contains('22 units, to bring stock up to the maximum of 25')
                && ! collect($lines)->pluck('label')->contains(fn (string $l) => str_starts_with($l, 'Fill to maximum'))));
    }

    public function test_without_max_level_the_inbox_row_stays_suggested_order_and_a_bad_max_is_refused(): void
    {
        $run = app(AgentRunRecorder::class)->start('inventory_analyst', 'daily_stock_review', $this->branch);
        $payload = ['sku_public_id' => $this->f['sku']->public_id, 'location_public_id' => $this->f['location']->public_id, 'current_quantity' => '3.000',
            'reorder_level' => '10.000', 'suggested_quantity' => '7.000', 'reason_code' => 'at_or_below_reorder_level'];
        app(ProposalService::class)->propose($run, 'inventory.restock_suggestion', $this->branch, $payload, 'hana-nomax-0001');

        $supervisor = $this->actor('ca_supervisor');
        $this->selectBranch($supervisor);
        $this->get(route('agents.inbox'))->assertInertia(fn (Assert $page) => $page->component('Agents/Inbox')
            ->where('proposals.0.lines', fn ($lines) => collect($lines)->pluck('label')->contains('Suggested order')));

        $this->expectException(ValidationException::class);
        app(ProposalService::class)->propose($run, 'inventory.restock_suggestion', $this->branch, [...$payload, 'max_level' => 'lots'], 'hana-badmax-0001');
    }
}
