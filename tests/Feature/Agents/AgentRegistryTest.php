<?php

namespace Tests\Feature\Agents;

use App\Domain\Access\PermissionCatalogue;
use App\Domain\Agents\AgentPermissions;
use App\Domain\Agents\Models\AiAgent;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Organisation\Models\Organisation;
use Database\Seeders\KPOneAgentSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Inertia\Testing\AssertableInertia as Assert;
use LogicException;
use Spatie\Permission\Models\Role;
use Tests\Feature\Visit\VisitTestCase;

/** AG-1: permissions, registry page, enable/disable and the synthetic agent seeder. */
class AgentRegistryTest extends VisitTestCase
{
    private const HOLDERS = [
        AgentPermissions::VIEW => ['administrator', 'director', 'technical_admin'],
        AgentPermissions::MANAGE => ['director'],
        AgentPermissions::ASSISTANT => ['director'],
        AgentPermissions::PROPOSALS_VIEW => ['ca_supervisor', 'director', 'finance_officer'],
        AgentPermissions::DAILY_SUMMARY_VIEW => ['administrator', 'ca_supervisor', 'director', 'finance_officer'],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(KPOneAgentSeeder::class);
    }

    private function agent(string $code = 'inventory_analyst'): AiAgent
    {
        return AiAgent::query()->where('organisation_id', $this->organisation->id)->where('code', $code)->firstOrFail();
    }

    public function test_catalogue_grants_exactly_the_contract_holders_and_none_is_people_authority(): void
    {
        foreach (self::HOLDERS as $permission => $holders) {
            $this->assertContains($permission, PermissionCatalogue::all());
            $this->assertSame($holders, collect(PermissionCatalogue::roles())->filter(fn ($p) => in_array($permission, $p, true))->keys()->sort()->values()->all(), $permission);
            $this->assertFalse(PermissionCatalogue::isAdministrativeAuthority($permission));
            foreach ($holders as $role) {
                $this->assertTrue(Role::findByName($role)->hasPermissionTo($permission), "{$role} {$permission}");
            }
        }
    }

    public function test_grant_migration_is_replay_safe_logs_a_missing_role_and_never_revokes(): void
    {
        $migration = require database_path('migrations/2026_11_05_100100_grant_ai_agent_permissions.php');
        $migration->up();
        $migration->up();
        $migration->down();
        foreach (array_keys(PermissionCatalogue::roles()) as $role) {
            foreach (self::HOLDERS as $permission => $holders) {
                $this->assertSame(in_array($role, $holders, true), Role::findByName($role)->hasPermissionTo($permission), "{$role} {$permission}");
            }
        }

        Role::findByName('administrator')->givePermissionTo(AgentPermissions::MANAGE);
        $migration->up();
        $this->assertTrue(Role::findByName('administrator')->hasPermissionTo(AgentPermissions::MANAGE), 'never revokes');

        Role::findByName('finance_officer')->delete();
        Log::spy();
        $migration->up();
        Log::shouldHaveReceived('warning')->with('Agent permission grant skipped missing role', ['role' => 'finance_officer', 'permission' => AgentPermissions::PROPOSALS_VIEW])->once();
    }

    public function test_registry_page_is_limited_to_viewers_and_only_the_director_may_manage(): void
    {
        foreach (array_keys(PermissionCatalogue::roles()) as $role) {
            $actor = $this->actor($role);
            $this->selectBranch($actor);
            $viewer = in_array($role, self::HOLDERS[AgentPermissions::VIEW], true);
            $response = $this->get(route('agents.index'));
            $response->assertStatus($viewer ? 200 : 403);
            $this->getJson(route('agents.office-feed'))->assertStatus($viewer ? 200 : 403);
            if ($viewer) {
                $response->assertInertia(fn (Assert $page) => $page->component('Agents/Index')
                    ->where('canManage', $role === 'director')
                    ->has('agents', 6)
                    ->has('activity', 0));
            }
        }
    }

    public function test_every_registered_agent_is_labelled_as_an_ai_agent(): void
    {
        $this->selectBranch($this->actor('director'));
        $this->get(route('agents.index'))->assertInertia(fn (Assert $page) => $page
            ->where('agents', fn ($agents) => collect($agents)->every(fn ($agent) => str_contains($agent['jobTitle'], '(AI agent)'))));

        $this->assertDatabaseRefuses(fn () => DB::table('ai_agents')->where('id', $this->agent()->id)->update(['job_title' => 'Inventory Analyst']));
    }

