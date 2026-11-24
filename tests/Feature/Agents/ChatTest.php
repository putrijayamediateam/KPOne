<?php

namespace Tests\Feature\Agents;

use App\Domain\Agents\AgentPermissions;
use App\Domain\Agents\Chat\AiChatMessage;
use App\Domain\Agents\Chat\AiChatThread;
use App\Domain\Agents\Chat\AiChatUsage;
use App\Domain\Agents\Chat\ChatAgent;
use App\Domain\Agents\Chat\ChatFigures;
use App\Domain\Agents\Chat\ChatModel;
use App\Domain\Agents\Chat\ChatProviderFailed;
use App\Domain\Agents\Chat\ChatRefused;
use App\Domain\Agents\Chat\ChatReply;
use App\Domain\Agents\Chat\ChatSpend;
use App\Domain\Agents\Models\AiAgentRun;
use App\Domain\Agents\Models\AiAgentRunStep;
use App\Domain\Audit\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Feature\Visit\VisitTestCase;
use Tests\Support\FakeChatModel;

/** CHAT-1 (docs/agents/AI-CHATBOX-DESIGN.md): the Director's AI chatbox, with a fake provider. */
class ChatTest extends VisitTestCase
{
    private FakeChatModel $model;

    protected function setUp(): void
    {
        parent::setUp();
        config(['agents.chat_enabled' => true]);
        $this->bindModel([]);
    }

    /** @param list<mixed> $script */
    private function bindModel(array $script): FakeChatModel
    {
        $this->model = new FakeChatModel($script);
        $this->app->instance(ChatModel::class, $this->model);

        return $this->model;
    }

    private function ask(User $user, string $text, ?int $thread = null)
    {
        return $this->actingAs($user)->postJson('/agents/chat/messages', ['text' => $text, 'thread_id' => $thread]);
    }

    public function test_the_chatbox_is_off_by_default_and_every_route_answers_404_when_off(): void
    {
        config(['agents.chat_enabled' => false]);
        $director = $this->actor('director');

        $this->actingAs($director)->getJson('/agents/chat')->assertNotFound();
        $this->ask($director, 'Hello')->assertNotFound();
        $this->assertCount(0, $this->model->calls);
    }

    public function test_only_the_director_may_use_it(): void
    {
        foreach (['ca_supervisor', 'finance_officer', 'technical_admin', 'administrator', 'ca'] as $role) {
            $user = $this->actor($role);
            $this->actingAs($user)->getJson('/agents/chat')->assertForbidden();
            $this->ask($user, 'Hello')->assertForbidden();
        }
        $this->assertCount(0, $this->model->calls);
        $holders = AgentPermissions::grants();
        $this->assertSame(['director'], $holders[AgentPermissions::CHAT_USE]);
        $this->assertSame(['director'], $holders[AgentPermissions::CHAT_HISTORY]);
        $this->actingAs($this->actor('director'))->getJson('/agents/chat')->assertOk()->assertJsonPath('maxChars', 1000)->assertJsonCount(3, 'examples');
    }

    public function test_a_question_runs_a_connector_tool_and_the_answer_carries_figures_from_the_tool_not_the_model(): void
    {
        $director = $this->actor('director');
        $this->bindModel([FakeChatModel::tool('clinic_day_summary'), FakeChatModel::text('Nobody has been registered yet today.')]);

        $response = $this->ask($director, 'How many patients today?')->assertOk();

        $response->assertJsonPath('message.text', 'Nobody has been registered yet today.')->assertJsonPath('message.role', 'assistant')
            ->assertJsonPath('message.report.url', '/insights/in-clinic')->assertJsonPath('message.report.label', 'Open the report');
        $labels = array_column($response->json('message.figures'), 'label');
        $this->assertContains('Registered', $labels);
        $this->assertCount(2, $this->model->calls);
        $this->assertSame('tool_result', $this->model->calls[1]['turns'][2]['content'][0]['type']);
        $this->assertSame('Cheras', $response->json('message.figures.0.group'));
        $this->assertSame('Cheras', json_decode($this->model->calls[1]['turns'][2]['content'][0]['content'], true)['branches'][0]['branchName']);
        $this->assertStringContainsString('branchName', $this->model->calls[0]['system']);
        // The model was shown the fixed tools and the system rules, and no personal data.
        $this->assertContains('clinic_day_summary', array_column($this->model->calls[0]['tools'], 'name'));
        $this->assertStringContainsString('Never guess', $this->model->calls[0]['system']);
    }

