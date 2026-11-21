<?php

namespace Tests\Feature\Agents;

use App\Domain\Agents\Models\AiAgent;
use App\Domain\Agents\Models\AiAgentTool;
use App\Domain\Agents\Registry\AgentCatalogue;
use App\Domain\Agents\Tools\AgentToolRegistry;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Organisation\Models\Organisation;
use Database\Seeders\KPOneAgentSeeder;
use Tests\Feature\Visit\VisitTestCase;

/** AG-36: `kpone:register-agents` registers the contract agents on a real server, safely and repeatably. */
class AgentRegistrationTest extends VisitTestCase
{
    public function test_it_registers_the_contract_agents_disabled_with_their_allowlists(): void
    {
        $this->artisan('kpone:register-agents')->assertSuccessful();

        $this->assertSame(array_keys(AgentCatalogue::AGENTS), AiAgent::query()->orderBy('id')->pluck('code')->all());
        foreach (AgentCatalogue::AGENTS as $code => [$name, $title, $department, $kind, $tools]) {
            $agent = AiAgent::query()->where('code', $code)->firstOrFail();
            $this->assertSame($name, $agent->display_name);
            $this->assertStringEndsWith('(AI agent)', $agent->job_title);
            $this->assertSame($kind, $agent->kind);
            $this->assertFalse($agent->is_enabled, $code);
            $this->assertEqualsCanonicalizing($tools, AiAgentTool::query()->where('ai_agent_id', $agent->id)->pluck('tool_code')->all(), $code);
        }
        $audit = AuditLog::query()->where('event', 'ai_agents.registered')->sole();
        $this->assertNull($audit->actor_user_id);
        $this->assertSame(array_keys(AgentCatalogue::AGENTS), $audit->metadata['created']);
    }

    public function test_enable_creates_them_enabled(): void
    {
        $this->artisan('kpone:register-agents', ['--enable' => true])->assertSuccessful();

        $this->assertSame(0, AiAgent::query()->where('is_enabled', false)->count());
    }

    public function test_a_second_run_changes_nothing(): void
    {
        $this->artisan('kpone:register-agents')->assertSuccessful();
        $before = [AiAgent::query()->orderBy('id')->get()->toArray(), AiAgentTool::query()->count(), AuditLog::query()->count()];

        $this->artisan('kpone:register-agents')->expectsOutput('Nothing to do.')->assertSuccessful();

        $this->assertSame($before, [AiAgent::query()->orderBy('id')->get()->toArray(), AiAgentTool::query()->count(), AuditLog::query()->count()]);
    }

    public function test_an_existing_agent_keeps_its_code_title_enabled_state_and_version(): void
    {
        $this->artisan('kpone:register-agents')->assertSuccessful();
        $hana = AiAgent::query()->where('code', 'inventory_analyst')->firstOrFail();
        $hana->forceFill(['display_name' => 'Hana Renamed', 'job_title' => 'Custom title (AI agent)', 'is_enabled' => true, 'lock_version' => 4])->save();

        $this->artisan('kpone:register-agents', ['--enable' => false, '--add-missing-tools' => true])->assertSuccessful();

        $hana->refresh();
        $this->assertSame(['Hana Renamed', 'Custom title (AI agent)', true, 4], [$hana->display_name, $hana->job_title, $hana->is_enabled, $hana->lock_version]);
    }

    public function test_missing_tools_are_added_only_on_request_and_never_removed(): void
    {
        $this->artisan('kpone:register-agents')->assertSuccessful();
        $rizal = AiAgent::query()->where('code', 'stocktake_officer')->firstOrFail();
        AiAgentTool::query()->where('ai_agent_id', $rizal->id)->delete();
        $extra = AiAgent::query()->where('code', 'technical_admin_agent')->firstOrFail();
        AiAgentTool::query()->create(['ai_agent_id' => $extra->id, 'tool_code' => 'insights.today']);

        $this->artisan('kpone:register-agents')->expectsOutputToContain('lacks inventory.stock_counts')->assertSuccessful();
        $this->assertSame(0, AiAgentTool::query()->where('ai_agent_id', $rizal->id)->count());

        $this->artisan('kpone:register-agents', ['--add-missing-tools' => true])->assertSuccessful();
        $this->assertSame(['inventory.stock_counts'], AiAgentTool::query()->where('ai_agent_id', $rizal->id)->pluck('tool_code')->all());
        $this->assertContains('insights.today', AiAgentTool::query()->where('ai_agent_id', $extra->id)->pluck('tool_code')->all());
    }

    public function test_a_dry_run_writes_nothing(): void
    {
        $this->artisan('kpone:register-agents', ['--dry-run' => true])->expectsOutputToContain('Would create director_assistant (disabled)')->assertSuccessful();

        $this->assertSame(0, AiAgent::query()->count());
        $this->assertSame(0, AuditLog::query()->where('event', 'ai_agents.registered')->count());
    }

    public function test_production_needs_force_and_a_dry_run_does_not(): void
    {
        $this->app['env'] = 'production';

        $this->artisan('kpone:register-agents')->assertFailed();
        $this->assertSame(0, AiAgent::query()->count());
        $this->artisan('kpone:register-agents', ['--dry-run' => true])->assertSuccessful();
        $this->artisan('kpone:register-agents', ['--force' => true])->assertSuccessful();
        $this->assertSame(count(AgentCatalogue::AGENTS), AiAgent::query()->count());
    }

    public function test_it_refuses_without_exactly_one_organisation_and_writes_nothing(): void
    {
        Organisation::query()->where('code', 'KLINIK_PUTRIJAYA')->update(['code' => 'OTHER']);

        $this->artisan('kpone:register-agents')->assertFailed();
        $this->assertSame(0, AiAgent::query()->count());
    }

    public function test_the_seeder_and_the_command_share_one_list_of_known_data_sources(): void
    {
        $this->assertSame(AgentCatalogue::AGENTS, KPOneAgentSeeder::AGENTS);
        $known = array_keys(app(AgentToolRegistry::class)->all());
        foreach (AgentCatalogue::AGENTS as $code => [, $title, , , $tools]) {
            $this->assertStringEndsWith('(AI agent)', $title, $code);
            foreach ($tools as $tool) {
                $this->assertContains($tool, $known, $code);
            }
        }
    }
}
