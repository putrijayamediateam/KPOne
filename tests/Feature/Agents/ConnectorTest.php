<?php

namespace Tests\Feature\Agents;

use App\Domain\Access\PermissionCatalogue;
use App\Domain\Agents\AgentPermissions;
use App\Domain\Agents\Connector\ConnectorAgent;
use App\Domain\Agents\Connector\ConnectorToolRunner;
use App\Domain\Agents\Models\AiAgentRun;
use App\Domain\Audit\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Passport\ClientRepository;
use Laravel\Passport\Passport;
use Laravel\Passport\Token;
use Spatie\Permission\PermissionRegistrar;
use Tests\Feature\Visit\VisitTestCase;

/** CN-1: the KPOne connector foundation (docs/agents/MCP-CONNECTOR-DESIGN.md): kill switch, permission, OAuth, MCP, audit, limits. */
class ConnectorTest extends VisitTestCase
{
    private string $keys;

    protected function setUp(): void
    {
        parent::setUp();
        $this->keys = sys_get_temp_dir().'/kpone-connector-keys-'.getmypid();
        if (! is_dir($this->keys)) {
            mkdir($this->keys, 0700, true);
            $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
            openssl_pkey_export($key, $private);
            file_put_contents($this->keys.'/oauth-private.key', $private);
            file_put_contents($this->keys.'/oauth-public.key', openssl_pkey_get_details($key)['key']);
            chmod($this->keys.'/oauth-private.key', 0600);
            chmod($this->keys.'/oauth-public.key', 0600);
        }
        Passport::loadKeysFrom($this->keys);
        config(['agents.connector_enabled' => true]);
    }

    /** @return array<string, string> */
    private function headers(): array
    {
        return ['Accept' => 'application/json, text/event-stream', 'MCP-Protocol-Version' => '2025-11-25', 'Mcp-Method' => 'tools/call', 'Mcp-Name' => 'list_branches'];
    }

