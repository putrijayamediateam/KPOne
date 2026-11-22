<?php

namespace Tests\Feature\Agents;

use App\Domain\Audit\Models\AuditLog;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Passport\ClientRepository;
use Laravel\Passport\Token;
use Tests\Feature\Visit\VisitTestCase;

/** CN-4: the Connected apps page lists and disconnects only the signed-in person's own connections. */
class ConnectedAppsTest extends VisitTestCase
{
    private function connect(User $user, string $name = 'Claude', bool $revoked = false): string
    {
        $client = app(ClientRepository::class)->createAuthorizationCodeGrantClient($name, ['https://claude.ai/api/mcp/auth_callback'], false, null, false);
        Token::query()->forceCreate(['id' => bin2hex(random_bytes(40)), 'user_id' => $user->id, 'client_id' => $client->id, 'scopes' => ['mcp:use'], 'revoked' => $revoked, 'expires_at' => now()->addHour()]);

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
        $this->connect($director);
        $audit = AuditLog::query()->forceCreate(['event' => 'ai_connector.tool_called', 'actor_user_id' => $director->id, 'organisation_id' => $director->organisation_id, 'occurred_at' => now(), 'metadata' => ['tool' => 'connector.list_branches']]);

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
}
