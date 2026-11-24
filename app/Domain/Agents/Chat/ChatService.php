<?php

namespace App\Domain\Agents\Chat;

use App\Domain\Agents\AgentPermissions;
use App\Domain\Agents\Services\AgentRunRecorder;
use App\Domain\Agents\Services\PersonalText;
use App\Domain\Audit\AuditRecorder;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

/**
 * One chatbox message (docs/agents/AI-CHATBOX-DESIGN.md section 5). Order: switch, permission, a prompt check (no identity number,
 * phone number or email), the thread, the daily limit and the monthly cap, then one run of the registered "AI chatbox" agent in
 * which the model may ask the connector's fixed read-only questions. Nothing is written to clinic data. The question and the answer
 * are saved together, encrypted, only when an answer exists; the audit row holds counts and tool names, never the text.
 */
final class ChatService
{
    public const SYSTEM = <<<'TXT'
You are a read-only reporting helper inside KPOne, a clinic management system, answering the Director's questions about their branches.
Rules:
- Answer only from the results of the tools you call. Never guess, estimate or invent a figure. If a tool returns an error or the data is not available, say so plainly.
- Amounts in tool results ending in "Sen" are in sen (100 sen = RM 1). Quote amounts exactly as the tool gives them; do not round them.
- Name a branch by its "branchName" in the tool result (for example "Cheras"), never by its code.
- Mention the date or period you used and what the figure counts, using the tool's "definitions".
- You cannot change anything in KPOne and you must never say or imply that you did. You do not have patient or staff names or personal data and must not ask for them.
- Keep answers short and in plain English, as plain text without markdown tables. If the question is not about the clinic's figures, say you can only answer questions about the branches' figures.
TXT;

    public function __construct(
        private ChatModel $model,
        private ChatTools $tools,
        private AgentRunRecorder $recorder,
        private AuditRecorder $audit,
    ) {}

    /**
     * @param  string|null  $key  a client key for this send (the same key on a retry returns the stored answer instead of asking again)
     * @return array{thread: array{id: int, title: string|null}, message: array<string, mixed>}
     *
     * @throws ChatRefused
     * @throws AuthorizationException
     */
    public function ask(User $user, ?int $threadId, string $text, ?string $key = null): array
    {
        if (! ChatSwitch::enabled()) {
            throw new ChatRefused('off', 'The chatbox is switched off.', 404);
        }
        if (! $user->can(AgentPermissions::CHAT_USE)) {
            throw new AuthorizationException('You may not use the chatbox.');
        }
        $text = trim($text);
        $max = (int) config('ai_chat.max_prompt_chars', 1000);
        if ($text === '' || mb_strlen($text) > $max) {
            throw new ChatRefused('invalid_input', 'Type a question of up to '.$max.' characters.');
        }
        if (PersonalText::looksPersonal($text)) {
            $this->audit($user, 'refused', ['reason' => 'personal_data']);
            throw new ChatRefused('personal_data', "Please don't type identity numbers, phone numbers or emails.");
        }
        if ($key !== null && ! preg_match('/^[A-Za-z0-9-]{8,64}$/', $key)) {
            throw new ChatRefused('invalid_input', 'The request key is not valid.');
        }
        $thread = $threadId === null ? null : AiChatThread::query()->where('organisation_id', $user->organisation_id)
            ->where('user_id', $user->id)->where('is_archived', false)->whereKey($threadId)->first();
        if ($threadId !== null && ! $thread) {
            throw new ChatRefused('thread_not_found', 'That conversation was not found.', 404);
        }
        $lock = null;
        if ($key !== null) {
            $stored = $this->stored($user, $key);
            if ($stored !== null) {
                return $stored;
            }
            // The same key already being answered (a double click or a retry while the first is still running).
            $lock = Cache::lock('ai-chat-key:'.$user->id.':'.$key, 180);
            if (! $lock->get()) {
                throw new ChatRefused('busy', 'This question is already being answered.', 409);
            }
        }

        try {
            return $this->answer($user, $thread, $text, $key);
        } finally {
            $lock?->release();
        }
    }

    /** @return array{thread: array{id: int, title: string|null}, message: array<string, mixed>}|null */
    private function stored(User $user, string $key): ?array
    {
        $question = AiChatMessage::query()->where('user_id', $user->id)->where('request_key', $key)->first();
        if (! $question) {
            return null;
        }
        $answer = AiChatMessage::query()->where('thread_id', $question->thread_id)->where('role', 'assistant')->where('id', '>', $question->id)->orderBy('id')->first();
        $thread = AiChatThread::query()->findOrFail($question->thread_id);

        return $answer ? ['thread' => ['id' => $thread->id, 'title' => $thread->title], 'message' => self::present($answer)] : null;
    }