    /** @return array<string, mixed> */
    private function message(): array
    {
        return ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'list_branches', 'arguments' => (object) []]];
    }

    private function director(): User
    {
        return $this->actor('director');
    }

    private function callAs(User $user)
    {
        Passport::actingAs($user, ['mcp:use'], 'api');

        return $this->postJson('/mcp', $this->message(), $this->headers());
    }

    public function test_every_connector_route_answers_404_while_the_switch_is_off(): void
    {
        config(['agents.connector_enabled' => false]);
        $director = $this->director();
        Passport::actingAs($director, ['mcp:use'], 'api');

        $this->postJson('/mcp', $this->message(), $this->headers())->assertNotFound();
        $this->getJson('/.well-known/oauth-protected-resource')->assertNotFound();
        $this->getJson('/.well-known/oauth-authorization-server')->assertNotFound();
        $this->postJson('/oauth/register', ['redirect_uris' => ['https://claude.ai/api/mcp/auth_callback']])->assertNotFound();
        $this->postJson('/oauth/token', [])->assertNotFound();
        $this->actingAs($director)->get('/oauth/authorize')->assertNotFound();
    }

    public function test_discovery_names_the_kpone_endpoints_and_one_read_scope(): void
    {
        $metadata = $this->getJson('/.well-known/oauth-authorization-server')->assertOk()->json();

        $this->assertStringEndsWith('/oauth/token', $metadata['token_endpoint']);
        $this->assertStringEndsWith('/oauth/authorize', $metadata['authorization_endpoint']);
        $this->assertStringEndsWith('/oauth/register', $metadata['registration_endpoint']);
        $this->assertSame(['mcp:use'], $metadata['scopes_supported']);
        $this->assertSame(['S256'], $metadata['code_challenge_methods_supported']);
    }

    public function test_the_example_environment_ships_the_switch_off(): void
    {
        $env = file_get_contents(base_path('.env.example'));
        $this->assertStringContainsString("AGENTS_CONNECTOR_ENABLED=false\n", $env);
    }

    public function test_a_call_without_a_token_is_refused(): void
    {
        $this->postJson('/mcp', $this->message(), $this->headers())->assertUnauthorized();
    }

    public function test_the_director_lists_branches_and_the_call_is_recorded_without_the_results(): void
    {
        $director = $this->director();

        $response = $this->callAs($director)->assertOk();
        $text = (string) $response->json('result.content.0.text');
        $this->assertStringContainsString('CHERAS', $text);
        $this->assertFalse((bool) $response->json('result.isError'));

        $run = AiAgentRun::query()->where('requested_by_user_id', $director->id)->firstOrFail();
        $this->assertSame('completed', $run->status);
        $this->assertSame('connector_call', $run->task_type);
        $this->assertSame(['connector.list_branches'], $run->steps()->pluck('tool_code')->all());
        $this->assertSame(ConnectorAgent::CODE, $run->agent()->firstOrFail()->code);

        $audit = AuditLog::query()->where('event', 'ai_connector.tool_called')->firstOrFail();
        $this->assertSame($director->id, $audit->actor_user_id);
        $this->assertEqualsCanonicalizing(['tool', 'outcome', 'rows'], array_keys($audit->metadata));
        $this->assertSame('ok', $audit->metadata['outcome']);
        $this->assertStringNotContainsString('CHERAS', json_encode($audit->metadata));
    }

    public function test_only_branches_the_director_works_at_are_listed(): void
    {
        $director = $this->director();
        $text = (string) $this->callAs($director)->json('result.content.0.text');

        $this->assertStringContainsString('CHERAS', $text);
        $this->assertStringNotContainsString('PUCHONG', $text);
    }

    public function test_every_other_role_is_refused(): void
    {
        foreach (['ca', 'ca_supervisor', 'finance_officer', 'administrator', 'technical_admin', 'resident_doctor', 'panel_officer', 'hr_manager'] as $role) {
            $this->callAs($this->actor($role))->assertForbidden();
        }
        $this->assertSame(0, AiAgentRun::query()->count());
    }

    public function test_an_inactive_director_or_one_who_lost_the_permission_stops_working_with_a_valid_token(): void
    {
        $director = $this->director();
        $this->callAs($director)->assertOk();

        $director->forceFill(['is_active' => false])->save();
        $this->callAs($director)->assertForbidden();

        $director->forceFill(['is_active' => true])->save();
        $director->roles()->detach();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->callAs(User::query()->findOrFail($director->id))->assertForbidden();
    }

    public function test_a_disabled_connector_agent_refuses_the_call_and_audits_it(): void
    {
        $director = $this->director();
        $this->callAs($director)->assertOk();
        ConnectorAgent::ensure($this->organisation->id)->forceFill(['is_enabled' => false])->save();

        $result = $this->callAs($director)->assertOk();
        $this->assertTrue((bool) $result->json('result.isError'));
        $this->assertSame('agent_unavailable', AuditLog::query()->where('event', 'ai_connector.tool_called')->latest('id')->firstOrFail()->metadata['reason']);
    }

    public function test_the_daily_limit_refuses_and_is_audited(): void
    {
        $director = $this->director();
        for ($i = 0; $i < ConnectorToolRunner::DAILY_LIMIT; $i++) {
            RateLimiter::hit('connector-day:'.$director->id, 86400);
        }

        $result = $this->callAs($director);
        $this->assertTrue((bool) $result->json('result.isError'));
        $this->assertSame('rate_limited_day', AuditLog::query()->where('event', 'ai_connector.tool_called')->firstOrFail()->metadata['reason']);
        $this->assertSame(0, AiAgentRun::query()->count());
    }

    public function test_the_permission_is_the_directors_alone_and_is_not_people_or_access_authority(): void
    {
        $this->assertSame(['director'], AgentPermissions::grants()[AgentPermissions::CONNECTOR_USE]);
        $this->assertNotContains(AgentPermissions::CONNECTOR_USE, PermissionCatalogue::AUTHORITY_OVER_PEOPLE_AND_ACCESS);
        foreach (PermissionCatalogue::roles() as $role => $permissions) {
            $this->assertSame($role === 'director', in_array(AgentPermissions::CONNECTOR_USE, $permissions, true), $role);
        }
    }

    public function test_the_permission_migration_is_additive_and_repeatable(): void
    {
        $migration = require database_path('migrations/2026_11_21_100100_grant_ai_connector_permission.php');
        $migration->up();
        $migration->up();

        $this->assertTrue($this->actor('director')->can(AgentPermissions::CONNECTOR_USE));
        $this->assertFalse($this->actor('ca')->can(AgentPermissions::CONNECTOR_USE));
    }

    public function test_only_the_connectors_own_redirect_origins_may_register_a_client(): void
    {
        $this->postJson('/oauth/register', ['client_name' => 'Test app', 'redirect_uris' => ['https://evil.example/callback']])
            ->assertStatus(400)->assertJsonPath('error', 'invalid_redirect_uri');
        $this->postJson('/oauth/register', ['client_name' => 'Test app', 'redirect_uris' => ['myapp://callback']])
            ->assertStatus(400);
        $this->postJson('/oauth/register', ['client_name' => 'Test app', 'redirect_uris' => ['https://claude.ai/api/mcp/auth_callback']])
            ->assertCreated()->assertJsonPath('token_endpoint_auth_method', 'none');
    }

    public function test_the_consent_screen_is_for_the_permission_holder_only_and_has_no_write_scope(): void
    {
        $client = app(ClientRepository::class)->createAuthorizationCodeGrantClient('Test app', ['https://claude.ai/api/mcp/auth_callback'], false, null, false);
        $query = http_build_query([
            'client_id' => $client->id, 'redirect_uri' => 'https://claude.ai/api/mcp/auth_callback', 'response_type' => 'code', 'scope' => 'mcp:use',
            'state' => 'abc', 'code_challenge' => str_repeat('a', 43), 'code_challenge_method' => 'S256',
        ]);

        $this->actingAs($this->actor('ca'))->get('/oauth/authorize?'.$query)->assertForbidden();
        $this->actingAs($this->director())->get('/oauth/authorize?'.$query)
            ->assertOk()->assertSee('Allow')->assertSee('cannot change anything in KPOne')->assertSee('can never see')->assertSee('switch the connector off')->assertHeader('Cache-Control');
        $this->assertSame(['mcp:use'], array_keys(Passport::scopes()->mapWithKeys(fn ($scope) => [$scope->id => true])->all()));
    }

    public function test_the_revoke_command_revokes_one_users_tokens_or_everyones(): void
    {
        $a = $this->director();
        $b = $this->director();
        $client = app(ClientRepository::class)->createAuthorizationCodeGrantClient('Test app', ['https://claude.ai/api/mcp/auth_callback'], false, null, false);
        foreach ([$a, $b] as $i => $user) {
            Token::query()->forceCreate(['id' => str_repeat((string) ($i + 1), 80), 'user_id' => $user->id, 'client_id' => $client->id, 'scopes' => ['mcp:use'], 'revoked' => false, 'expires_at' => now()->addHour()]);
        }

        $this->artisan('agents:connector-revoke', ['--user' => $a->email])->expectsOutput('Revoked 1 token(s).')->assertSuccessful();
        $this->assertTrue((bool) Token::query()->where('user_id', $a->id)->value('revoked'));
        $this->assertFalse((bool) Token::query()->where('user_id', $b->id)->value('revoked'));

        $this->artisan('agents:connector-revoke')->expectsOutput('Revoked 1 token(s).')->assertSuccessful();
        $this->assertSame(0, Token::query()->where('revoked', false)->count());
        $this->assertSame(2, AuditLog::query()->where('event', 'ai_connector.tokens_revoked')->count());
        foreach (AuditLog::query()->where('event', 'ai_connector.tokens_revoked')->get() as $log) {
            $this->assertStringNotContainsString(str_repeat('1', 20), json_encode($log->metadata));
        }
    }

    public function test_connector_tool_classes_never_call_a_write_method(): void
    {
        foreach (glob(app_path('Domain/Agents/Connector/{ListBranchesTool,ConnectorTools}.php'), GLOB_BRACE) + glob(app_path('Domain/Agents/Connector/Mcp/*Tool.php')) as $file) {
            $source = file_get_contents($file);
            foreach (['->save(', '->update(', '->delete(', '->insert(', '->create(', 'forceFill(', 'DB::statement', 'DB::insert', 'DB::update', 'DB::delete'] as $write) {
                $this->assertStringNotContainsString($write, $source, basename($file).' must stay read-only: '.$write);
            }
        }
    }
}
