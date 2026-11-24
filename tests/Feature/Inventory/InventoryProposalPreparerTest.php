<?php

namespace Tests\Feature\Inventory;

use App\Domain\Access\BranchAccessService;
use App\Domain\Agents\Models\AiAgentRun;
use App\Domain\Agents\Proposals\AiProposal;
use App\Domain\Agents\Proposals\ProposalService;
use App\Domain\Agents\Proposals\WarnsBeforePreparing;
use App\Domain\Agents\Services\AgentRunRecorder;
use App\Domain\Organisation\Inventory\Models\InventoryLocation;
use App\Domain\Organisation\Inventory\Models\StockRequest;
use App\Domain\Organisation\Inventory\Services\InventoryProposalPreparer;
use App\Domain\Organisation\Inventory\Services\StockRequestService;
use App\Domain\Organisation\Models\Branch;
use App\Models\User;
use Database\Seeders\KPOneAgentSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Visit\VisitTestCase;
use Tests\Support\GovernedStockFixture;

/**
 * STK-41: approving an agent transfer suggestion prepares a governed stock request, and a restock suggestion a purchase request,
 * through the normal Inventory services as the approver; an agent-prepared stock request never approves itself.
 */
final class InventoryProposalPreparerTest extends VisitTestCase
{
    private ProposalService $proposals;

    /** @var array<string, mixed> */
    private array $f;

    private Branch $puchong;

