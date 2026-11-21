<?php

namespace Tests\Feature\Agents;

use App\Domain\Agents\Models\AiAgentRun;
use App\Domain\Agents\Proposals\AiProposal;
use App\Domain\Agents\Proposals\ProposalService;
use App\Domain\Agents\Services\AgentRunRecorder;
use App\Domain\Organisation\Inventory\Models\InventoryLocation;
use App\Domain\Organisation\Models\Branch;
use Database\Seeders\KPOneAgentSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Visit\VisitTestCase;
use Tests\Support\GovernedStockFixture;

/** AG-31: a transfer suggestion is held by the sending branch; every other type stays at its run's branch. */
class TransferProposalHolderTest extends VisitTestCase
{
    private ProposalService $proposals;

    private array $f;

    private Branch $puchong;

    private InventoryLocation $senderLocation;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(KPOneAgentSeeder::class);
        $this->proposals = app(ProposalService::class);
        $this->f = (new GovernedStockFixture($this->branch))->build('3.000');
        $this->puchong = Branch::query()->where('organisation_id', $this->organisation->id)->where('code', 'PUCHONG')->firstOrFail();
        $this->senderLocation = $this->location($this->puchong);
    }

    private function location(Branch $branch, string $type = 'dispensary', bool $active = true): InventoryLocation
    {
        $location = new InventoryLocation;
        $location->forceFill(['public_id' => (string) Str::uuid(), 'organisation_id' => $this->organisation->id, 'branch_id' => $branch->id, 'code' => 'SYN-'.Str::upper(Str::random(8)),
            'name' => 'Synthetic location', 'type' => $type, 'is_active' => $active])->save();

        return $location;
    }

    private function agentRun(?Branch $branch = null): AiAgentRun
    {
        return app(AgentRunRecorder::class)->start('inventory_analyst', 'daily_stock_review', $branch ?? $this->branch);
    }

    /** @return array<string, string> */
    private function payload(array $overrides = []): array
    {
        return ['sku_public_id' => $this->f['sku']->public_id, 'from_branch_code' => 'PUCHONG', 'to_branch_code' => 'CHERAS', 'quantity' => '2.000', 'from_current_quantity' => '9.000',
            'from_reorder_level' => '5.000', 'to_current_quantity' => '3.000', 'to_reorder_level' => '5.000',
            'source_location_public_id' => $this->senderLocation->public_id, 'destination_location_public_id' => $this->f['location']->public_id, ...$overrides];
    }

    private function propose(array $overrides = [], ?AiAgentRun $run = null, string $key = 'transfer-0001'): AiProposal
    {
        return $this->proposals->propose($run ?? $this->agentRun(), 'inventory.transfer_suggestion', $this->branch, $this->payload($overrides), $key);
    }

    public function test_the_proposal_is_held_by_the_sender_and_a_retry_returns_the_same_one(): void
    {
        $run = $this->agentRun();
        $proposal = $this->propose([], $run);

        $this->assertSame([$this->puchong->id, $this->branch->id], [$proposal->branch_id, $run->branch_id]);
        $this->assertSame($proposal->id, $this->propose([], $run)->id);
        $this->assertSame(1, AiProposal::query()->count());
        $this->assertSame($this->puchong->id, DB::table('audit_logs')->where('event', 'ai_proposal.proposed')->value('branch_id'), 'the audit row sits at the holding branch');
    }

    public function test_both_locations_must_be_real_active_stock_locations_at_the_right_branch(): void
    {
        $inactive = $this->location($this->puchong, active: false);
        $returns = $this->location($this->puchong, InventoryLocation::TYPE_DISPENSARY_RETURNS);
        $receiverReturns = $this->location($this->branch, InventoryLocation::TYPE_DISPENSARY_RETURNS);
        $cases = [
            'source at the receiving branch' => ['source_location_public_id' => $this->f['location']->public_id],
            'destination at the sending branch' => ['destination_location_public_id' => $this->senderLocation->public_id],
            'inactive source' => ['source_location_public_id' => $inactive->public_id],
            'patient returns source' => ['source_location_public_id' => $returns->public_id],
            'patient returns destination' => ['destination_location_public_id' => $receiverReturns->public_id],
            'unknown source' => ['source_location_public_id' => '00000000-0000-4000-8000-000000000000'],
            'missing destination' => ['destination_location_public_id' => null],
        ];
        $n = 0;
        foreach ($cases as $case => $override) {
            try {
                $this->propose($override, null, 'transfer-bad-'.++$n);
                $this->fail("Must be refused: {$case}.");
            } catch (ValidationException) {
                $this->assertTrue(true);
            }
        }
        $this->assertSame(0, AiProposal::query()->count());
    }

    public function test_a_run_must_be_at_the_receiver_and_other_types_stay_at_their_own_branch(): void
    {
        try {
            $this->proposals->propose($this->agentRun($this->puchong), 'inventory.transfer_suggestion', $this->puchong, $this->payload(), 'transfer-wrong-run');
            $this->fail('A run at the sender cannot propose a transfer to the receiver.');
        } catch (ValidationException) {
            $this->assertSame(0, AiProposal::query()->count());
        }

        $restock = $this->proposals->propose($this->agentRun(), 'inventory.restock_suggestion', $this->branch, ['sku_public_id' => $this->f['sku']->public_id, 'location_public_id' => $this->f['location']->public_id,
            'current_quantity' => '3.000', 'reorder_level' => '10.000', 'suggested_quantity' => '7.000', 'reason_code' => 'at_or_below_reorder_level'], 'restock-0001');
        $this->assertSame($this->branch->id, $restock->branch_id);
    }

    public function test_the_database_lets_only_a_transfer_differ_from_its_run_branch(): void
    {
        $restock = $this->proposals->propose($this->agentRun(), 'inventory.restock_suggestion', $this->branch, ['sku_public_id' => $this->f['sku']->public_id, 'location_public_id' => $this->f['location']->public_id,
            'current_quantity' => '3.000', 'reorder_level' => '10.000', 'suggested_quantity' => '7.000', 'reason_code' => 'at_or_below_reorder_level'], 'restock-0001');
        $row = (array) DB::table('ai_proposals')->where('id', $restock->id)->first();
        unset($row['id']);

        try {
            DB::transaction(fn () => DB::table('ai_proposals')->insert([...$row, 'branch_id' => $this->puchong->id, 'idempotency_key' => 'restock-moved-0001']));
            $this->fail('A restock held by another branch must be refused.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('Agent proposal scope mismatch', $e->getMessage());
        }
    }
}
