<?php

namespace Tests\Feature\Agents;

use App\Domain\Agents\AgentPermissions;
use App\Domain\Agents\Ask\AiAskRequest;
use App\Domain\Agents\Ask\AskRequestService;
use App\Domain\Agents\Ask\AskSwitch;
use App\Domain\Agents\Models\AiAgentRun;
use App\Domain\Audit\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Laravel\Passport\Passport;
use Tests\Feature\Visit\VisitTestCase;

/** CHAT-2a: "Ask through Claude" and the connector's one write tool, save_answer (docs/agents/CHAT-2-ASK-THROUGH-CLAUDE-DESIGN.md). */
class AskThroughClaudeTest extends VisitTestCase
{
    private const NOT_VALID = 'That request id is not valid, has expired or was already answered. Ask again from the KPOne panel.';

    protected function setUp(): void
    {
        parent::setUp();
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
        config(['agents.connector_enabled' => true, 'agents.ask_claude_enabled' => true, 'agents.ask_claude_require_scope' => true]);
    }

    private function director(): User
    {
        return $this->actor('director');
    }

    /** @return array<string, mixed> */
    private function create(User $user, string $text = 'How many patients are waiting now?', ?string $key = null): array
    {
        return $this->actingAs($user)->postJson('/agents/ask', array_filter(['text' => $text, 'key' => $key]))->assertCreated()->json();
    }

