<?php

namespace Tests\Feature\Agents;

use App\Domain\Agents\Models\AiAgent;
use App\Domain\Agents\Models\AiAgentRun;
use App\Domain\Agents\Models\AiAgentTool;
use App\Domain\Agents\Services\AgentRunRecorder;
use App\Domain\Agents\Tools\AgentToolGateway;
use App\Domain\Agents\Tools\AgentToolRegistry;
use App\Domain\Agents\Tools\AggregatePayloadGuard;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Organisation\Models\Organisation;
use App\Models\User;
use Database\Seeders\KPOneAgentSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use LogicException;
use Tests\Feature\Visit\VisitTestCase;

/** AG-4: the data source boundary — permission, branch, allowlist, input, step log and the aggregate-only scan. */
class AgentToolGatewayTest extends VisitTestCase
{
    /** Contract section 5, less `system.health` (AG-10). */
    private const CONTRACT = [
        'insights.today' => 'insights.view.organisation',
        'insights.sales_summary' => 'insights.view.organisation',
        'inventory.stock_review' => 'inventory.view.branch',
        'inventory.branch_balances' => 'inventory.view.branch',
        'inventory.dispensing_activity' => 'inventory.view.branch',
        'inventory.stock_counts' => 'inventory.view.branch',
        'clinic.day_summary' => 'insights.view.organisation',
        'money.day_summary' => 'insights.view.organisation',
        'panel.claims_summary' => 'insights.view.organisation',
    ];