    /** @return array{thread: array{id: int, title: string|null}, message: array<string, mixed>} */
    private function answer(User $user, ?AiChatThread $thread, string $text, ?string $key): array
    {
        $this->hit('ai-chat-minute:'.$user->id, 60, (int) config('ai_chat.messages_per_minute', 10), 'rate_limited', 'Too many questions. Try again in a minute.', $user);
        $this->hit('ai-chat-day:'.$user->id, 86400, (int) config('ai_chat.messages_per_day', 30), 'rate_limited_day', 'The daily limit of questions is reached. Try again tomorrow.', $user);

        try {
            $usage = ChatSpend::reserve($user->organisation_id, $user->id);
        } catch (ChatRefused $refused) {
            $this->audit($user, 'refused', ['reason' => $refused->reason]);
            throw $refused;
        }
        try {
            $run = $this->recorder->start(ChatAgent::ensure($user->organisation_id), 'chat_message', null, $user, AgentPermissions::CHAT_USE);
        } catch (AuthorizationException|ValidationException) {
            ChatSpend::settle($usage, 0, 0, 'agent_unavailable');
            $this->audit($user, 'refused', ['reason' => 'agent_unavailable']);
            throw new ChatRefused('agent_unavailable', 'The chatbox is not available right now.', 503);
        }

        // Only a web request has a PHP time limit to lift. In the console (tests, commands) the limit is 0, and
        // calling set_time_limit() there would start a timer that later kills the whole worker process.
        if ((int) ini_get('max_execution_time') > 0) {
            @set_time_limit((int) config('ai_chat.deadline_seconds', 90) + 30);
        }
        $started = microtime(true);
        $turns = $this->context($thread);
        $turns[] = ['role' => 'user', 'content' => $text];
        $figures = [];
        $used = [];
        $tokensIn = 0;
        $tokensOut = 0;
        $answer = '';
        $report = null;
        $calls = 0;

        try {
            for ($round = 0; $round <= (int) config('ai_chat.max_tool_calls', 6); $round++) {
                if (microtime(true) - $started > (int) config('ai_chat.deadline_seconds', 90)) {
                    throw new ChatProviderFailed('The message took too long.');
                }
                $reply = $this->model->complete(self::SYSTEM, $turns, $this->tools->definitions());
                $tokensIn += $reply->tokensIn;
                $tokensOut += $reply->tokensOut;
                if ($reply->toolCalls === []) {
                    $answer = $reply->text;
                    break;
                }
                $turns[] = ['role' => 'assistant', 'content' => $reply->content];
                $results = [];
                foreach ($reply->toolCalls as $call) {
                    $calls++;
                    $over = $calls > (int) config('ai_chat.max_total_tool_calls', 8);
                    $outcome = $over ? ['result' => null, 'error' => 'Too many questions in one message.'] : $this->tools->execute($user, $call['name'], $call['input']);
                    $used[] = $call['name'];
                    $this->recorder->step($run, 'note', 'Asked '.$call['name'].($outcome['error'] === null ? '.' : ' (refused).'));
                    if ($outcome['result'] !== null) {
                        $figures = [...$figures, ...ChatFigures::from($call['name'], $outcome['result'])];
                        $report ??= ChatFigures::report($call['name']);
                    }
                    $results[] = [
                        'type' => 'tool_result',
                        'tool_use_id' => $call['id'],
                        'content' => json_encode($outcome['error'] === null ? $outcome['result'] : ['error' => $outcome['error']], JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR),
                        'is_error' => $outcome['error'] !== null,
                    ];
                }
                $turns[] = ['role' => 'user', 'content' => $results];
            }
            if (trim($answer) === '') {
                throw new ChatProviderFailed('No answer was produced.');
            }
        } catch (Throwable $exception) {
            report($exception);
            // Tokens already spent still count against the cap.
            ChatSpend::settle($usage, $tokensIn, $tokensOut, 'failed');
            $this->recorder->fail($run, 'chat.provider_error');
            $this->audit($user, 'error', ['reason' => 'provider_error', 'tokens_in' => $tokensIn, 'tokens_out' => $tokensOut]);
            throw new ChatRefused('error', "Couldn't answer. Nothing was changed. Try again.", 503);
        }

        $cost = ChatSpend::settle($usage, $tokensIn, $tokensOut, 'ok');
        // Tags are removed (the page shows text only); a lone "<" in a figure comparison stays.
        $answer = mb_substr(trim((string) preg_replace('/<\/?[a-zA-Z!][^>]*>/', '', $answer)), 0, (int) config('ai_chat.max_answer_chars', 4000));
        $tools = array_values(array_unique($used));

        try {
            $saved = DB::transaction(function () use ($user, $thread, $text, $answer, $figures, $tools, $tokensIn, $tokensOut, $cost, $report, $key): array {
                $now = now();
                $thread ??= AiChatThread::query()->create([
                    'organisation_id' => $user->organisation_id, 'user_id' => $user->id, 'surface' => AiChatThread::SURFACE_DIRECTOR_OVERVIEW,
                    'title' => mb_substr($text, 0, 80), 'is_archived' => false, 'created_at' => $now, 'last_used_at' => $now,
                ]);
                AiChatMessage::query()->create(['thread_id' => $thread->id, 'organisation_id' => $user->organisation_id, 'user_id' => $user->id, 'role' => 'user', 'body' => $text, 'request_key' => $key, 'created_at' => $now]);
                $assistant = AiChatMessage::query()->create([
                    'thread_id' => $thread->id, 'organisation_id' => $user->organisation_id, 'user_id' => $user->id, 'role' => 'assistant', 'body' => $answer,
                    'figures' => $figures === [] && $report === null ? null : ['figures' => $figures, 'report' => $report],
                    'tools' => $tools, 'tokens_in' => $tokensIn, 'tokens_out' => $tokensOut, 'cost_sen' => $cost, 'created_at' => $now,
                ]);
                $thread->forceFill(['last_used_at' => $now])->save();
                // The audit row is part of the same transaction: if it cannot be written, nothing is saved or returned.
                if ($this->audit($user, 'ok', ['tools' => $tools, 'tokens_in' => $tokensIn, 'tokens_out' => $tokensOut, 'cost_sen' => $cost]) === null) {
                    throw new RuntimeException('The audit row could not be written.');
                }

                return ['thread' => $thread, 'assistant' => $assistant];
            });
        } catch (Throwable $exception) {
            report($exception);
            $this->recorder->fail($run, 'chat.save_failed');
            throw new ChatRefused('error', "Couldn't answer. Nothing was changed. Try again.", 503);
        }
        $this->recorder->complete($run);

        return [
            'thread' => ['id' => $saved['thread']->id, 'title' => $saved['thread']->title],
            'message' => self::present($saved['assistant']),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function present(AiChatMessage $message): array
    {
        $extra = $message->purged_at === null && is_array($message->figures) ? $message->figures : [];

        return [
            'id' => $message->id,
            'role' => $message->role,
            'text' => $message->purged_at === null ? $message->body : null,
            'purged' => $message->purged_at !== null,
            'figures' => is_array($extra['figures'] ?? null) ? $extra['figures'] : [],
            'report' => is_array($extra['report'] ?? null) ? $extra['report'] : null,
            'createdAt' => $message->created_at->toIso8601String(),
        ];
    }

    /** @return list<array{role: string, content: string}> the last few text messages of the thread, for follow-up questions */
    private function context(?AiChatThread $thread): array
    {
        if (! $thread) {
            return [];
        }
        $rows = AiChatMessage::query()->where('thread_id', $thread->id)->whereNull('purged_at')->orderByDesc('id')
            ->limit((int) config('ai_chat.history_context_messages', 8))->get()->reverse();

        return array_values($rows->map(fn (AiChatMessage $row): array => ['role' => $row->role, 'content' => (string) $row->body])->all());
    }

    private function hit(string $key, int $seconds, int $limit, string $reason, string $message, User $user): void
    {
        if (RateLimiter::hit($key, $seconds) > $limit) {
            $this->audit($user, 'refused', ['reason' => $reason]);
            throw new ChatRefused($reason, $message, 429);
        }
    }

    /** @param array<string, mixed> $metadata */
    private function audit(User $user, string $outcome, array $metadata): mixed
    {
        return $this->audit->record('ai_chat.message', null, ['outcome' => $outcome, ...$metadata], $user, null, $user->organisation_id);
    }
}