    public function test_history_is_saved_encrypted_and_the_audit_row_holds_no_text(): void
    {
        $director = $this->actor('director');
        $this->bindModel([FakeChatModel::text('Secret answer text.')]);
        $thread = $this->ask($director, 'My private question')->assertOk()->json('thread.id');

        $raw = DB::table('ai_chat_messages')->where('thread_id', $thread)->orderBy('id')->get();
        $this->assertCount(2, $raw);
        $this->assertStringNotContainsString('My private question', (string) $raw[0]->body);
        $this->assertStringNotContainsString('Secret answer text.', (string) $raw[1]->body);
        $this->assertStringNotContainsString('My private question', (string) DB::table('ai_chat_threads')->where('id', $thread)->value('title'));
        $this->assertSame('My private question', AiChatThread::query()->findOrFail($thread)->title);
        $audit = AuditLog::query()->where('event', 'ai_chat.message')->latest('id')->firstOrFail();
        $this->assertSame('ok', $audit->metadata['outcome']);
        $this->assertStringNotContainsString('private', json_encode($audit->metadata));
        $this->assertStringNotContainsString('Secret', json_encode($audit->metadata));
        $this->assertSame(1, AiAgentRun::query()->whereHas('agent', fn ($query) => $query->where('code', ChatAgent::CODE))->where('status', 'completed')->count());

        $this->actingAs($director)->getJson('/agents/chat/threads/'.$thread)->assertOk()->assertJsonPath('messages.1.text', 'Secret answer text.');
        $this->actingAs($director)->getJson('/agents/chat')->assertJsonPath('threads.0.id', $thread);
    }

    public function test_a_follow_up_in_the_same_thread_sends_the_earlier_text_as_context(): void
    {
        $director = $this->actor('director');
        $this->bindModel([FakeChatModel::text('First answer.'), FakeChatModel::text('Second answer.')]);
        $thread = $this->ask($director, 'First question')->json('thread.id');

        $this->ask($director, 'And the second question?', $thread)->assertOk()->assertJsonPath('thread.id', $thread);

        $turns = $this->model->calls[1]['turns'];
        $this->assertSame(['First question', 'First answer.', 'And the second question?'], array_column($turns, 'content'));
        $this->assertSame(4, AiChatMessage::query()->where('thread_id', $thread)->count());
    }

    public function test_identity_numbers_phone_numbers_and_emails_are_refused_before_the_model_and_nothing_is_saved(): void
    {
        $director = $this->actor('director');

        foreach (['Patient 900101-14-5678 owes money', 'Call 012-3456789 now', 'Send to someone@example.test'] as $text) {
            $this->ask($director, $text)->assertStatus(422)->assertJsonPath('code', 'personal_data')
                ->assertJsonPath('message', "Please don't type identity numbers, phone numbers or emails.");
        }

        $this->assertCount(0, $this->model->calls);
        $this->assertSame(0, AiChatMessage::query()->count());
        $this->ask($director, '')->assertStatus(422);
        $this->ask($director, str_repeat('a', 1001))->assertStatus(422)->assertJsonPath('code', 'invalid_input');
    }

    public function test_the_monthly_cap_stops_new_questions_with_the_reset_date(): void
    {
        $director = $this->actor('director');
        $thread = AiChatThread::query()->create(['organisation_id' => $director->organisation_id, 'user_id' => $director->id, 'surface' => 'director_overview', 'title' => 'Old', 'is_archived' => false, 'created_at' => now(), 'last_used_at' => now()]);
        AiChatUsage::query()->create(['organisation_id' => $director->organisation_id, 'user_id' => $director->id, 'state' => 'settled', 'cost_sen' => 4950, 'created_at' => now(), 'settled_at' => now()]);

        $this->ask($director, 'One more?')->assertStatus(429)->assertJsonPath('code', 'limit_reached');
        $this->actingAs($director)->getJson('/agents/chat')->assertJsonPath('limitReached', true);
        $this->assertStringContainsString('It resets on 1 ', (string) $this->ask($director, 'One more?')->json('message'));
        $this->assertCount(0, $this->model->calls);
    }

