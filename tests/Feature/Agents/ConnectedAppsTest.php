<?php

namespace Tests\Feature\Agents;

use App\Domain\Audit\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Passport\ClientRepository;
use Laravel\Passport\Passport;
use Laravel\Passport\Token;
use Tests\Feature\Visit\VisitTestCase;

/** CN-4: the Connected apps page lists and disconnects only the signed-in person's own connections. */
class ConnectedAppsTest extends VisitTestCase
{
    private function connect(User $user, string $name = 'Claude', bool $revoked = false, ?string $expiresAt = null, bool $refresh = false): string
    {
        $client = app(ClientRepository::class)->createAuthorizationCodeGrantClient($name, ['https://claude.ai/api/mcp/auth_callback'], false, null, false);
        $id = bin2hex(random_bytes(40));
        Token::query()->forceCreate(['id' => $id, 'user_id' => $user->id, 'client_id' => $client->id, 'scopes' => ['mcp:use'], 'revoked' => $revoked, 'expires_at' => $expiresAt ?? now()->addHour()]);
        if ($refresh) {
            DB::table('oauth_refresh_tokens')->insert(['id' => bin2hex(random_bytes(40)), 'access_token_id' => $id, 'revoked' => false, 'expires_at' => now()->addDays(30)]);
        }

        return (string) $client->id;
    }

