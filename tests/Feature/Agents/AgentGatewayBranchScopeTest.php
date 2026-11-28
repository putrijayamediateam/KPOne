<?php

namespace Tests\Feature\Agents;

use App\Domain\Agents\Models\AiAgent;
use App\Domain\Agents\Models\AiAgentRun;
use App\Domain\Agents\Models\AiAgentTool;
use App\Domain\Agents\Services\AgentRunRecorder;
use App\Domain\Agents\Tools\AgentToolGateway;
use App\Domain\Organisation\Models\Branch;
use App\Models\User;
use Database\Seeders\KPOneAgentSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Tests\Feature\Visit\VisitTestCase;

/**
 * INS-BM-2 (follow-up to INS-BM-1, owner decision 27 Nov 2026, option A): an agent data source never gives a branch-only
 * person (no `branch_context.switch.organisation`) another branch's or the whole organisation's figures. The Insights
 * data sources need only the organisation-level `insights.view.organisation`, which every role holds, so the gateway
 * keeps a branch-only requester to a branch where it works today. Organisation accounts are unchanged.
 */
final class AgentGatewayBranchScopeTest extends VisitTestCase
{
    /** Every data source that reads clinic figures under the organisation-level Insights permission. */
    private const INSIGHTS_SOURCES = [
        'insights.today',
        'insights.sales_summary',
        'clinic.day_summary',
        'money.day_summary',
        'panel.claims_summary',
        'connector.visits_by_state',
        'connector.queue_now',
        'connector.panel_claims_made',
    ];

    private const BRANCH_ONLY_ROLES = ['branch_manager', 'ca', 'ca_supervisor', 'resident_doctor', 'medical_assistant'];

    private AgentToolGateway $gateway;

    private AiAgent $agent;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(KPOneAgentSeeder::class);
        $this->gateway = app(AgentToolGateway::class);
        $this->agent = new AiAgent;
        $this->agent->forceFill(['organisation_id' => $this->organisation->id, 'code' => 'test_scope_reader', 'display_name' => 'Test', 'job_title' => 'Test reader (AI agent)', 'kind' => 'runtime'])->save();
        foreach (self::INSIGHTS_SOURCES as $code) {
            AiAgentTool::query()->create(['ai_agent_id' => $this->agent->id, 'tool_code' => $code]);
        }
    }

    private function agentRun(?User $requester = null): AiAgentRun
    {
        return app(AgentRunRecorder::class)->start($this->agent, 'test_read', null, $requester, $requester ? 'insights.view.organisation' : null, organisationId: $this->organisation->id);
    }

    private function puchong(): Branch
    {
        return Branch::query()->where('organisation_id', $this->organisation->id)->where('code', 'PUCHONG')->firstOrFail();
    }

    private function assertRefused(callable $read, string $case): void
    {
        try {
            $read();
            $this->fail("Must be refused: {$case}.");
        } catch (AuthorizationException) {
            $this->addToAssertionCount(1);
        }
    }

    public function test_a_branch_only_person_reads_insights_sources_only_for_their_own_branch(): void
    {
        foreach (self::BRANCH_ONLY_ROLES as $role) {
            $person = $this->actor($role);
            $this->assertTrue($person->can('insights.view.organisation'), $role);
            $run = $this->agentRun($person);
            foreach (self::INSIGHTS_SOURCES as $code) {
                $this->assertRefused(fn () => $this->gateway->call($run, $code, $person), "{$role} {$code} with no branch (the whole organisation)");
                $this->assertRefused(fn () => $this->gateway->call($run, $code, $person, $this->puchong()), "{$role} {$code} for another branch");
                $this->assertIsArray($this->gateway->call($run, $code, $person, $this->branch), "{$role} {$code} for its own branch");
            }
        }
    }

    public function test_a_branch_only_person_with_no_branch_in_effect_today_is_refused(): void
    {
        $ca = $this->actor('ca');
        $ca->staffProfile->branchAssignments()->update(['valid_from' => now()->subDays(5)->toDateString(), 'valid_until' => now()->subDays(1)->toDateString()]);
        $run = $this->agentRun($ca);
        $this->assertRefused(fn () => $this->gateway->call($run, 'insights.today', $ca, $this->branch), 'an ended assignment');
    }

    public function test_an_organisation_account_still_reads_any_branch_and_the_whole_organisation(): void
    {
        $director = $this->actor('director');
        $run = $this->agentRun($director);
        $this->assertSame('all', $this->gateway->call($run, 'clinic.day_summary', $director)['scope']);
        $this->assertSame('all', $this->gateway->call($run, 'insights.today', $director)['scope']);
        $this->assertSame('PUCHONG', $this->gateway->call($run, 'insights.today', $director, $this->puchong())['scope']);
    }
}