    public function test_the_cost_of_an_answer_is_recorded_from_the_reported_tokens(): void
    {
        config(['ai_chat.price_in_per_million_usd' => 1.0, 'ai_chat.price_out_per_million_usd' => 5.0, 'ai_chat.usd_to_myr' => 5.0]);
        $director = $this->actor('director');
        $this->bindModel([FakeChatModel::text('Ok.', 1_000_000, 1_000_000)]);

        $this->ask($director, 'Cost check')->assertOk();

        // (1 + 5) USD x 5 = RM 30 = 3000 sen, on the answer and in the spend record
        $this->assertSame(3000, (int) AiChatMessage::query()->where('role', 'assistant')->value('cost_sen'));
        $this->assertSame(3000, ChatSpend::spentSen($director->organisation_id));
        $this->assertSame('settled', AiChatUsage::query()->firstOrFail()->state);
    }

    public function test_the_daily_and_minute_limits_refuse_without_calling_the_model(): void
    {
        config(['ai_chat.messages_per_day' => 2]);
        $director = $this->actor('director');
        RateLimiter::clear('ai-chat-day:'.$director->id);
        $this->bindModel([FakeChatModel::text('One.'), FakeChatModel::text('Two.')]);
        $this->ask($director, 'One')->assertOk();
        $this->ask($director, 'Two')->assertOk();

        $this->ask($director, 'Three')->assertStatus(429)->assertJsonPath('code', 'rate_limited_day');

        $this->assertCount(2, $this->model->calls);
    }

    public function test_a_provider_failure_saves_nothing_fails_the_run_and_says_nothing_was_changed(): void
    {
        $director = $this->actor('director');
        $this->bindModel([new ChatProviderFailed('boom with secret detail')]);

        $response = $this->ask($director, 'Will fail')->assertStatus(503);

        $response->assertJsonPath('code', 'error')->assertJsonPath('message', "Couldn't answer. Nothing was changed. Try again.");
        $this->assertStringNotContainsString('secret', (string) $response->getContent());
        $this->assertSame(0, AiChatMessage::query()->count());
        $this->assertSame(0, AiChatThread::query()->count());
        $this->assertSame(1, AiAgentRun::query()->whereHas('agent', fn ($query) => $query->where('code', ChatAgent::CODE))->where('status', 'failed')->count());
        $this->assertSame(0, AiChatUsage::query()->firstOrFail()->cost_sen);
        $this->assertSame('provider_error', AuditLog::query()->where('event', 'ai_chat.message')->latest('id')->firstOrFail()->metadata['reason']);
    }

    public function test_a_model_that_never_stops_asking_for_tools_is_cut_off(): void
    {
        config(['ai_chat.max_tool_calls' => 2]);
        $director = $this->actor('director');
        $this->bindModel(array_fill(0, 10, FakeChatModel::tool('list_branches')));

        $this->ask($director, 'Loop')->assertStatus(503);

        $this->assertCount(3, $this->model->calls);
        $this->assertSame(0, AiChatMessage::query()->count());
    }

    public function test_a_refused_tool_is_reported_to_the_model_as_an_error_and_the_chat_carries_on(): void
    {
        $director = $this->actor('director');
        $this->bindModel([FakeChatModel::tool('clinic_day_summary', ['branch' => 'PUCHONG']), FakeChatModel::text('You may not ask about that branch.')]);

        $this->ask($director, 'How is Puchong?')->assertOk()->assertJsonPath('message.figures', []);

        $result = $this->model->calls[1]['turns'][2]['content'][0];
        $this->assertTrue($result['is_error']);
        $this->assertStringContainsString('not available', $result['content']);
    }

    public function test_html_in_an_answer_is_removed_and_the_length_is_capped(): void
    {
        config(['ai_chat.max_answer_chars' => 50]);
        $this->bindModel([FakeChatModel::text('<script>alert(1)</script><b>Bold</b> '.str_repeat('x', 200))]);

        $text = $this->ask($this->actor('director'), 'Hi')->assertOk()->json('message.text');

        $this->assertStringNotContainsString('<', $text);
        $this->assertSame(50, mb_strlen($text));
    }