    private InventoryLocation $puchongDispensary;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(KPOneAgentSeeder::class);
        config(['agents.prepare_enabled' => true]);
        $this->proposals = app(ProposalService::class);
        $this->f = (new GovernedStockFixture($this->branch))->build('10.000');
        $this->puchong = Branch::query()->where('organisation_id', $this->branch->organisation_id)->where('code', 'PUCHONG')->firstOrFail();
        $this->puchongDispensary = new InventoryLocation;
        $this->puchongDispensary->forceFill(['public_id' => (string) Str::uuid(), 'organisation_id' => $this->branch->organisation_id, 'branch_id' => $this->puchong->id,
            'code' => 'SYN-PCG-'.Str::upper(Str::random(6)), 'name' => 'Synthetic Puchong dispensary', 'type' => 'dispensary', 'is_active' => true])->save();
    }

    private function approver(string $role = 'ca_supervisor'): User
    {
        $actor = (new GovernedStockFixture($this->branch))->actor($role);
        app(BranchAccessService::class)->select($actor, $this->branch);

        return $actor;
    }

    private function agentRun(): AiAgentRun
    {
        return app(AgentRunRecorder::class)->start('inventory_analyst', 'daily_stock_review', $this->branch);
    }

    /**
     * A transfer suggestion decided at the sending branch (Cheras) for stock going to Puchong: the shape the agent platform's
     * step 2b proposes. Until 2b, `propose` still pins a transfer to the receiving branch, so the row is written as 2b will write it.
     */
    private function transfer(string $quantity = '4.000', string $key = 'transfer-0001'): AiProposal
    {
        $run = $this->agentRun();
        $payload = ['from_branch_code' => $this->branch->code, 'from_current_quantity' => '10.000', 'from_reorder_level' => '0.000', 'quantity' => $quantity,
            'sku_public_id' => $this->f['sku']->public_id, 'to_branch_code' => 'PUCHONG', 'to_current_quantity' => '0.000', 'to_reorder_level' => '5.000'];

        return AiProposal::query()->create(['organisation_id' => $run->organisation_id, 'branch_id' => $this->branch->id, 'ai_agent_run_id' => $run->id,
            'ai_agent_id' => $run->ai_agent_id, 'type' => 'inventory.transfer_suggestion', 'payload' => $payload,
            'payload_hash' => hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)), 'status' => 'pending', 'lock_version' => 0, 'idempotency_key' => $key]);
    }

    private function restock(string $key = 'restock-0001'): AiProposal
    {
        return $this->proposals->propose($this->agentRun(), 'inventory.restock_suggestion', $this->branch, ['sku_public_id' => $this->f['sku']->public_id,
            'location_public_id' => $this->f['location']->public_id, 'current_quantity' => '10.000', 'reorder_level' => '12.000', 'suggested_quantity' => '2.000',
            'reason_code' => 'at_or_below_reorder_level'], $key);
    }

    private function reorderLevel(string $level): void
    {
        DB::table('inventory_reorder_levels')->insert(['organisation_id' => $this->branch->organisation_id, 'inventory_location_id' => $this->f['location']->id,
            'inventory_sku_id' => $this->f['sku']->id, 'reorder_level' => $level, 'updated_by_user_id' => $this->f['proposer']->id, 'created_at' => now(), 'updated_at' => now()]);
    }

    /** @return array<string, mixed> */
    private function prepare(array $edits = []): array
    {
        return ['lock_version' => 0, 'mode' => 'prepare', 'edits' => $edits];
    }

    private function locations(): array
    {
        return ['source_location_public_id' => $this->f['location']->public_id, 'destination_location_public_id' => $this->puchongDispensary->public_id];
    }

    private function refused(callable $action, string $field, ?string $message = null): void
    {
        try {
            $action();
            $this->fail('Must be refused.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey($field, $e->errors());
            if ($message !== null) {
                $this->assertSame($message, $e->errors()[$field][0]);
            }
        }
    }

    private function assertPendingAndNothingMade(AiProposal $proposal): void
    {
        $this->assertSame(['pending', null], [$proposal->fresh()->status, $proposal->fresh()->prepared_record_type]);
        $this->assertSame(0, StockRequest::query()->count());
        $this->assertSame(0, DB::table('inventory_purchase_requests')->count());
    }

    public function test_an_approved_transfer_suggestion_becomes_a_requested_stock_request_by_the_approver(): void
    {
        $proposal = $this->transfer();
        $supervisor = $this->approver();

        $approved = $this->proposals->approve($supervisor, $proposal, $this->prepare(['quantity' => '3', ...$this->locations()]));

        $request = StockRequest::query()->sole();
        $this->assertSame([StockRequest::STATUS_REQUESTED, $supervisor->id, $proposal->id], [$request->status, (int) $request->requested_by_user_id, (int) $request->ai_proposal_id]);
        $this->assertSame([$this->f['location']->id, $this->puchongDispensary->id], [(int) $request->source_location_id, (int) $request->destination_location_id]);
        $this->assertSame('3.000', (string) DB::table('inventory_stock_request_lines')->where('stock_request_id', $request->id)->value('requested_quantity'));
        $this->assertSame(['approved', 'inventory.stock_request', $request->public_id, $request->request_number],
            [$approved->status, $approved->prepared_record_type, $approved->prepared_record_public_id, $approved->prepared_record_label]);
        $audit = json_decode((string) DB::table('audit_logs')->where('event', 'inventory.stock_request.requested')->sole()->metadata, true);
        $this->assertSame(['ai_proposal', $proposal->id, true], [$audit['source'], $audit['ai_proposal_id'], $audit['cross_branch']]);

        // A retry of the same approval returns the same request.
        $this->proposals->approve($supervisor, $proposal, $this->prepare(['quantity' => '3', ...$this->locations()]));
        $this->assertSame(1, StockRequest::query()->count());
    }

    public function test_a_transfer_is_refused_without_locations_to_the_wrong_branch_or_beyond_what_can_be_spared(): void
    {
        $proposal = $this->transfer();
        $supervisor = $this->approver();

        $this->refused(fn () => $this->proposals->approve($supervisor, $proposal, $this->prepare()), 'edits', 'Choose where the stock comes from and where it goes.');
        $this->refused(fn () => $this->proposals->approve($supervisor, $proposal, $this->prepare(['destination_location_public_id' => $this->f['location']->public_id,
            'source_location_public_id' => $this->f['location']->public_id])), 'edits', 'The stock must come from the sending branch and go to the receiving branch in this suggestion.');
        $this->assertPendingAndNothingMade($proposal);

        // Stock changed in between: only 1 can be spared now, so the stock request service refuses and nothing is made.
        DB::table('inventory_stock_balances')->where('id', $this->f['balance']->id)->update(['quantity' => '1.000']);
        $this->refused(fn () => $this->proposals->approve($supervisor, $proposal, $this->prepare($this->locations())), 'lines.0.quantity', 'This branch can spare 1 of this item. Lower the quantity.');
        $this->assertPendingAndNothingMade($proposal);
    }

    public function test_an_agent_prepared_stock_request_is_never_approved_by_its_requester(): void
    {
        $supervisor = $this->approver();
        $this->proposals->approve($supervisor, $this->transfer(), $this->prepare($this->locations()));
        $request = StockRequest::query()->sole();
        $attributes = ['expected_branch_id' => $this->branch->id, 'lock_version' => $request->lock_version];

        try {
            app(StockRequestService::class)->approve($supervisor, $request, $attributes);
            $this->fail('The requester approved their own agent-prepared request.');
        } catch (AuthorizationException $e) {
            $this->assertSame('This request was prepared from a suggestion you approved, so someone else must approve it.', $e->getMessage());
        }
        $this->assertSame(StockRequest::STATUS_REQUESTED, $request->fresh()->status);

        // PostgreSQL refuses it too, even straight into the table.
        try {
            DB::transaction(fn () => DB::table('inventory_stock_requests')->where('id', $request->id)->update(['status' => 'approved', 'decided_by_user_id' => $supervisor->id, 'decided_at' => now()]));
            $this->fail('PostgreSQL accepted a self-approval.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('approved by someone other than its requester', $e->getMessage());
        }
        try {
            DB::transaction(fn () => DB::table('inventory_stock_requests')->where('id', $request->id)->update(['ai_proposal_id' => null]));
            $this->fail('PostgreSQL let the suggestion link change.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('kept as recorded', $e->getMessage());
        }

        // Another CA Supervisor approves it as usual.
        $other = $this->approver();
        $this->assertSame(StockRequest::STATUS_APPROVED, app(StockRequestService::class)->approve($other, $request, $attributes)->status);
    }

    public function test_an_approved_restock_suggestion_becomes_a_purchase_request_and_is_refused_once_stock_is_above_the_level(): void
    {
        $this->reorderLevel('12.000');
        $proposal = $this->restock();
        $preparer = app(InventoryProposalPreparer::class);
        $this->assertNull($preparer->openOrderWarning($proposal));
        $supervisor = $this->approver();

        $approved = $this->proposals->approve($supervisor, $proposal, $this->prepare());

        $request = DB::table('inventory_purchase_requests')->sole();
        $line = DB::table('inventory_purchase_request_lines')->where('purchase_request_id', $request->id)->sole();
        $this->assertSame(['waiting', $supervisor->id, $proposal->id, $this->f['location']->id], [$request->status, (int) $request->requested_by_user_id, (int) $request->ai_proposal_id, (int) $request->destination_location_id]);
        $this->assertSame(['2.000', InventoryProposalPreparer::RESTOCK_NOTE], [(string) $line->requested_quantity, $line->note]);
        $this->assertSame(['inventory.purchase_request', $request->request_number], [$approved->prepared_record_type, $approved->prepared_record_label]);
        $this->assertSame(InventoryProposalPreparer::OPEN_ORDER_WARNING, $preparer->openOrderWarning($this->restock('restock-0002')));
        // STK-41b: the agent platform's inbox reads the same warning through WarnsBeforePreparing; a transfer has none.
        $this->assertInstanceOf(WarnsBeforePreparing::class, $preparer);
        $this->assertSame(InventoryProposalPreparer::OPEN_ORDER_WARNING, $preparer->warning($supervisor, $this->restock('restock-0004')));
        $this->assertNull($preparer->warning($supervisor, $this->transfer('4.000', 'transfer-warning-0001')));

        // Stock back above the level: the live check refuses, nothing is made and the suggestion stays pending.
        DB::table('inventory_reorder_levels')->update(['reorder_level' => '5.000']);
        $later = $this->restock('restock-0003');
        $this->refused(fn () => $this->proposals->approve($supervisor, $later, $this->prepare()), 'mode', InventoryProposalPreparer::ABOVE_LEVEL);
        $this->assertSame('pending', $later->fresh()->status);
        $this->assertSame(1, DB::table('inventory_purchase_requests')->count());
    }

    public function test_a_transfer_whose_stock_is_not_at_the_deciding_branch_is_refused_and_nothing_is_made(): void
    {
        // Defensive: the agent platform holds a transfer at the sending branch, but if the stock it names is elsewhere (here the
        // suggestion sits at Cheras while the stock comes from Puchong), the preparer refuses rather than letting STK-31 404.
        $source = new InventoryLocation;
        $source->forceFill(['public_id' => (string) Str::uuid(), 'organisation_id' => $this->branch->organisation_id, 'branch_id' => $this->puchong->id,
            'code' => 'SYN-PCG-'.Str::upper(Str::random(6)), 'name' => 'Synthetic Puchong store', 'type' => 'branch_store', 'is_active' => true])->save();
        $run = $this->agentRun();
        $payload = ['destination_location_public_id' => $this->f['location']->public_id, 'from_branch_code' => 'PUCHONG', 'from_current_quantity' => '9.000',
            'from_reorder_level' => '5.000', 'quantity' => '2.000', 'sku_public_id' => $this->f['sku']->public_id, 'source_location_public_id' => $source->public_id,
            'to_branch_code' => $this->branch->code, 'to_current_quantity' => '3.000', 'to_reorder_level' => '5.000'];
        $proposal = AiProposal::query()->create(['organisation_id' => $run->organisation_id, 'branch_id' => $this->branch->id, 'ai_agent_run_id' => $run->id,
            'ai_agent_id' => $run->ai_agent_id, 'type' => 'inventory.transfer_suggestion', 'payload' => $payload,
            'payload_hash' => hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)), 'status' => 'pending', 'lock_version' => 0,
            'idempotency_key' => 'transfer-elsewhere-0001']);

        $this->refused(fn () => $this->proposals->approve($this->approver(), $proposal, $this->prepare()), 'mode',
            'This transfer is prepared at the branch that sends the stock. Approve it as a decision only here.');
        $this->assertPendingAndNothingMade($proposal);
    }

    public function test_a_finance_officer_is_told_why_and_may_still_decide_as_a_decision_only(): void
    {
        $proposal = $this->transfer();
        $finance = $this->approver('finance_officer');

        $this->refused(fn () => $this->proposals->approve($finance, $proposal, $this->prepare($this->locations())), 'mode', 'Your role cannot create stock requests, so approve this as a decision only.');
        $this->assertPendingAndNothingMade($proposal);

        $this->assertSame('approved', $this->proposals->approve($finance, $proposal, ['lock_version' => 0, 'mode' => 'decision_only'])->status);
        $this->assertSame(0, StockRequest::query()->count());
    }
}
