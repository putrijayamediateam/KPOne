<?php

namespace Tests\Feature\Agents;

use App\Domain\Agents\Proposals\AiProposal;
use App\Domain\Agents\Proposals\ProposalPreparers;
use App\Domain\Agents\Proposals\ProposalService;
use App\Domain\Agents\Services\AgentRunRecorder;
use App\Domain\Organisation\Inventory\Models\InventoryLocation;
use App\Domain\Organisation\Models\Branch;
use Database\Seeders\KPOneAgentSeeder;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Visit\VisitTestCase;
use Tests\Support\FakeProposalPreparer;
use Tests\Support\GovernedStockFixture;

/** AG-32: the inbox page and the Approve action offer "Approve and create ..." only when approval can prepare work. */
class InboxPrepareTest extends VisitTestCase
{
    private FakeProposalPreparer $preparer;

    private array $f;

    private Branch $puchong;

    private InventoryLocation $senderLocation;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(KPOneAgentSeeder::class);
        $this->f = (new GovernedStockFixture($this->branch))->build('3.000');
        $this->puchong = Branch::query()->where('organisation_id', $this->organisation->id)->where('code', 'PUCHONG')->firstOrFail();
        $this->senderLocation = new InventoryLocation;
        $this->senderLocation->forceFill(['public_id' => (string) Str::uuid(), 'organisation_id' => $this->organisation->id, 'branch_id' => $this->puchong->id, 'code' => 'SYN-SEND',
            'name' => 'Synthetic sending dispensary', 'type' => 'dispensary', 'is_active' => true])->save();
        $this->preparer = new FakeProposalPreparer;
        $this->app->instance(FakeProposalPreparer::class, $this->preparer);
        $this->app->tag([FakeProposalPreparer::class], ProposalPreparers::TAG);
    }

    private function restock(): AiProposal
    {
        return app(ProposalService::class)->propose(app(AgentRunRecorder::class)->start('inventory_analyst', 'daily_stock_review', $this->branch), 'inventory.restock_suggestion', $this->branch, [
            'sku_public_id' => $this->f['sku']->public_id, 'location_public_id' => $this->f['location']->public_id, 'current_quantity' => '3.000',
            'reorder_level' => '10.000', 'suggested_quantity' => '7.000', 'reason_code' => 'at_or_below_reorder_level'], 'inbox-restock-0001');
    }

    private function transfer(): AiProposal
    {
        return app(ProposalService::class)->propose(app(AgentRunRecorder::class)->start('inventory_analyst', 'daily_stock_review', $this->branch), 'inventory.transfer_suggestion', $this->branch, [
            'sku_public_id' => $this->f['sku']->public_id, 'from_branch_code' => 'PUCHONG', 'to_branch_code' => 'CHERAS', 'quantity' => '2.000', 'from_current_quantity' => '9.000',
            'from_reorder_level' => '5.000', 'to_current_quantity' => '3.000', 'to_reorder_level' => '5.000',
            'source_location_public_id' => $this->senderLocation->public_id, 'destination_location_public_id' => $this->f['location']->public_id], 'inbox-transfer-0001');
    }

    private function expiry(): AiProposal
    {
        return app(ProposalService::class)->propose(app(AgentRunRecorder::class)->start('inventory_analyst', 'daily_stock_review', $this->branch), 'inventory.expiry_warning', $this->branch, [
            'sku_public_id' => $this->f['sku']->public_id, 'batch_public_id' => $this->f['batch']->public_id, 'location_public_id' => $this->f['location']->public_id, 'quantity' => '3.000',
            'expiry_date' => '2027-01-01', 'days_left' => 57, 'suggested_action' => 'write_off_review'], 'inbox-expiry-0001');
    }

    public function test_with_the_switch_off_nothing_is_offered(): void
    {
        $this->restock();
        $this->selectBranch($this->actor('ca_supervisor'));

        $this->get(route('agents.inbox'))->assertInertia(fn (Assert $page) => $page->where('proposals.0.prepare', null)->where('proposals.0.prepared', null));
    }

    public function test_a_restock_offers_the_purchase_request_and_an_expiry_warning_offers_nothing(): void
    {
        config(['agents.prepare_enabled' => true]);
        $this->restock();
        $this->expiry();
        $this->preparer->warningText = 'An open purchase request or purchase order already includes this item for this location.';
        $this->selectBranch($this->actor('ca_supervisor'));

        $this->get(route('agents.inbox'))->assertInertia(fn (Assert $page) => $page
            ->where('proposals.0.type', 'inventory.expiry_warning')->where('proposals.0.prepare', null)
            ->where('proposals.1.prepare', [
                'canPrepare' => true, 'cannotPrepareReason' => null, 'actionLabel' => 'Approve and create the purchase request', 'recordLabel' => 'purchase request',
                'warning' => 'An open purchase request or purchase order already includes this item for this location.', 'edits' => null,
            ]));
    }

    public function test_someone_who_cannot_prepare_is_told_why_and_no_warning_is_shown(): void
    {
        config(['agents.prepare_enabled' => true]);
        $this->restock();
        $this->preparer->cannotReason = 'Only a CA Supervisor at this branch prepares this.';
        $this->preparer->warningText = 'should not show';
        $this->selectBranch($this->actor('finance_officer'));

        $this->get(route('agents.inbox'))->assertInertia(fn (Assert $page) => $page
            ->where('proposals.0.prepare.canPrepare', false)->where('proposals.0.prepare.cannotPrepareReason', 'Only a CA Supervisor at this branch prepares this.')->where('proposals.0.prepare.warning', null));
    }

    public function test_a_transfer_is_decided_at_the_sending_branch_and_offers_quantity_and_both_locations(): void
    {
        config(['agents.prepare_enabled' => true]);
        $this->transfer();
        $sender = $this->actor('ca_supervisor', $this->puchong);
        $this->selectBranch($sender, $this->puchong);

        $this->get(route('agents.inbox'))->assertInertia(fn (Assert $page) => $page
            ->where('proposals.0.prepare.actionLabel', 'Approve and create the stock request')
            ->where('proposals.0.prepare.edits.maxQuantity', '2.000')
            ->where('proposals.0.prepare.edits.source', $this->senderLocation->public_id)
            ->where('proposals.0.prepare.edits.destination', $this->f['location']->public_id)
            ->where('proposals.0.prepare.edits.sourceLocations', fn ($rows) => collect($rows)->pluck('id')->contains($this->senderLocation->public_id) && ! collect($rows)->pluck('id')->contains($this->f['location']->public_id))
            ->where('proposals.0.prepare.edits.destinationLocations', fn ($rows) => collect($rows)->pluck('id')->contains($this->f['location']->public_id) && ! collect($rows)->pluck('id')->contains($this->senderLocation->public_id))
            ->where('proposals.0.lines', fn ($lines) => collect($lines)->pluck('value')->contains('Synthetic sending dispensary')));

        $this->selectBranch($this->actor('ca_supervisor'), $this->branch);
        $this->get(route('agents.inbox'))->assertInertia(fn (Assert $page) => $page->where('counts.pending', 0), 'the receiving branch no longer holds the suggestion');
    }

    public function test_approving_with_prepare_creates_the_record_and_says_so(): void
    {
        config(['agents.prepare_enabled' => true]);
        $proposal = $this->transfer();
        $sender = $this->actor('ca_supervisor', $this->puchong);
        $this->selectBranch($sender, $this->puchong);

        $response = $this->post(route('agents.inbox.approve', $proposal), ['lock_version' => 0, 'mode' => 'prepare', 'edits' => ['quantity' => '1.5']]);

        $response->assertRedirect(route('agents.inbox', ['tab' => 'pending']))->assertSessionHas('inertia.flash_data.toast.message', 'Proposal approved. Stock request SR-000001 created.');
        $this->assertSame(['approved', 'SR-000001'], [$proposal->fresh()->status, $proposal->fresh()->prepared_record_label]);
        $this->assertSame('1.500', $this->preparer->calls[0]['values']['quantity']);

        $this->get(route('agents.inbox', ['tab' => 'approved']))->assertInertia(fn (Assert $page) => $page
            ->where('proposals.0.prepared', ['kind' => 'Stock request', 'label' => 'SR-000001', 'text' => 'Stock request SR-000001 created'])->where('proposals.0.prepare', null));
    }

    public function test_a_decision_only_approval_and_a_refused_prepare_keep_the_proposal_honest(): void
    {
        $proposal = $this->restock();
        $this->selectBranch($this->actor('ca_supervisor'));

        $this->post(route('agents.inbox.approve', $proposal), ['lock_version' => 0, 'mode' => 'prepare'])
            ->assertRedirect(route('agents.inbox', ['tab' => 'pending']))->assertSessionHasErrors('mode');
        $this->assertSame('pending', $proposal->fresh()->status);

        $this->post(route('agents.inbox.approve', $proposal), ['lock_version' => 0])
            ->assertSessionHas('inertia.flash_data.toast.message', 'Proposal approved. Nothing in stock has changed; act on it from the Inventory screens.');
        $this->assertSame(['approved', null], [$proposal->fresh()->status, $proposal->fresh()->prepared_record_type]);
        $this->assertSame([], $this->preparer->calls);
    }
}