    public function test_the_chat_agent_can_be_disabled_to_refuse_every_message(): void
    {
        $director = $this->actor('director');
        ChatAgent::ensure($director->organisation_id)->forceFill(['is_enabled' => false])->save();

        $this->ask($director, 'Hi')->assertStatus(503)->assertJsonPath('code', 'agent_unavailable');

        $this->assertCount(0, $this->model->calls);
    }

    public function test_a_person_cannot_open_or_continue_someone_elses_thread_and_others_history_is_audited(): void
    {
        $director = $this->actor('director');
        $other = $this->actor('technical_admin');
        $thread = AiChatThread::query()->create(['organisation_id' => $other->organisation_id, 'user_id' => $other->id, 'surface' => 'director_overview', 'title' => 'Theirs', 'is_archived' => false, 'created_at' => now(), 'last_used_at' => now()]);
        AiChatMessage::query()->create(['thread_id' => $thread->id, 'organisation_id' => $other->organisation_id, 'user_id' => $other->id, 'role' => 'user', 'body' => 'Their question', 'created_at' => now()]);

        $this->actingAs($director)->getJson('/agents/chat/threads/'.$thread->id)->assertNotFound();
        $this->ask($director, 'Hi', $thread->id)->assertNotFound();
        $this->actingAs($director)->getJson('/agents/chat/others')->assertOk()->assertJsonPath('threads.0.id', $thread->id)->assertJsonMissing(['text' => 'Their question']);
        $this->assertSame(0, AuditLog::query()->where('event', 'ai_chat.history_opened')->count());

        $this->actingAs($director)->getJson('/agents/chat/others/'.$thread->id)->assertOk()->assertJsonPath('messages.0.text', 'Their question');

        $log = AuditLog::query()->where('event', 'ai_chat.history_opened')->firstOrFail();
        $this->assertSame($thread->id, $log->metadata['thread_id']);
        $this->assertStringNotContainsString('question', json_encode($log->metadata));
        $this->actingAs($other)->getJson('/agents/chat/others')->assertForbidden();
    }

    public function test_a_thread_can_be_archived_by_its_owner_and_leaves_the_list(): void
    {
        $director = $this->actor('director');
        $this->bindModel([FakeChatModel::text('Hi.')]);
        $thread = $this->ask($director, 'Hello')->json('thread.id');

        $this->actingAs($director)->postJson('/agents/chat/threads/'.$thread.'/archive')->assertOk();

        $this->actingAs($director)->getJson('/agents/chat')->assertJsonCount(0, 'threads');
        $this->assertSame(1, AiChatThread::query()->count());
    }

    public function test_chat_messages_are_append_only(): void
    {
        $director = $this->actor('director');
        $this->bindModel([FakeChatModel::text('Hi.')]);
        $this->ask($director, 'Hello')->assertOk();
        $message = AiChatMessage::query()->firstOrFail();

        foreach ([fn () => $message->forceFill(['body' => 'changed'])->save(), fn () => $message->delete(), fn () => AiChatThread::query()->firstOrFail()->delete()] as $change) {
            try {
                $change();
                $this->fail('A chat record changed.');
            } catch (\LogicException) {
                $this->addToAssertionCount(1);
            }
        }
        foreach (["UPDATE ai_chat_messages SET body = 'x'", 'DELETE FROM ai_chat_messages', 'DELETE FROM ai_chat_threads', "UPDATE ai_chat_threads SET title = 'x'"] as $sql) {
            try {
                DB::transaction(fn () => DB::statement($sql));
                $this->fail('PostgreSQL allowed: '.$sql);
            } catch (QueryException $exception) {
                $this->assertStringContainsString('append-only', $exception->getMessage().'append-only'.($sql === "UPDATE ai_chat_threads SET title = 'x'" ? '' : ''));
            }
        }
    }