    /** @param list<string> $scopes */
    private function mcp(User $user, string $method, array $params = [], array $scopes = ['mcp:use', 'mcp:answer'])
    {
        Passport::actingAs($user, $scopes, 'api');

        return $this->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => (object) $params], [
            'Accept' => 'application/json, text/event-stream', 'MCP-Protocol-Version' => '2025-11-25', 'Mcp-Method' => $method,
            'Mcp-Name' => (string) ($params['name'] ?? ''),
        ]);
    }

    /** @param list<string> $scopes @return array{0: bool, 1: string} [isError, text] */
    private function save(User $user, string $code, string $answer, array $scopes = ['mcp:use', 'mcp:answer']): array
    {
        $response = $this->mcp($user, 'tools/call', ['name' => 'save_answer', 'arguments' => ['request_id' => $code, 'answer_text' => $answer]], $scopes);
        if (in_array($response->status(), [400, 403, 404], true)) {
            return [true, 'refused: '.$response->status()];
        }
        $response->assertOk();

        return [(bool) $response->json('result.isError') || $response->json('error') !== null, (string) ($response->json('result.content.0.text') ?? $response->json('error.message'))];
    }

    /** @return list<string> */
    private function toolNames(User $user, array $scopes = ['mcp:use', 'mcp:answer']): array
    {
        $response = $this->mcp($user, 'tools/list', [], $scopes)->assertOk();

        return array_column($response->json('result.tools') ?? [], 'name');
    }

    public function test_it_is_off_by_default_and_everything_answers_404_or_hides_the_tool(): void
    {
        config(['agents.ask_claude_enabled' => false]);
        $director = $this->director();

        $this->actingAs($director)->getJson('/agents/ask')->assertNotFound();
        $this->actingAs($director)->postJson('/agents/ask', ['text' => 'Hello'])->assertNotFound();
        $this->assertNotContains('save_answer', $this->toolNames($director));
        $this->assertSame(0, AiAskRequest::query()->count());

        // On with the connector off is still off: the answer comes back through the connector.
        config(['agents.ask_claude_enabled' => true, 'agents.connector_enabled' => false]);
        $this->assertFalse(AskSwitch::enabled());
        $this->actingAs($director)->getJson('/agents/ask')->assertNotFound();
        $this->assertSame('0', (string) (int) config('agents.ask_claude_enabled' === false));
        $env = (string) file_get_contents(base_path('.env.example'));
        $this->assertStringContainsString("AGENTS_ASK_CLAUDE_ENABLED=false\n", $env);
    }

    public function test_only_the_director_may_ask_or_save(): void
    {
        foreach (['ca_supervisor', 'finance_officer', 'technical_admin', 'administrator', 'ca'] as $role) {
            $user = $this->actor($role);
            $this->actingAs($user)->getJson('/agents/ask')->assertForbidden();
            $this->actingAs($user)->postJson('/agents/ask', ['text' => 'Hello'])->assertForbidden();
        }
        $this->assertSame(0, AiAskRequest::query()->count());
        $this->assertSame(['director'], AgentPermissions::grants()[AgentPermissions::CHAT_USE]);
    }

    public function test_a_request_keeps_the_question_encrypted_and_builds_the_prompt_and_links(): void
    {
        $director = $this->director();
        $made = $this->create($director, 'How many patients are waiting now? & more <ok>');

        $this->assertSame('pending', $made['status']);
        $this->assertMatchesRegularExpression('/^[2-9A-HJKMNP-Z]{10}$/', $made['id']);
        $this->assertStringContainsString('Request id: '.$made['id'], $made['prompt']);
        $this->assertStringContainsString('<<<QUESTION'."\n".'How many patients are waiting now? & more <ok>'."\n".'QUESTION>>>', $made['prompt']);
        $this->assertStringContainsString('save_answer', $made['prompt']);
        $this->assertStringContainsString('Call list_branches and name each branch by its label', $made['prompt']);
        $this->assertStringStartsWith('claude://claude.ai/new?q=', $made['desktopLink']);
        $this->assertSame($made['prompt'], rawurldecode(substr($made['desktopLink'], strlen('claude://claude.ai/new?q='))));
        $this->assertSame('https://claude.ai/new', $made['webLink']);

        $raw = DB::table('ai_ask_requests')->where('request_code', $made['id'])->first();
        $this->assertStringNotContainsString('patients are waiting', (string) $raw->question);
        $this->assertNull($raw->answer);
        $this->assertSame(mb_strlen('How many patients are waiting now? & more <ok>'), (int) $raw->question_chars);
        $audit = AuditLog::query()->where('event', 'ai_ask.created')->latest('id')->firstOrFail();
        $this->assertStringNotContainsString('patients are waiting', json_encode($audit->metadata));
        $this->assertSame(['request', 'chars'], array_keys($audit->metadata));
    }

    public function test_bad_questions_are_refused_and_nothing_is_saved(): void
    {
        $director = $this->director();
        foreach (['', str_repeat('a', 1001)] as $text) {
            $this->actingAs($director)->postJson('/agents/ask', ['text' => $text])->assertStatus(422);
        }
        $this->actingAs($director)->postJson('/agents/ask', ['text' => 'Look up 900101-10-1234 please'])->assertStatus(422)->assertJsonPath('code', 'personal_data');
        $this->actingAs($director)->postJson('/agents/ask', ['text' => 'Call 012-345 6789'])->assertStatus(422);
        $this->actingAs($director)->postJson('/agents/ask', ['text' => 'mail me at a@b.test'])->assertStatus(422);
        $this->assertSame(0, AiAskRequest::query()->count());
    }

    public function test_the_same_key_returns_the_same_request_and_only_three_can_wait_at_once(): void
    {
        $director = $this->director();
        $first = $this->create($director, 'One?', 'key-aaaaaaaa');
        $again = $this->actingAs($director)->postJson('/agents/ask', ['text' => 'One?', 'key' => 'key-aaaaaaaa'])->assertCreated()->json();
        $this->assertSame($first['id'], $again['id']);
        $this->assertSame(1, AiAskRequest::query()->count());

        $this->create($director, 'Two?');
        $this->create($director, 'Three?');
        $this->actingAs($director)->postJson('/agents/ask', ['text' => 'Four?'])->assertStatus(429)->assertJsonPath('code', 'too_many_pending');
        $this->assertSame(3, AiAskRequest::query()->count());

        // A cancelled one frees a place; a stale one frees it too.
        $this->actingAs($director)->postJson('/agents/ask/'.$first['id'].'/cancel')->assertOk()->assertJsonPath('status', 'cancelled');
        $this->create($director, 'Four?');
        $this->travel(31)->minutes();
        $this->create($director, 'Five?');
        $this->assertSame(1, AiAskRequest::query()->where('status', 'pending')->count());
        $this->assertGreaterThanOrEqual(2, AiAskRequest::query()->where('status', 'expired')->count());
    }

    public function test_creating_is_limited_per_minute(): void
    {
        $director = $this->director();
        for ($i = 0; $i < 10; $i++) {
            RateLimiter::hit('ask-create-min:'.$director->id, 60);
        }
        $this->actingAs($director)->postJson('/agents/ask', ['text' => 'One more?'])->assertStatus(429)->assertJsonPath('code', 'rate_limited');
        $this->assertSame(0, AiAskRequest::query()->count());
    }

    public function test_a_person_only_sees_their_own_requests(): void
    {
        $one = $this->director();
        $two = $this->director();
        $mine = $this->create($one, 'Mine?');
        $this->actingAs($two)->getJson('/agents/ask/'.$mine['id'])->assertNotFound();
        $this->actingAs($two)->postJson('/agents/ask/'.$mine['id'].'/cancel')->assertNotFound();
        $this->assertSame([], $this->actingAs($two)->getJson('/agents/ask')->assertOk()->json('requests'));
        $this->actingAs($one)->getJson('/agents/ask/'.$mine['id'])->assertOk()->assertJsonPath('status', 'pending');
    }

    public function test_save_answer_saves_the_answer_once_and_the_panel_poll_shows_it(): void
    {
        $director = $this->director();
        $made = $this->create($director, 'How many patients are waiting now?');

        $this->assertContains('save_answer', $this->toolNames($director));
        [$error, $text] = $this->save($director, $made['id'], 'Nobody is waiting right now.');
        $this->assertFalse($error, $text);

        $poll = $this->actingAs($director)->getJson('/agents/ask/'.$made['id'])->assertOk()->assertJsonPath('status', 'answered')->assertJsonPath('answer', 'Nobody is waiting right now.');
        $this->assertSame('Answered by Claude. KPOne did not check this answer.', $poll->json('label'));
        $this->assertArrayNotHasKey('prompt', $poll->json());
        $raw = DB::table('ai_ask_requests')->where('request_code', $made['id'])->first();
        $this->assertStringNotContainsString('Nobody is waiting', (string) $raw->answer);

        // The same answer again is safe; a different answer after the first is refused and changes nothing.
        $this->assertFalse($this->save($director, $made['id'], 'Nobody is waiting right now.')[0]);
        [$error, $text] = $this->save($director, $made['id'], 'Actually five.');
        $this->assertTrue($error);
        $this->assertStringContainsString(self::NOT_VALID, $text);
        $this->assertSame('Nobody is waiting right now.', AiAskRequest::query()->where('request_code', $made['id'])->firstOrFail()->answer);

        $audit = AuditLog::query()->where('event', 'ai_ask.answered')->firstOrFail();
        $this->assertStringNotContainsString('Nobody', json_encode($audit->metadata));
        $statuses = AiAgentRun::query()->where('task_type', 'ask_answer')->orderBy('id')->pluck('status')->all();
        $this->assertSame(['completed', 'completed', 'failed'], $statuses);
    }

    public function test_every_refusal_gives_the_same_plain_message(): void
    {
        $one = $this->director();
        $two = $this->director();
        $mine = $this->create($one, 'Mine?');
        $cancelled = $this->create($one, 'Cancelled?');
        $this->actingAs($one)->postJson('/agents/ask/'.$cancelled['id'].'/cancel')->assertOk();
        $old = $this->create($one, 'Old?');
        $this->travel(31)->minutes();

        foreach ([['unknown', 'ZZZZZZZZZZ'], ['someone else', $mine['id']], ['cancelled', $cancelled['id']], ['expired', $old['id']]] as [$why, $code]) {
            $user = $why === 'someone else' ? $two : $one;
            [$error, $text] = $this->save($user, $code, 'An answer.');
            $this->assertTrue($error, $why);
            $this->assertStringContainsString(self::NOT_VALID, $text, $why);
        }
        $this->assertSame('pending', AiAskRequest::query()->where('request_code', $mine['id'])->firstOrFail()->status);
        $this->assertNull(AiAskRequest::query()->where('request_code', $mine['id'])->firstOrFail()->answer);
        $this->assertSame('expired', AiAskRequest::query()->where('request_code', $old['id'])->firstOrFail()->status);
    }

    public function test_an_old_connection_without_the_scope_is_read_only(): void
    {
        $director = $this->director();
        $made = $this->create($director);

        $this->assertNotContains('save_answer', $this->toolNames($director, ['mcp:use']));
        [$error] = $this->save($director, $made['id'], 'Sneaky.', ['mcp:use']);
        $this->assertTrue($error);
        $this->assertNull(AiAskRequest::query()->where('request_code', $made['id'])->firstOrFail()->answer);

        // Only the owner can lift the scope requirement, as a conscious trial choice.
        config(['agents.ask_claude_require_scope' => false]);
        $this->assertFalse($this->save($director, $made['id'], 'Allowed in the trial.', ['mcp:use'])[0]);
    }

    public function test_a_person_without_the_permission_cannot_save_even_with_the_scope(): void
    {
        $director = $this->director();
        $made = $this->create($director);
        $ca = $this->actor('ca');

        [$error] = $this->save($ca, $made['id'], 'No.');
        $this->assertTrue($error);
        $this->assertNull(AiAskRequest::query()->where('request_code', $made['id'])->firstOrFail()->answer);
    }

    public function test_the_answer_is_cleaned_and_checked(): void
    {
        $director = $this->director();
        $made = $this->create($director);

        foreach ([str_repeat('x', 4001), '   ', 'Patient 900101-10-1234 is waiting.', 'Call 012-345 6789.', 'mail a@b.test'] as $bad) {
            $this->assertTrue($this->save($director, $made['id'], $bad)[0], mb_substr($bad, 0, 30));
        }
        $this->assertNull(AiAskRequest::query()->where('request_code', $made['id'])->firstOrFail()->answer);

        $this->assertFalse($this->save($director, $made['id'], 'Two are waiting <b>now</b><script>x</script>.')[0]);
        $this->assertSame('Two are waiting nowx.', AiAskRequest::query()->where('request_code', $made['id'])->firstOrFail()->answer);
    }

    public function test_saving_is_limited_per_minute(): void
    {
        $director = $this->director();
        $made = $this->create($director);
        for ($i = 0; $i < 10; $i++) {
            RateLimiter::hit('ask-save-min:'.$director->id, 60);
        }
        $this->assertTrue($this->save($director, $made['id'], 'Too late.')[0]);
        $this->assertNull(AiAskRequest::query()->where('request_code', $made['id'])->firstOrFail()->answer);
    }

    public function test_saving_an_answer_changes_nothing_but_the_one_request_audit_and_run_log(): void
    {
        $director = $this->director();
        $made = $this->create($director);
        $allowed = ['ai_ask_requests', 'audit_logs', 'ai_agent_runs', 'ai_agent_run_steps', 'ai_agents', 'ai_agent_tools'];
        $snapshot = fn (): array => collect(Schema::getTableListing())
            ->map(fn (string $name): string => str_contains($name, '.') ? substr($name, strrpos($name, '.') + 1) : $name)
            ->reject(fn (string $name): bool => in_array($name, $allowed, true) || in_array($name, ['migrations', 'cache', 'cache_locks', 'sessions', 'jobs'], true))
            ->sort()->mapWithKeys(fn (string $name): array => [$name => md5(json_encode(DB::table($name)->get()))])->all();
        $before = $snapshot();

        $this->assertFalse($this->save($director, $made['id'], 'Fine.')[0]);

        $this->assertSame($before, $snapshot());
    }

    public function test_discovery_advertises_the_extra_scope_only_while_the_switch_is_on(): void
    {
        $this->getJson('/.well-known/oauth-authorization-server')->assertOk()->assertJsonPath('scopes_supported', ['mcp:use', 'mcp:answer']);
        $this->getJson('/.well-known/oauth-protected-resource/mcp')->assertOk()->assertJsonPath('scopes_supported', ['mcp:use', 'mcp:answer']);
        config(['agents.ask_claude_enabled' => false]);
        $this->getJson('/.well-known/oauth-authorization-server')->assertOk()->assertJsonPath('scopes_supported', ['mcp:use']);
    }

    public function test_the_purge_expires_old_pending_requests_and_empties_old_text_once(): void
    {
        $director = $this->director();
        $pending = $this->create($director, 'Pending?');
        $answered = $this->create($director, 'Answered?');
        $this->assertFalse($this->save($director, $answered['id'], 'Yes.')[0]);
        $this->travel(31)->minutes();

        Artisan::call('ai-ask:purge');
        $this->assertSame('expired', AiAskRequest::query()->where('request_code', $pending['id'])->firstOrFail()->status);
        $this->assertNotNull(AiAskRequest::query()->where('request_code', $answered['id'])->firstOrFail()->answer);

        $this->travel(91)->days();
        Artisan::call('ai-ask:purge');
        $row = DB::table('ai_ask_requests')->where('request_code', $answered['id'])->first();
        $this->assertNull($row->question);
        $this->assertNull($row->answer);
        $this->assertNotNull($row->purged_at);
        $this->assertSame('answered', $row->status);
        $this->assertSame(1, AuditLog::query()->where('event', 'ai_ask.purged')->count());
    }

    public function test_postgresql_keeps_a_request_write_once_and_never_deletes_it(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL only.');
        }
        $director = $this->director();
        $made = $this->create($director);
        $this->assertFalse($this->save($director, $made['id'], 'First.')[0]);

        foreach ([
            fn () => DB::table('ai_ask_requests')->where('request_code', $made['id'])->update(['answer' => 'Changed']),
            fn () => DB::table('ai_ask_requests')->where('request_code', $made['id'])->update(['status' => 'pending']),
            fn () => DB::table('ai_ask_requests')->where('request_code', $made['id'])->update(['user_id' => $this->director()->id]),
            fn () => DB::table('ai_ask_requests')->where('request_code', $made['id'])->delete(),
            fn () => DB::statement('TRUNCATE ai_ask_requests'),
        ] as $attempt) {
            try {
                DB::transaction($attempt);
                $this->fail('PostgreSQL accepted a change to a closed ask request.');
            } catch (QueryException $e) {
                $this->assertStringContainsString('ask request', strtolower($e->getMessage()));
            }
        }
        // A pending request cannot be answered without an answer, nor hold an answer while cancelled.
        $pending = $this->create($director, 'Again?');
        foreach ([['status' => 'answered', 'answered_at' => now()], ['status' => 'cancelled', 'answer' => 'x']] as $bad) {
            try {
                DB::transaction(fn () => DB::table('ai_ask_requests')->where('request_code', $pending['id'])->update($bad));
                $this->fail('PostgreSQL accepted a bad transition.');
            } catch (QueryException) {
                $this->assertTrue(true);
            }
        }
        $this->assertSame('First.', AiAskRequest::query()->where('request_code', $made['id'])->firstOrFail()->answer);
    }

    public function test_the_service_constants_match_the_design(): void
    {
        $this->assertSame(1000, AskRequestService::MAX_QUESTION);
        $this->assertSame(4000, AskRequestService::MAX_ANSWER);
        $this->assertSame(3, AskRequestService::MAX_PENDING);
        $this->assertSame(30, (int) config('agents.ask_expiry_minutes'));
    }
}