    public function test_a_person_sees_only_their_own_live_connections(): void
    {
        $director = $this->actor('director');
        $other = $this->actor('technical_admin');
        $mine = $this->connect($director);
        $this->connect($director, 'Old app', true);
        $this->connect($other, 'Someone else');

        $this->actingAs($director)->get('/agents/connected-apps')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Agents/ConnectedApps')
            ->has('apps', 1)
            ->where('apps.0.clientId', $mine)
            ->where('apps.0.lastAskedAt', null)
            ->has('questions', 11)
            ->etc());
    }

    public function test_last_asked_comes_from_the_connectors_audit_rows(): void
    {
        $director = $this->actor('director');
        $app = $this->connect($director);
        $audit = AuditLog::query()->forceCreate(['event' => 'ai_connector.tool_called', 'actor_user_id' => $director->id, 'organisation_id' => $director->organisation_id, 'occurred_at' => now(), 'metadata' => ['tool' => 'connector.list_branches', 'client_id' => $app]]);

        $this->actingAs($director)->get('/agents/connected-apps')->assertInertia(fn (Assert $page) => $page
            ->where('apps.0.lastAskedAt', fn ($value): bool => $value !== null && str_starts_with($value, $audit->occurred_at->format('Y-m-d')))
            ->etc());
    }

    public function test_only_holders_of_the_connector_permission_can_open_the_page(): void
    {
        foreach (['ca_supervisor', 'finance_officer', 'technical_admin'] as $role) {
            $this->actingAs($this->actor($role))->get('/agents/connected-apps')->assertForbidden();
        }
    }

    public function test_disconnect_revokes_only_that_apps_tokens_for_this_person_and_audits_counts(): void
    {
        $director = $this->actor('director');
        $other = $this->actor('technical_admin');
        $app = $this->connect($director);
        $kept = $this->connect($director, 'Second app');
        $this->connect($other, 'Someone else');

        $this->actingAs($director)->delete('/agents/connected-apps/'.$app)->assertRedirect('/agents/connected-apps');

        $this->assertSame(0, Token::query()->where('user_id', $director->id)->where('client_id', $app)->where('revoked', false)->count());
        $this->assertSame(1, Token::query()->where('user_id', $director->id)->where('client_id', $kept)->where('revoked', false)->count());
        $this->assertSame(1, Token::query()->where('user_id', $other->id)->where('revoked', false)->count());
        $log = AuditLog::query()->where('event', 'ai_connector.tokens_revoked')->latest('id')->firstOrFail();
        $this->assertSame('one_app', $log->metadata['scope']);
        $this->assertSame(1, $log->metadata['tokens']);
    }

    public function test_disconnecting_twice_or_someone_elses_app_changes_nothing(): void
    {
        $director = $this->actor('director');
        $other = $this->actor('technical_admin');
        $app = $this->connect($director);
        $theirs = $this->connect($other, 'Theirs');

        $this->actingAs($director)->delete('/agents/connected-apps/'.$theirs)->assertRedirect('/agents/connected-apps');
        $this->assertSame(1, Token::query()->where('user_id', $other->id)->where('revoked', false)->count());

        $this->actingAs($director)->delete('/agents/connected-apps/'.$app)->assertRedirect();
        $this->actingAs($director)->delete('/agents/connected-apps/'.$app)->assertRedirect('/agents/connected-apps');
    }

    public function test_the_page_opens_with_the_connector_switched_off_and_says_so(): void
    {
        config(['agents.connector_enabled' => false]);

        $this->actingAs($this->actor('director'))->get('/agents/connected-apps')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('connectorEnabled', false)->etc());
    }

    public function test_the_agents_page_links_to_connected_apps_only_for_the_permission_holder(): void
    {
        $this->actingAs($this->actor('director'))->get('/agents')->assertInertia(fn (Assert $page) => $page->where('canConnect', true)->etc());
        $this->actingAs($this->actor('administrator'))->get('/agents')->assertInertia(fn (Assert $page) => $page->where('canConnect', false)->etc());
    }

    public function test_an_app_stays_listed_while_its_refresh_token_is_live_even_after_the_access_token_expired(): void
    {
        $director = $this->actor('director');
        $live = $this->connect($director, 'Renewable', false, now()->subHour()->toDateTimeString(), true);
        $this->connect($director, 'Lapsed', false, now()->subHour()->toDateTimeString(), false);

        $this->actingAs($director)->get('/agents/connected-apps')->assertInertia(fn (Assert $page) => $page
            ->has('apps', 1)->where('apps.0.clientId', $live)->etc());

        $this->actingAs($director)->delete('/agents/connected-apps/'.$live)->assertRedirect();
        $this->assertSame(0, DB::table('oauth_refresh_tokens')->where('revoked', false)->count());
        $this->actingAs($director)->get('/agents/connected-apps')->assertInertia(fn (Assert $page) => $page->has('apps', 0)->etc());
    }

    public function test_connected_on_is_the_first_time_and_last_asked_belongs_to_that_app_and_that_person(): void
    {
        $director = $this->actor('director');
        $other = $this->actor('technical_admin');
        $first = $this->connect($director, 'First app');
        $second = $this->connect($director, 'Second app');
        Token::query()->where('client_id', $first)->update(['created_at' => '2026-01-02 03:04:05']);
        $record = fn (User $who, ?string $client) => AuditLog::query()->forceCreate(['event' => 'ai_connector.tool_called', 'actor_user_id' => $who->id, 'organisation_id' => $who->organisation_id, 'occurred_at' => now(), 'metadata' => array_filter(['tool' => 'connector.list_branches', 'client_id' => $client])]);
        $record($director, $first);
        $record($other, $second);

        $this->actingAs($director)->get('/agents/connected-apps')->assertInertia(fn (Assert $page) => $page
            ->where('apps.0.clientId', $first)->where('apps.0.connectedAt', fn ($value): bool => str_starts_with((string) $value, '2026-01-02'))->where('apps.0.lastAskedAt', fn ($value): bool => $value !== null)
            ->where('apps.1.clientId', $second)->where('apps.1.lastAskedAt', null)->etc());
    }

    public function test_the_connector_records_which_app_asked(): void
    {
        config(['agents.connector_enabled' => true]);
        $director = $this->actor('director');
        $client = app(ClientRepository::class)->createAuthorizationCodeGrantClient('Claude', ['https://claude.ai/api/mcp/auth_callback'], false, null, false);
        $keys = sys_get_temp_dir().'/kpone-connector-keys-'.getmypid();
        if (! is_dir($keys)) {
            mkdir($keys, 0700, true);
            $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
            openssl_pkey_export($key, $private);
            file_put_contents($keys.'/oauth-private.key', $private);
            file_put_contents($keys.'/oauth-public.key', openssl_pkey_get_details($key)['key']);
            chmod($keys.'/oauth-private.key', 0600);
            chmod($keys.'/oauth-public.key', 0600);
        }
        Passport::loadKeysFrom($keys);
        Passport::actingAs($director, ['mcp:use'], 'api', $client);
        $this->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'list_branches', 'arguments' => (object) []]], [
            'Accept' => 'application/json, text/event-stream', 'MCP-Protocol-Version' => '2025-11-25', 'Mcp-Method' => 'tools/call', 'Mcp-Name' => 'list_branches',
        ])->assertOk();

        $this->assertSame((string) $client->id, AuditLog::query()->where('event', 'ai_connector.tool_called')->latest('id')->firstOrFail()->metadata['client_id']);
    }

    public function test_disconnect_audit_names_the_app_but_holds_no_token_and_a_no_op_writes_nothing(): void
    {
        $director = $this->actor('director');
        $app = $this->connect($director);

        $this->actingAs($director)->delete('/agents/connected-apps/'.$app)->assertRedirect();
        $this->actingAs($director)->delete('/agents/connected-apps/'.$app)->assertRedirect();
        $this->actingAs($director)->delete('/agents/connected-apps/0')->assertNotFound();

        $logs = AuditLog::query()->where('event', 'ai_connector.tokens_revoked')->get();
        $this->assertCount(1, $logs);
        $this->assertSame($app, $logs[0]->metadata['client_id']);
        $this->assertSame($director->organisation_id, $logs[0]->organisation_id);
        $this->assertStringNotContainsString('"id"', json_encode($logs[0]->metadata));
    }

    public function test_an_app_id_of_zero_never_widens_to_every_app(): void
    {
        $director = $this->actor('director');
        $app = $this->connect($director);

        $this->actingAs($director)->delete('/agents/connected-apps/0')->assertNotFound();

        $this->assertSame(1, Token::query()->where('client_id', $app)->where('revoked', false)->count());
    }

    public function test_disconnect_needs_the_permission_and_a_signed_in_person(): void
    {
        $this->delete('/agents/connected-apps/'.Str::uuid())->assertRedirect('/login');
        $this->actingAs($this->actor('ca_supervisor'))->delete('/agents/connected-apps/'.Str::uuid())->assertForbidden();
    }
}