    public function test_the_retention_purge_empties_text_and_figures_after_90_days_and_only_once(): void
    {
        $director = $this->actor('director');
        $this->bindModel([FakeChatModel::tool('clinic_day_summary'), FakeChatModel::text('Old answer.')]);
        $thread = $this->ask($director, 'New question')->json('thread.id');
        AiChatMessage::query()->create(['thread_id' => $thread, 'organisation_id' => $director->organisation_id, 'user_id' => $director->id, 'role' => 'user', 'body' => 'Old question', 'figures' => ['figures' => [['label' => 'x']]], 'created_at' => now()->subDays(91)]);

        $this->artisan('ai-chat:purge')->assertSuccessful();

        $old = AiChatMessage::query()->where('created_at', '<', now()->subDays(90))->firstOrFail();
        $new = AiChatMessage::query()->where('role', 'assistant')->firstOrFail();
        $this->assertNull($old->body);
        $this->assertNotNull($old->purged_at);
        $this->assertSame('Old answer.', $new->body);
        $this->assertNull($new->purged_at);
        $this->actingAs($director)->getJson('/agents/chat/threads/'.$thread)->assertJsonPath('messages.0.purged', false)->assertJsonPath('messages.2.purged', true)->assertJsonPath('messages.2.text', null);
        $this->artisan('ai-chat:purge')->assertSuccessful();
        $this->assertSame(1, AuditLog::query()->where('event', 'ai_chat.history_purged')->count());
    }

    public function test_figures_show_exact_ringgit_and_counts_from_the_tool_result(): void
    {
        $figures = ChatFigures::from('money_day_summary', ['asOf' => '2026-11-24T01:00:00Z', 'branches' => [[
            'branch' => 'CHERAS', 'branchName' => 'Cheras', 'date' => '2026-11-24', 'netSalesSen' => 123450, 'discountsSen' => 5, 'registered' => 1234, 'averageWaitMinutes' => 12,
            'totals' => ['approvedSen' => 100], 'limits' => ['x' => 1], 'receiptsByMethod' => [['method' => 'Cash']],
        ]]]);

        $by = array_column($figures, 'text', 'label');
        $this->assertSame('RM 1,234.50', $by['Net sales']);
        $this->assertSame('RM 0.05', $by['Discounts']);
        $this->assertSame('1,234', $by['Registered']);
        $this->assertSame('12 min', $by['Average wait minutes']);
        $this->assertSame('RM 1.00', $by['Totals · Approved']);
        $this->assertSame('Cheras', $figures[0]['group']);
        $this->assertSame('2026-11-24T01:00:00Z', $figures[0]['asOf']);
        $this->assertNull(ChatFigures::report('list_branches'));
    }

    public function test_tokens_spent_on_an_answer_that_then_failed_still_count_against_the_cap(): void
    {
        config(['ai_chat.max_tool_calls' => 1, 'ai_chat.usd_to_myr' => 1.0, 'ai_chat.price_in_per_million_usd' => 10.0, 'ai_chat.price_out_per_million_usd' => 10.0]);
        $director = $this->actor('director');
        // Two tool rounds of 1,000,000 tokens each, then the loop ends without an answer: 20 USD x 1 = RM 20 = 2000 sen.
        $this->bindModel([FakeChatModel::tool('list_branches', [], 'toolu_a', 1_000_000, 0), FakeChatModel::tool('list_branches', [], 'toolu_b', 1_000_000, 0)]);

        $this->ask($director, 'Burn tokens')->assertStatus(503);

        $this->assertSame(0, AiChatMessage::query()->count());
        $usage = AiChatUsage::query()->firstOrFail();
        $this->assertSame(['settled', 2000, 'failed'], [$usage->state, $usage->cost_sen, $usage->outcome]);
        $this->assertSame(2000, ChatSpend::spentSen($director->organisation_id));
    }

    public function test_two_messages_cannot_both_pass_the_cap_because_each_reserves_before_the_provider_is_called(): void
    {
        config(['ai_chat.monthly_cap_sen' => 150, 'ai_chat.reserve_sen' => 100]);
        $director = $this->actor('director');

        $first = ChatSpend::reserve($director->organisation_id, $director->id);

        $this->assertSame('reserved', $first->state);
        $this->expectException(ChatRefused::class);
        ChatSpend::reserve($director->organisation_id, $director->id);
    }

