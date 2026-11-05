<?php

namespace Tests\Feature\Agents;

use App\Domain\Access\PermissionCatalogue;
use App\Domain\Agents\AgentPermissions;
use App\Domain\Agents\Models\AiAgentRun;
use App\Domain\Agents\Services\AgentOfficeDetail;
use App\Domain\Agents\Services\AgentRunRecorder;
use App\Domain\Organisation\Models\Branch;
use Database\Seeders\KPOneAgentSeeder;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Visit\VisitTestCase;

/** AG-3: the 2.5D agent office page and its click panel details. */
class AgentOfficePageTest extends VisitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(KPOneAgentSeeder::class);
    }

    private function proposal(AiAgentRun $run, string $status, string $key): void
    {
        DB::table('ai_proposals')->insert([
            'organisation_id' => $run->organisation_id, 'branch_id' => $this->branch->id, 'ai_agent_run_id' => $run->id, 'ai_agent_id' => $run->ai_agent_id,
            'type' => 'inventory.restock_suggestion', 'payload' => encrypt('{"sku_public_id":"secret-payload"}', false), 'payload_hash' => str_repeat('a', 64),
            'status' => $status, 'decided_at' => $status === 'approved' ? now() : null, 'decided_by_user_id' => $status === 'approved' ? $this->actor('director')->id : null,
            'lock_version' => $status === 'approved' ? 1 : 0, 'idempotency_key' => $key, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_the_office_is_limited_to_agent_registry_viewers(): void
    {
        $viewers = array_keys(array_filter(PermissionCatalogue::roles(), fn (array $permissions): bool => in_array(AgentPermissions::VIEW, $permissions, true)));
        $this->assertNotEmpty($viewers);
        foreach (array_keys(PermissionCatalogue::roles()) as $role) {
            $this->selectBranch($this->actor($role));
            $viewer = in_array($role, $viewers, true);
            $page = $this->get(route('agents.office'))->assertStatus($viewer ? 200 : 403);
            $this->getJson(route('agents.office.agent', 'inventory_analyst'))->assertStatus($viewer ? 200 : 403);
            if ($viewer) {
                $page->assertInertia(fn (Assert $inertia) => $inertia->component('Agents/Office')
                    ->has('feed.agents', 6)
                    ->has('feed.messages', 0)
                    ->where('feed.pendingProposals', 0)
                    ->has('canOpenInbox'));
                $this->assertStringContainsString('no-store', (string) $page->headers->get('Cache-Control'));
            }
        }
    }

    public function test_the_page_feed_matches_the_office_feed(): void
    {
        $this->selectBranch($this->actor('director'));
        $recorder = app(AgentRunRecorder::class);
        $run = $recorder->start('inventory_analyst', 'daily_stock_review', $this->branch);
        $recorder->step($run, 'decision', 'Proposed 1 restock suggestion');
        $recorder->message($run, 'director_assistant', '1 item below reorder level');

        $this->travelTo(now()->startOfSecond());
        $feed = $this->getJson(route('agents.office-feed'))->assertOk()->json();
        $this->get(route('agents.office'))->assertInertia(fn (Assert $page) => $page->where('feed', $feed)->where('canOpenInbox', true));
    }

    public function test_the_panel_shows_the_last_five_steps_and_pending_proposals_without_payloads(): void
    {
        $this->selectBranch($this->actor('director'));
        $recorder = app(AgentRunRecorder::class);
        $old = $recorder->start('inventory_analyst', 'daily_stock_review', $this->branch);
        $recorder->step($old, 'note', 'Old step');
        $recorder->complete($old);
        $run = $recorder->start('inventory_analyst', 'daily_stock_review', $this->branch);
        for ($i = 1; $i <= 6; $i++) {
            $recorder->step($run, 'note', "Step {$i}");
        }
        $this->proposal($run, 'pending', 'office-pending-1');
        $this->proposal($run, 'pending', 'office-pending-2');
        $this->proposal($run, 'approved', 'office-approved-1');
        $recorder->step($recorder->start('stocktake_officer', 'stock_count_check', $this->branch), 'note', 'Another agent');

        $response = $this->getJson(route('agents.office.agent', 'inventory_analyst'))->assertOk();
        $this->assertSame(['code', 'displayName', 'jobTitle', 'department', 'isEnabled', 'steps', 'pendingTotal', 'pendingBranch', 'pending'], array_keys($response->json()));
        $this->assertSame(['Step 6', 'Step 5', 'Step 4', 'Step 3', 'Step 2'], array_column($response->json('steps'), 'summary'));
        $this->assertSame(AgentOfficeDetail::STEP_LIMIT, count($response->json('steps')));
        $this->assertSame(['kind', 'summary', 'at'], array_keys($response->json('steps.0')));
        $this->assertSame(2, $response->json('pendingTotal'));
        $this->assertSame($this->branch->name, $response->json('pendingBranch'));
        $this->assertSame(['id', 'typeLabel', 'createdAt'], array_keys($response->json('pending.0')));
        $this->assertSame('Restock suggestion', $response->json('pending.0.typeLabel'));
        $this->assertStringNotContainsString('secret-payload', $response->getContent());
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    public function test_proposal_rows_follow_the_inbox_rule_branch_viewers_at_their_active_branch_only(): void
    {
        $recorder = app(AgentRunRecorder::class);
        $run = $recorder->start('inventory_analyst', 'daily_stock_review', $this->branch);
        $this->proposal($run, 'pending', 'office-scope-1');

        // An organisation viewer without ai_proposals.view.branch sees the count only, never the rows.
        $this->selectBranch($this->actor('administrator'));
        $this->assertFalse($this->actor('administrator')->can(AgentPermissions::PROPOSALS_VIEW));
        $admin = $this->getJson(route('agents.office.agent', 'inventory_analyst'))->assertOk();
        $this->assertSame([1, null, []], [$admin->json('pendingTotal'), $admin->json('pendingBranch'), $admin->json('pending')]);
        $this->get(route('agents.office'))->assertInertia(fn (Assert $page) => $page->where('canOpenInbox', false));

        // The Director, who decides, sees the rows of the active branch only.
        $director = $this->actor('director');
        $this->selectBranch($director);
        $this->assertSame(1, count($this->getJson(route('agents.office.agent', 'inventory_analyst'))->json('pending')));
        $otherBranch = DB::table('branches')->where('organisation_id', $this->organisation->id)->where('id', '<>', $this->branch->id)->orderBy('id')->first();
        $this->assertNotNull($otherBranch);
        $elsewhere = $recorder->start('inventory_analyst', 'daily_stock_review', Branch::query()->findOrFail($otherBranch->id));
        DB::table('ai_proposals')->insert([
            'organisation_id' => $this->organisation->id, 'branch_id' => $otherBranch->id, 'ai_agent_run_id' => $elsewhere->id, 'ai_agent_id' => $elsewhere->ai_agent_id,
            'type' => 'inventory.expiry_warning', 'payload' => encrypt('{}', false), 'payload_hash' => str_repeat('b', 64),
            'status' => 'pending', 'lock_version' => 0, 'idempotency_key' => 'office-scope-2', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $json = $this->getJson(route('agents.office.agent', 'inventory_analyst'))->json();
        $this->assertSame(2, $json['pendingTotal']);
        $this->assertSame(['Restock suggestion'], array_column($json['pending'], 'typeLabel'), 'another branch\'s proposal is never listed');
    }

    public function test_the_panel_never_shows_another_organisations_agent_and_refuses_unknown_codes(): void
    {
        $this->selectBranch($this->actor('director'));
        $other = DB::table('organisations')->insertGetId(['code' => 'SYNTHETIC_OTHER', 'name' => 'Synthetic Other Clinic', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('ai_agents')->insert(['organisation_id' => $other, 'code' => 'foreign_agent', 'display_name' => 'Foreign', 'job_title' => 'Foreign (AI agent)', 'kind' => 'runtime', 'is_enabled' => true, 'lock_version' => 0, 'created_at' => now(), 'updated_at' => now()]);

        $this->getJson(route('agents.office.agent', 'foreign_agent'))->assertNotFound();
        $this->getJson('/agents/office/agents/not_an_agent')->assertNotFound();
        $this->getJson('/agents/office/agents/Bad-Code')->assertNotFound();
        $this->get(route('agents.office'))->assertInertia(fn (Assert $page) => $page->has('feed.agents', 6));
    }

    public function test_the_office_changes_nothing(): void
    {
        $this->selectBranch($this->actor('director'));
        $before = [DB::table('ai_agent_runs')->count(), DB::table('ai_agent_run_steps')->count(), DB::table('audit_logs')->count()];
        $this->get(route('agents.office'))->assertOk();
        $this->getJson(route('agents.office.agent', 'inventory_analyst'))->assertOk();
        $this->assertSame($before, [DB::table('ai_agent_runs')->count(), DB::table('ai_agent_run_steps')->count(), DB::table('audit_logs')->count()]);
    }
}