    private AgentToolGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(KPOneAgentSeeder::class);
        $this->gateway = app(AgentToolGateway::class);
    }

    private function allTools(): AiAgent
    {
        $agent = new AiAgent;
        $agent->forceFill(['organisation_id' => $this->organisation->id, 'code' => 'test_all_tools', 'display_name' => 'Test', 'job_title' => 'Test reader (AI agent)', 'kind' => 'runtime'])->save();
        foreach (array_keys(self::CONTRACT) as $code) {
            AiAgentTool::query()->create(['ai_agent_id' => $agent->id, 'tool_code' => $code]);
        }

        return $agent;
    }

    private function agentRun(AiAgent|string $agent, ?Branch $branch = null, ?User $requester = null): AiAgentRun
    {
        return app(AgentRunRecorder::class)->start($agent, 'test_read', $branch, $requester, $requester ? 'insights.view.organisation' : null, organisationId: $this->organisation->id);
    }

    private function puchong(): Branch
    {
        return Branch::query()->where('organisation_id', $this->organisation->id)->where('code', 'PUCHONG')->firstOrFail();
    }

    public function test_registry_holds_exactly_the_contract_data_sources_and_permissions(): void
    {
        $tools = app(AgentToolRegistry::class)->all();
        $this->assertSame(array_keys(self::CONTRACT), array_keys($tools));
        foreach ($tools as $code => $tool) {
            $this->assertSame(self::CONTRACT[$code], $tool->requiredPermission(), $code);
        }
        $this->expectException(InvalidArgumentException::class);
        app(AgentToolRegistry::class)->get('system.unknown');
    }

    public function test_seeded_agent_allowlists_only_name_known_data_sources(): void
    {
        $known = [...array_keys(self::CONTRACT), 'system.health'];
        foreach (DB::table('ai_agent_tools')->pluck('tool_code') as $code) {
            $this->assertContains($code, $known);
        }
    }

    public function test_every_data_source_returns_aggregate_keys_only_for_a_director(): void
    {
        $agent = $this->allTools();
        $director = $this->actor('director');
        $patient = $this->patient($director);
        $this->register($this->actor('ca'), $patient);
        $run = $this->agentRun($agent, $this->branch, $director);
        foreach (array_keys(self::CONTRACT) as $code) {
            $result = $this->gateway->call($run, $code, $director, $this->branch);
            app(AggregatePayloadGuard::class)->assertAggregate($result);
            $json = json_encode($result, JSON_THROW_ON_ERROR);
            foreach ([$patient->full_name, $patient->patient_number, $director->email] as $personal) {
                $this->assertStringNotContainsString((string) $personal, $json, $code);
            }
        }
    }

    public function test_the_agent_allowlist_enabled_state_and_run_are_enforced(): void
    {
        $director = $this->actor('director');
        $arif = $this->agentRun('director_assistant', $this->branch, $director);
        $lina = $this->agentRun('report_assistant', $this->branch, $director);
        $someoneElses = $this->agentRun('director_assistant', $this->branch, $this->actor('director'));
        $finished = app(AgentRunRecorder::class)->complete($this->agentRun('director_assistant', $this->branch, $director));
        $refusals = [
            'not on allowlist' => fn () => $this->gateway->call($arif, 'money.day_summary', $director, $this->branch),
            'run asked for by someone else' => fn () => $this->gateway->call($someoneElses, 'insights.today', $director, $this->branch),
            'run for another branch' => fn () => $this->gateway->call($arif, 'insights.today', $director, $this->puchong()),
            'finished run' => fn () => $this->gateway->call($finished, 'insights.today', $director, $this->branch),
        ];
        DB::table('ai_agents')->where('code', 'report_assistant')->update(['is_enabled' => false]);
        $refusals['disabled agent'] = fn () => $this->gateway->call($lina, 'money.day_summary', $director, $this->branch);
        foreach ($refusals as $case => $refused) {
            try {
                $refused();
                $this->fail("Must be refused: {$case}.");
            } catch (AuthorizationException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame(0, DB::table('ai_agent_run_steps')->count(), 'a refused read leaves no step');
        $this->assertSame('CHERAS', $this->gateway->call($arif, 'insights.today', $director)['scope'], 'the run branch is used when none is given');
    }

    public function test_the_requester_permission_and_branch_are_enforced(): void
    {
        $agent = $this->allTools();
        $supervisor = $this->actor('ca_supervisor');
        $other = new Organisation;
        $other->forceFill(['code' => 'SYNTHETIC_OTHER', 'name' => 'Synthetic Other Clinic', 'is_active' => true])->save();
        $foreignBranch = new Branch;
        $foreignBranch->forceFill(['organisation_id' => $other->id, 'code' => 'FOREIGN', 'name' => 'Foreign', 'timezone' => 'Asia/Kuala_Lumpur', 'is_active' => true])->save();
        $scheduled = $this->agentRun($agent);
        $refusals = [
            'no insights permission' => fn () => $this->gateway->call($scheduled, 'insights.today', $this->actor('queue_display'), $this->branch),
            'no inventory permission' => fn () => $this->gateway->call($scheduled, 'inventory.stock_review', $this->actor('panel_officer'), $this->branch),
            'branch tool without a branch' => fn () => $this->gateway->call($scheduled, 'inventory.stock_review', $supervisor),
            'branch without assignment' => fn () => $this->gateway->call($scheduled, 'inventory.stock_review', $supervisor, $this->puchong()),
            'branch of another organisation' => fn () => $this->gateway->call($scheduled, 'insights.today', $supervisor, $foreignBranch),
        ];
        $inactive = $this->actor('ca_supervisor');
        DB::table('users')->where('id', $inactive->id)->update(['is_active' => false]);
        $refusals['inactive requester'] = fn () => $this->gateway->call($scheduled, 'insights.today', $inactive->refresh(), $this->branch);
        foreach ($refusals as $case => $refused) {
            try {
                $refused();
                $this->fail("Must be refused: {$case}.");
            } catch (AuthorizationException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame('CHERAS', $this->gateway->call($scheduled, 'inventory.stock_review', $supervisor, $this->branch)['branch']);
        $this->assertSame('all', $this->gateway->call($scheduled, 'clinic.day_summary', $supervisor)['scope']);
    }

    public function test_input_is_validated_against_the_tool_schema(): void
    {
        $director = $this->actor('director');
        $run = $this->agentRun($this->allTools(), $this->branch, $director);
        foreach ([
            ['inventory.dispensing_activity', ['window_days' => 0]],
            ['inventory.dispensing_activity', ['window_days' => 400]],
            ['clinic.day_summary', ['date' => '05/11/2026']],
            ['insights.sales_summary', ['from' => '2026-11-05', 'to' => '2026-11-01']],
            ['insights.sales_summary', ['from' => '2026-01-01', 'to' => '2026-12-31']],
        ] as [$code, $input]) {
            try {
                $this->gateway->call($run, $code, $director, $this->branch, $input);
                $this->fail("Must be refused: {$code}.");
            } catch (ValidationException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_every_read_is_recorded_as_a_data_read_step_on_its_run(): void
    {
        $director = $this->actor('director');
        $recorder = app(AgentRunRecorder::class);
        $run = $recorder->start('director_assistant', 'quick_answer', $this->branch, $director, 'ai_assistant.use.organisation');
        $this->gateway->call($run, 'insights.today', $director, $this->branch);
        $this->gateway->call($run, 'inventory.stock_review', $director, $this->branch);
        $steps = DB::table('ai_agent_run_steps')->where('ai_agent_run_id', $run->id)->orderBy('sequence')->get();
        $this->assertSame([['data_read', 'insights.today', 'Read insights.today for CHERAS'], ['data_read', 'inventory.stock_review', 'Read inventory.stock_review for CHERAS']],
            $steps->map(fn ($step) => [$step->kind, $step->tool_code, $step->summary])->all());
    }

    public function test_the_payload_guard_refuses_personal_keys_at_any_depth(): void
    {
        $guard = app(AggregatePayloadGuard::class);
        foreach ([['patientCount' => 1], ['rows' => [['doctor_name' => 'x']]], ['a' => ['b' => ['email' => 'x']]], ['nric' => 'x'], ['displayName' => 'x'], ['diagnosisCode' => 'x'], ['phone' => 'x'], ['note' => 'x'],
            ['ICNumber' => 'x'], ['NRICNo' => 'x'], ['emails' => []], ['notes' => []], ['addresses' => []], ['fullname' => 'x'], ['username' => 'x'], ['kpNumber' => 'x'], ['visitNumber' => 'x'], ['mykad' => 'x'], ['mrn' => 'x'],
            ['locationLabel' => 'Bilik 012-345 6789'], ['item' => 'Call +60123456789'], ['panel' => 'IC 900101-14-5678'], ['method' => 'someone@example.test']] as $payload) {
            try {
                $guard->assertAggregate($payload);
                $this->fail('Must be refused: '.json_encode($payload));
            } catch (LogicException) {
                $this->addToAssertionCount(1);
            }
        }
        $guard->assertAggregate(['skuCode' => 'SYN-ABC123', 'item' => 'Paracetamol 500 mg', 'panel' => 'Synthetic Panel', 'doctor' => 'Dr Synthetic', 'uniqueVisitors' => 1, 'date' => '2026-11-05',
            'quantity' => '1250.500', 'sku' => '12345678-1234-1234-1234-123456789012', 'branches' => [['branch' => 'CHERAS', 'averageWaitMinutes' => 12]]]);
    }
}