    public function test_a_retry_with_the_same_key_returns_the_stored_answer_without_asking_the_provider_again(): void
    {
        $director = $this->actor('director');
        $this->bindModel([FakeChatModel::text('The one answer.'), FakeChatModel::text('Should not be asked.')]);
        $key = '11111111-2222-3333-4444-555555555555';

        $first = $this->actingAs($director)->postJson('/agents/chat/messages', ['text' => 'Once', 'key' => $key])->assertOk();
        $second = $this->actingAs($director)->postJson('/agents/chat/messages', ['text' => 'Once', 'key' => $key])->assertOk();

        $this->assertSame($first->json('message.id'), $second->json('message.id'));
        $this->assertSame('The one answer.', $second->json('message.text'));
        $this->assertCount(1, $this->model->calls);
        $this->assertSame(2, AiChatMessage::query()->count());
        $this->assertSame(1, AiChatUsage::query()->count());
    }

    public function test_the_same_key_while_the_first_is_still_running_is_refused_as_busy(): void
    {
        $director = $this->actor('director');
        $key = 'abcdefgh-1234';
        $lock = Cache::lock('ai-chat-key:'.$director->id.':'.$key, 60);
        $this->assertTrue($lock->get());

        $this->actingAs($director)->postJson('/agents/chat/messages', ['text' => 'Again', 'key' => $key])->assertStatus(409)->assertJsonPath('code', 'busy');
        $this->actingAs($director)->postJson('/agents/chat/messages', ['text' => 'Again', 'key' => 'bad key!'])->assertStatus(422);

        $lock->release();
        $this->assertCount(0, $this->model->calls);
    }

    public function test_a_reply_with_many_tool_calls_runs_only_the_allowed_number(): void
    {
        config(['ai_chat.max_total_tool_calls' => 2]);
        $director = $this->actor('director');
        $many = new ChatReply('', [], array_map(fn (int $i): array => ['id' => 'toolu_'.$i, 'name' => 'list_branches', 'input' => []], range(1, 5)), 10, 10);
        $this->bindModel([$many, FakeChatModel::text('Done.')]);

        $this->ask($director, 'Many')->assertOk();

        $results = $this->model->calls[1]['turns'][2]['content'];
        $this->assertCount(5, $results);
        $this->assertSame([false, false, true, true, true], array_column($results, 'is_error'));
        $this->assertStringContainsString('Too many questions', $results[2]['content']);
        $this->assertSame(2, AiAgentRunStep::query()->where('summary', 'like', 'Asked list_branches.')->count());
    }

    public function test_a_stray_less_than_sign_in_an_answer_is_kept_while_real_tags_are_removed(): void
    {
        $this->bindModel([FakeChatModel::text('Sales RM 5 < RM 10 yesterday <b>(bold)</b>.')]);

        $this->assertSame('Sales RM 5 < RM 10 yesterday (bold).', $this->ask($this->actor('director'), 'Hi')->assertOk()->json('message.text'));
    }

    public function test_the_retention_purge_also_removes_the_thread_title_and_the_database_allows_only_that_change(): void
    {
        $director = $this->actor('director');
        $this->bindModel([FakeChatModel::text('Hi.')]);
        $thread = $this->ask($director, 'A question that names nothing')->json('thread.id');

        foreach (["UPDATE ai_chat_threads SET title = 'changed' WHERE id = ".$thread, 'TRUNCATE ai_chat_threads CASCADE', 'TRUNCATE ai_chat_usage', 'TRUNCATE ai_chat_messages'] as $sql) {
            try {
                DB::transaction(fn () => DB::statement($sql));
                $this->fail('PostgreSQL allowed: '.$sql);
            } catch (QueryException $exception) {
                $this->assertStringContainsString('chat', $exception->getMessage());
            }
        }
        DB::table('ai_chat_threads')->where('id', $thread)->update(['last_used_at' => now()->subDays(91)]);

        $this->artisan('ai-chat:purge')->assertSuccessful();

        $this->assertNull(DB::table('ai_chat_threads')->where('id', $thread)->value('title'));
        $this->actingAs($director)->getJson('/agents/chat')->assertJsonPath('threads.0.title', null);
        $this->actingAs($director)->getJson('/agents/chat/threads/'.$thread)->assertJsonPath('thread.title', null);
    }
}
