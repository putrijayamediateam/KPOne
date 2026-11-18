<?php

namespace Tests\Feature\Agents;

use App\Domain\Agents\Models\AiAgentRun;
use App\Domain\Agents\Proposals\AiProposal;
use App\Domain\Agents\Proposals\ProposalPreparers;
use App\Domain\Agents\Proposals\ProposalService;
use App\Domain\Agents\Services\AgentRunRecorder;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Organisation\Inventory\Models\InventoryLocation;
use App\Domain\Organisation\Models\Branch;
use Database\Seeders\KPOneAgentSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Visit\VisitTestCase;
use Tests\Support\FakeProposalPreparer;
use Tests\Support\GovernedStockFixture;

/** AG-30: approval that may prepare work (CONTRACT section 15): the switch, the mode, the edits, the link and the rollback. */
class ProposalPrepareTest extends VisitTestCase
{
    private ProposalService $proposals;

    private FakeProposalPreparer $preparer;

    private array $f;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(KPOneAgentSeeder::class);
        $this->proposals = app(ProposalService::class);
        $this->f = (new GovernedStockFixture($this->branch))->build('3.000');
        $this->preparer = new FakeProposalPreparer;
        $this->app->instance(FakeProposalPreparer::class, $this->preparer);
        $this->app->tag([FakeProposalPreparer::class], ProposalPreparers::TAG);
    }

    private function agentRun(): AiAgentRun
    {
        return app(AgentRunRecorder::class)->start('inventory_analyst', 'daily_stock_review', $this->branch);
    }

    private function restock(string $key = 'restock-cheras-0001'): AiProposal
    {
        return $this->proposals->propose($this->agentRun(), 'inventory.restock_suggestion', $this->branch, ['sku_public_id' => $this->f['sku']->public_id, 'location_public_id' => $this->f['location']->public_id,
            'current_quantity' => '3.000', 'reorder_level' => '10.000', 'suggested_quantity' => '7.000', 'reason_code' => 'at_or_below_reorder_level'], $key);
    }

    private function transfer(string $key = 'transfer-cheras-0001'): AiProposal
    {
        return $this->proposals->propose($this->agentRun(), 'inventory.transfer_suggestion', $this->branch, ['sku_public_id' => $this->f['sku']->public_id, 'from_branch_code' => 'PUCHONG', 'to_branch_code' => 'CHERAS',
            'quantity' => '2.000', 'from_current_quantity' => '9.000', 'from_reorder_level' => '5.000', 'to_current_quantity' => '3.000', 'to_reorder_level' => '5.000',
            'source_location_public_id' => $this->sender()->public_id, 'destination_location_public_id' => $this->f['location']->public_id], $key);
    }

    /** A usable location at the sending branch (a transfer suggestion names one at each end). */
    private function sender(): InventoryLocation
    {
        return InventoryLocation::query()->where('code', 'SYN-SEND')->first() ?? tap(new InventoryLocation, fn (InventoryLocation $location) => $location->forceFill([
            'public_id' => (string) Str::uuid(), 'organisation_id' => $this->organisation->id, 'code' => 'SYN-SEND', 'name' => 'Synthetic sending dispensary', 'type' => 'dispensary', 'is_active' => true,
            'branch_id' => Branch::query()->where('organisation_id', $this->organisation->id)->where('code', 'PUCHONG')->value('id'),
        ])->save());
    }

    private function expiry(): AiProposal
    {
        return $this->proposals->propose($this->agentRun(), 'inventory.expiry_warning', $this->branch, ['sku_public_id' => $this->f['sku']->public_id, 'batch_public_id' => $this->f['batch']->public_id,
            'location_public_id' => $this->f['location']->public_id, 'quantity' => '3.000', 'expiry_date' => '2027-01-01', 'days_left' => 57, 'suggested_action' => 'write_off_review'], 'expiry-0001');
    }

    private function refusedWith(callable $action, string $field, ?string $message = null): void
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

    private function assertStillPending(AiProposal $proposal, int $version = 0): void
    {
        $fresh = $proposal->fresh();
        $this->assertSame(['pending', $version, null], [$fresh->status, $fresh->lock_version, $fresh->prepared_record_type]);
        $this->assertSame(0, AuditLog::query()->where('event', 'ai_proposal.approved')->count());
    }

    public function test_the_switch_is_off_by_default_and_approval_stays_decision_only(): void
    {
        $this->assertFalse(config('agents.prepare_enabled'));
        $this->assertStringContainsString('AGENTS_PREPARE_ENABLED=false', (string) file_get_contents(base_path('.env.example')));

        $proposal = $this->restock();
        $this->refusedWith(fn () => $this->proposals->approve($this->actor('ca_supervisor'), $proposal, ['lock_version' => 0, 'mode' => 'prepare']), 'mode', 'Preparing work from a suggestion is not switched on. Approve it as a decision only.');
        $this->assertSame([], $this->preparer->calls);
        $this->assertStillPending($proposal);

        $approved = $this->proposals->approve($this->actor('ca_supervisor'), $proposal, ['lock_version' => 0]);
        $this->assertSame(['approved', null, null], [$approved->status, $approved->prepared_record_type, $approved->prepared_at]);
        $this->assertSame([], $this->preparer->calls, 'no mode means a decision only, even with the switch on');
    }

    public function test_decision_only_never_prepares_even_when_the_switch_is_on(): void
    {
        config(['agents.prepare_enabled' => true]);
        $proposal = $this->restock();
        $approved = $this->proposals->approve($this->actor('ca_supervisor'), $proposal, ['lock_version' => 0, 'mode' => 'decision_only']);

        $this->assertSame(['approved', null], [$approved->status, $approved->prepared_record_public_id]);
        $this->assertSame([], $this->preparer->calls);
        $this->refusedWith(fn () => $this->proposals->approve($this->actor('ca_supervisor'), $this->restock('restock-cheras-0002'), ['lock_version' => 0, 'edits' => ['quantity' => '1']]), 'edits');
    }

    public function test_prepare_runs_as_the_approver_inside_the_decision_and_links_the_record(): void
    {
        config(['agents.prepare_enabled' => true]);
        $proposal = $this->restock();
        $supervisor = $this->actor('ca_supervisor');
        $outer = DB::transactionLevel();

        $approved = $this->proposals->approve($supervisor, $proposal, ['lock_version' => 0, 'mode' => 'prepare']);

        $this->assertCount(1, $this->preparer->calls);
        $call = $this->preparer->calls[0];
        $this->assertSame([$supervisor->id, $proposal->id], [$call['actor'], $call['proposal']]);
        $this->assertGreaterThan($outer, $call['transactionLevel'], 'prepared inside the approval transaction');
        $this->assertSame('7.000', $call['values']['suggested_quantity']);
        $this->assertSame(['approved', 'inventory.stock_request', '11111111-1111-4111-8111-111111111111', 'SR-000001'], [$approved->status, $approved->prepared_record_type, $approved->prepared_record_public_id, $approved->prepared_record_label]);
        $this->assertNotNull($approved->prepared_at);
        $audit = AuditLog::query()->where('event', 'ai_proposal.approved')->sole();
        $this->assertSame(['prepare', 'inventory.stock_request', 'SR-000001', $proposal->id], [$audit->metadata['mode'], $audit->metadata['prepared_record_type'], $audit->metadata['prepared_record_label'], $audit->metadata['proposal_id']]);

        $again = $this->proposals->approve($supervisor, $proposal, ['lock_version' => 0, 'mode' => 'prepare']);
        $this->assertSame([$approved->id, 'SR-000001', 1], [$again->id, $again->prepared_record_label, count($this->preparer->calls)], 'a retry returns the same link and prepares nothing again');
        $this->assertSame(1, AuditLog::query()->where('event', 'ai_proposal.approved')->count());
    }

    public function test_a_refusal_from_the_domain_rolls_the_whole_approval_back(): void
    {
        config(['agents.prepare_enabled' => true]);
        $proposal = $this->restock();
        $this->preparer->refuseWith = 'This branch can spare 1 of this item. Lower the quantity.';

        $this->refusedWith(fn () => $this->proposals->approve($this->actor('ca_supervisor'), $proposal, ['lock_version' => 0, 'mode' => 'prepare']), 'quantity', 'This branch can spare 1 of this item. Lower the quantity.');

        $this->assertCount(1, $this->preparer->calls);
        $this->assertStillPending($proposal);
    }

    public function test_someone_who_cannot_prepare_is_told_why_and_nothing_changes(): void
    {
        config(['agents.prepare_enabled' => true]);
        $proposal = $this->restock();
        $this->preparer->cannotReason = 'Only a CA Supervisor at this branch prepares this.';

        $this->refusedWith(fn () => $this->proposals->approve($this->actor('finance_officer'), $proposal, ['lock_version' => 0, 'mode' => 'prepare']), 'mode', 'Only a CA Supervisor at this branch prepares this.');
        $this->assertSame([], $this->preparer->calls);
        $this->assertStillPending($proposal);

        $decided = $this->proposals->approve($this->actor('finance_officer'), $proposal, ['lock_version' => 0, 'mode' => 'decision_only']);
        $this->assertSame(['approved', null], [$decided->status, $decided->prepared_record_type]);
    }

    public function test_a_type_without_a_preparer_or_a_rejection_never_prepares(): void
    {
        config(['agents.prepare_enabled' => true]);
        $expiry = $this->expiry();
        $this->refusedWith(fn () => $this->proposals->approve($this->actor('ca_supervisor'), $expiry, ['lock_version' => 0, 'mode' => 'prepare']), 'mode', 'This suggestion does not prepare work.');

        $this->preparer = new FakeProposalPreparer(['inventory.transfer_suggestion']);
        $this->app->instance(FakeProposalPreparer::class, $this->preparer);
        $restock = $this->restock();
        $this->refusedWith(fn () => $this->proposals->approve($this->actor('ca_supervisor'), $restock, ['lock_version' => 0, 'mode' => 'prepare']), 'mode', 'This suggestion does not prepare work.');
        $this->refusedWith(fn () => $this->proposals->reject($this->actor('ca_supervisor'), $restock, ['lock_version' => 0, 'reason' => 'Not needed', 'mode' => 'prepare']), 'mode', 'Only an approval can prepare work.');
        $this->refusedWith(fn () => $this->proposals->approve($this->actor('ca_supervisor'), $restock, ['lock_version' => 0, 'mode' => 'surprise']), 'mode');
        $this->assertSame([], $this->preparer->calls);
        $this->assertStillPending($restock);
    }

    public function test_edits_may_lower_the_quantity_only_and_only_known_fields(): void
    {
        config(['agents.prepare_enabled' => true]);
        $proposal = $this->transfer();
        $supervisor = $this->actor('ca_supervisor', Branch::query()->findOrFail($proposal->branch_id)); // held and decided by the sending branch
        $sourceLocation = '22222222-2222-4222-8222-222222222222';

        foreach ([['quantity' => '2.001'], ['quantity' => '0'], ['quantity' => '0.000'], ['quantity' => 'lots']] as $edits) {
            $this->refusedWith(fn () => $this->proposals->approve($supervisor, $proposal, ['lock_version' => 0, 'mode' => 'prepare', 'edits' => $edits]), $edits['quantity'] === 'lots' ? 'quantity' : 'edits');
        }
        foreach ([['sku_public_id' => $this->f['sku']->public_id], ['source_location_public_id' => 'not-a-uuid'], ['quantity' => '1', 'note' => 'x']] as $edits) {
            $this->refusedWith(fn () => $this->proposals->approve($supervisor, $proposal, ['lock_version' => 0, 'mode' => 'prepare', 'edits' => $edits]), array_key_first($edits) === 'source_location_public_id' ? 'source_location_public_id' : 'edits');
        }
        $this->assertSame([], $this->preparer->calls);
        $this->assertStillPending($proposal);

        $this->proposals->approve($supervisor, $proposal, ['lock_version' => 0, 'mode' => 'prepare', 'edits' => ['quantity' => '1.5', 'source_location_public_id' => $sourceLocation]]);
        $values = $this->preparer->calls[0]['values'];
        $this->assertSame(['1.500', $sourceLocation, 'CHERAS', 'PUCHONG'], [$values['quantity'], $values['source_location_public_id'], $values['to_branch_code'], $values['from_branch_code']]);
        $this->assertSame('2.000', $proposal->fresh()->payload['quantity'], 'the proposal keeps what the agent proposed');
    }

    public function test_the_link_is_all_or_nothing_and_only_on_an_approved_proposal(): void
    {
        $pending = $this->restock();
        $link = ['prepared_record_type' => 'inventory.stock_request', 'prepared_record_public_id' => '11111111-1111-4111-8111-111111111111', 'prepared_record_label' => 'SR-000001', 'prepared_at' => now()];
        $this->assertDbRefuses(fn () => DB::table('ai_proposals')->where('id', $pending->id)->update([...$link, 'lock_version' => 1]), 'ai_proposals_prepared_link_check');

        $approved = $this->proposals->approve($this->actor('ca_supervisor'), $this->restock('restock-cheras-0002'), ['lock_version' => 0]);
        $this->assertDbRefuses(fn () => DB::table('ai_proposals')->where('id', $approved->id)->update([...$link, 'lock_version' => 2]), 'A decided agent proposal cannot change');

        // The shape is checked on a fresh row too: half a link, or an unknown kind of record, is refused.
        foreach ([['prepared_record_type' => 'inventory.stock_request'], [...$link, 'prepared_record_type' => 'billing.invoice'], [...$link, 'prepared_record_label' => ' ']] as $broken) {
            $this->assertDbRefuses(fn () => DB::table('ai_proposals')->insert([...$this->insertRow($pending), 'idempotency_key' => 'broken-'.md5(json_encode($broken)), 'status' => 'approved', 'decided_at' => now(), 'decided_by_user_id' => $this->actor('ca_supervisor')->id, ...$broken]), 'ai_proposals_prepared_link_check');
        }
    }

    public function test_the_last_registered_preparer_for_a_type_wins(): void
    {
        $first = new FakeProposalPreparer(['inventory.restock_suggestion']);
        $this->app->instance('first-preparer', $first);
        $this->app->tag(['first-preparer'], ProposalPreparers::TAG);
        $second = new FakeProposalPreparer(['inventory.restock_suggestion']);
        $this->app->instance('second-preparer', $second);
        $this->app->tag(['second-preparer'], ProposalPreparers::TAG);

        $this->assertSame($second, app(ProposalPreparers::class)->for('inventory.restock_suggestion'));
        $this->assertNull(app(ProposalPreparers::class)->for('inventory.expiry_warning'));
    }

    public function test_the_retry_key_is_one_stable_uuid_per_proposal(): void
    {
        $key = ProposalPreparers::retryKey(41);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-5[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $key);
        $this->assertSame([$key, false], [ProposalPreparers::retryKey(41), $key === ProposalPreparers::retryKey(42)]);
    }

    /** @return array<string, mixed> */
    private function insertRow(AiProposal $from): array
    {
        $row = (array) DB::table('ai_proposals')->where('id', $from->id)->first();
        unset($row['id']);

        return [...$row, 'status' => 'pending'];
    }

    private function assertDbRefuses(callable $action, string $expected): void
    {
        try {
            DB::transaction($action);
            $this->fail("The database must refuse it ({$expected}).");
        } catch (QueryException $e) {
            $this->assertStringContainsString($expected, $e->getMessage());
        }
    }
}
