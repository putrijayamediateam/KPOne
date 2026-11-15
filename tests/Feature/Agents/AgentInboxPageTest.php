<?php

namespace Tests\Feature\Agents;

use App\Domain\Access\PermissionCatalogue;
use App\Domain\Agents\Proposals\AiProposal;
use App\Domain\Agents\Proposals\ProposalService;
use App\Domain\Agents\Services\AgentRunRecorder;
use App\Domain\Agents\Tools\AggregatePayloadGuard;
use App\Domain\Organisation\Inventory\Models\InventorySku;
use App\Domain\Organisation\Models\Branch;
use Database\Seeders\KPOneAgentSeeder;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Visit\VisitTestCase;
use Tests\Support\GovernedStockFixture;

/** AG-2: the /agents/inbox page, its tabs and its Approve and Reject actions. */
class AgentInboxPageTest extends VisitTestCase
{
    private AiProposal $proposal;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(KPOneAgentSeeder::class);
        $f = (new GovernedStockFixture($this->branch))->build('3.000');
        $run = app(AgentRunRecorder::class)->start('inventory_analyst', 'daily_stock_review', $this->branch);
        $this->proposal = app(ProposalService::class)->propose($run, 'inventory.restock_suggestion', $this->branch, [
            'sku_public_id' => $f['sku']->public_id, 'location_public_id' => $f['location']->public_id, 'current_quantity' => '3.000',
            'reorder_level' => '10.000', 'suggested_quantity' => '7.000', 'reason_code' => 'at_or_below_reorder_level',
        ], 'page-restock-0001');
    }

    public function test_only_proposal_viewers_open_the_inbox_and_only_deciders_see_the_buttons(): void
    {
        $viewers = ['ca_supervisor', 'director', 'finance_officer'];
        foreach (array_keys(PermissionCatalogue::roles()) as $role) {
            $this->selectBranch($this->actor($role));
            $response = $this->get(route('agents.inbox'));
            $response->assertStatus(in_array($role, $viewers, true) ? 200 : 403);
            if (in_array($role, $viewers, true)) {
                $response->assertInertia(fn (Assert $page) => $page->component('Agents/Inbox')
                    ->where('tab', 'pending')->where('counts', ['pending' => 1, 'approved' => 0, 'rejected' => 0])
                    ->where('proposals.0.typeLabel', 'Restock suggestion')->where('proposals.0.agent', 'Hana')
                    ->where('proposals.0.canDecide', true)
                    ->where('proposals.0.lines', fn ($lines) => collect($lines)->pluck('value')->contains('Synthetic governed item ('.InventorySku::query()->value('sku_code').')')));
            }
        }
    }

    public function test_approve_and_reject_through_the_page_and_tabs_follow_the_status(): void
    {
        $supervisor = $this->actor('ca_supervisor');
        $this->selectBranch($supervisor);
        $this->post(route('agents.inbox.reject', $this->proposal), ['lock_version' => 0])
            ->assertRedirect(route('agents.inbox', ['tab' => 'pending']))->assertSessionHasErrors('reason');
        $this->post(route('agents.inbox.approve', $this->proposal), ['lock_version' => 0, 'reason' => 'Order placed with supplier'])
            ->assertRedirect(route('agents.inbox', ['tab' => 'pending']));
        $this->assertSame('approved', $this->proposal->refresh()->status);

        $this->get(route('agents.inbox', ['tab' => 'approved']))->assertInertia(fn (Assert $page) => $page
            ->where('counts', ['pending' => 0, 'approved' => 1, 'rejected' => 0])
            ->where('proposals.0.decidedBy', 'You')->where('proposals.0.reason', 'Order placed with supplier')->where('proposals.0.canDecide', false));
        $this->selectBranch($this->actor('director'));
        $this->get(route('agents.inbox', ['tab' => 'approved']))->assertInertia(fn (Assert $page) => $page->where('proposals.0.decidedBy', 'Staff #'.$supervisor->id));
        $this->get(route('agents.inbox', ['tab' => 'bogus']))->assertSessionHasErrors('tab');
    }

    public function test_the_page_carries_no_personal_data_keys_and_other_organisations_are_not_reachable(): void
    {
        $this->selectBranch($this->actor('director'));
        $props = $this->get(route('agents.inbox'))->viewData('page')['props'];
        app(AggregatePayloadGuard::class)->assertAggregate(['proposals' => array_map(fn (array $p) => array_diff_key($p, ['reason' => true]), $props['proposals'])]);

        $this->post(route('agents.inbox.approve', 999999), ['lock_version' => 0])->assertNotFound();
    }

    public function test_the_inbox_shows_only_the_active_assigned_branch_and_never_another_organisation(): void
    {
        $puchong = Branch::query()->where('organisation_id', $this->organisation->id)->where('code', 'PUCHONG')->firstOrFail();
        $this->selectBranch($this->actor('ca_supervisor', $puchong), $puchong);
        $this->get(route('agents.inbox'))->assertOk()->assertInertia(fn (Assert $page) => $page->where('counts.pending', 0)->has('proposals', 0));

        $director = $this->actor('director', $puchong);
        $this->selectBranch($director, $this->branch);
        $this->get(route('agents.inbox'))->assertForbidden();

        $db = DB::class;
        $other = $db::table('organisations')->insertGetId(['code' => 'SYNTHETIC_OTHER', 'name' => 'Synthetic Other Clinic', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $branch = $db::table('branches')->insertGetId(['organisation_id' => $other, 'code' => 'FOREIGN', 'name' => 'Foreign', 'timezone' => 'Asia/Kuala_Lumpur', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $agent = $db::table('ai_agents')->insertGetId(['organisation_id' => $other, 'code' => 'inventory_analyst', 'display_name' => 'Foreign', 'job_title' => 'Foreign (AI agent)', 'kind' => 'runtime', 'is_enabled' => true, 'lock_version' => 0, 'created_at' => now(), 'updated_at' => now()]);
        $run = $db::table('ai_agent_runs')->insertGetId(['organisation_id' => $other, 'branch_id' => $branch, 'ai_agent_id' => $agent, 'task_type' => 'daily_stock_review', 'status' => 'working', 'started_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        $foreign = $db::table('ai_proposals')->insertGetId(['organisation_id' => $other, 'branch_id' => $branch, 'ai_agent_run_id' => $run, 'ai_agent_id' => $agent,
            'type' => 'inventory.restock_suggestion', 'payload' => encrypt('{}', false), 'payload_hash' => str_repeat('c', 64), 'status' => 'pending', 'lock_version' => 0,
            'idempotency_key' => 'foreign-0001', 'created_at' => now(), 'updated_at' => now()]);

        $this->selectBranch($this->actor('ca_supervisor'));
        $this->get(route('agents.inbox'))->assertInertia(fn (Assert $page) => $page->where('counts.pending', 1)->where('proposals.0.id', $this->proposal->id));
        $this->post(route('agents.inbox.approve', $foreign), ['lock_version' => 0])->assertNotFound();
        $this->post(route('agents.inbox.reject', $foreign), ['lock_version' => 0, 'reason' => 'Not ours'])->assertNotFound();
        $this->assertSame('pending', $db::table('ai_proposals')->where('id', $foreign)->value('status'));
    }

    public function test_the_person_who_asked_for_the_run_sees_no_buttons_and_is_told_why_if_they_try(): void
    {
        $director = $this->actor('director');
        $f = (new GovernedStockFixture($this->branch))->build('2.000');
        $run = app(AgentRunRecorder::class)->start('inventory_analyst', 'daily_stock_review', $this->branch, $director, 'ai_proposals.view.branch');
        $own = app(ProposalService::class)->propose($run, 'inventory.restock_suggestion', $this->branch, [
            'sku_public_id' => $f['sku']->public_id, 'location_public_id' => $f['location']->public_id, 'current_quantity' => '2',
            'reorder_level' => '10', 'suggested_quantity' => '8', 'reason_code' => 'at_or_below_reorder_level',
        ], 'page-own-0001');
        $this->selectBranch($director);
        $this->get(route('agents.inbox'))->assertInertia(fn (Assert $page) => $page
            ->where('proposals.0.id', $own->id)->where('proposals.0.canDecide', false)
            ->where('proposals.0.lines', fn ($lines) => collect($lines)->pluck('value')->contains('8 units') && collect($lines)->pluck('value')->contains('10 unit')));
        $this->post(route('agents.inbox.approve', $own), ['lock_version' => 0])->assertRedirect(route('agents.inbox', ['tab' => 'pending']))->assertSessionHasErrors('status');
        $this->assertSame('pending', $own->refresh()->status);
    }
}