    public function test_director_disables_and_enables_with_lock_version_and_audit(): void
    {
        $director = $this->actor('director');
        $this->selectBranch($director);
        $agent = $this->agent();

        $this->post(route('agents.enabled', $agent), ['is_enabled' => false, 'lock_version' => 0])->assertRedirect(route('agents.index'));
        $agent->refresh();
        $this->assertFalse($agent->is_enabled);
        $this->assertSame(1, $agent->lock_version);
        $audit = AuditLog::query()->where('event', 'ai_agent.disabled')->sole();
        $this->assertSame($director->id, $audit->actor_user_id);
        $this->assertSame(['agent_id' => $agent->id, 'agent_code' => 'inventory_analyst', 'lock_version' => 1], $audit->metadata);

        $this->post(route('agents.enabled', $agent), ['is_enabled' => true, 'lock_version' => 0])
            ->assertRedirect(route('agents.index'))->assertSessionHasErrors('lock_version');
        $this->assertFalse($agent->refresh()->is_enabled);

        $this->post(route('agents.enabled', $agent), ['is_enabled' => true, 'lock_version' => 1])->assertRedirect(route('agents.index'));
        $this->assertTrue($agent->refresh()->is_enabled);
        $this->assertSame(2, $agent->lock_version);
        $this->assertSame(1, AuditLog::query()->where('event', 'ai_agent.enabled')->count());

        $before = AuditLog::query()->count();
        $this->post(route('agents.enabled', $agent), ['is_enabled' => true, 'lock_version' => 2])->assertRedirect(route('agents.index'));
        $this->assertSame(2, $agent->refresh()->lock_version, 'no-op keeps the version');
        $this->assertSame($before, AuditLog::query()->count());
    }

    public function test_viewers_without_manage_and_other_organisations_are_refused(): void
    {
        $agent = $this->agent();
        foreach (['administrator', 'technical_admin'] as $role) {
            $this->selectBranch($this->actor($role));
            $this->post(route('agents.enabled', $agent), ['is_enabled' => false, 'lock_version' => 0])->assertForbidden();
        }
        $this->assertTrue($agent->refresh()->is_enabled);

        $other = new Organisation;
        $other->forceFill(['code' => 'SYNTHETIC_OTHER', 'name' => 'Synthetic Other Clinic', 'is_active' => true])->save();
        $foreign = new AiAgent;
        $foreign->forceFill(['organisation_id' => $other->id, 'code' => 'inventory_analyst', 'display_name' => 'Other', 'job_title' => 'Other (AI agent)', 'kind' => 'runtime'])->save();
        $this->selectBranch($this->actor('director'));
        $this->post(route('agents.enabled', $foreign), ['is_enabled' => false, 'lock_version' => 0])->assertNotFound();
        $this->assertTrue($foreign->refresh()->is_enabled);
    }

    public function test_an_agent_is_never_deleted_and_keeps_its_code(): void
    {
        $agent = $this->agent();
        try {
            $agent->delete();
            $this->fail('Model delete must be refused.');
        } catch (LogicException) {
        }
        $this->assertDatabaseRefuses(fn () => DB::table('ai_agents')->where('id', $agent->id)->delete());
        $this->assertDatabaseRefuses(fn () => DB::table('ai_agents')->where('id', $agent->id)->update(['code' => 'renamed_agent']));
        $this->assertDatabaseRefuses(fn () => DB::statement('TRUNCATE ai_agents CASCADE'));
        $this->assertDatabaseHas('ai_agents', ['id' => $agent->id, 'code' => 'inventory_analyst']);
    }

    public function test_seeder_is_idempotent_keeps_the_enabled_state_and_refuses_production(): void
    {
        $this->assertSame(array_keys(KPOneAgentSeeder::AGENTS), AiAgent::query()->orderBy('id')->pluck('code')->all());
        $this->assertSame(['clinic.day_summary', 'insights.sales_summary', 'insights.today', 'inventory.stock_review', 'money.day_summary', 'panel.claims_summary'], $this->agent('director_assistant')->tools()->orderBy('tool_code')->pluck('tool_code')->all());
        $this->assertSame([], $this->agent('qa_reviewer')->tools()->pluck('tool_code')->all());
        $this->assertSame('build', $this->agent('qa_reviewer')->kind);
        $this->assertSame('Leadership', $this->agent('report_assistant')->department?->name);

        DB::table('ai_agents')->where('code', 'stocktake_officer')->update(['is_enabled' => false, 'lock_version' => 3]);
        $this->seed(KPOneAgentSeeder::class);
        $this->assertSame(6, AiAgent::query()->count());
        $this->assertSame(15, DB::table('ai_agent_tools')->count());
        $this->assertFalse($this->agent('stocktake_officer')->is_enabled);
        $this->assertSame(3, $this->agent('stocktake_officer')->lock_version);

        $this->app['env'] = 'production';
        try {
            $this->expectException(LogicException::class);
            (new KPOneAgentSeeder)->run();
        } finally {
            $this->app['env'] = 'testing';
        }
    }

    private function assertDatabaseRefuses(callable $statement): void
    {
        try {
            DB::transaction($statement);
        } catch (QueryException) {
            $this->addToAssertionCount(1);

            return;
        }
        $this->fail('The database accepted a statement it must refuse.');
    }
}
