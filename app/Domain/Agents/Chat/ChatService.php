<?php

namespace App\Domain\Agents\Chat;

use App\Domain\Agents\AgentPermissions;
use App\Domain\Agents\Services\AgentRunRecorder;
use App\Domain\Agents\Services\PersonalText;
use App\Domain\Audit\AuditRecorder;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
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
     * @return array{thread: array{id: int, title: string}, message: array<string, mixed>}
     *
     * @throws ChatRefused
     * @throws AuthorizationException
     */
    public function ask(User $user, ?int $threadId, string $text): array
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
        $thread = $threadId === null ? null : AiChatThread::query()->where('organisation_id', $user->organisation_id)
            ->where('user_id', $user->id)->where('is_archived', false)->whereKey($threadId)->first();
        if ($threadId !== null && ! $thread) {
            throw new ChatRefused('thread_not_found', 'That conversation was not found.', 404);
        }
        if (ChatSpend::reached($user->organisation_id)) {
            $this->audit($user, 'refused', ['reason' => 'limit_reached']);
            throw new ChatRefused('limit_reached', 'The monthly AI limit is reached. It resets on '.ChatSpend::resetsOn().'.', 429);
        }
        $this->hit('ai-chat-minute:'.$user->id, 60, (int) config('ai_chat.messages_per_minute', 10), 'rate_limited', 'Too many questions. Try again in a minute.', $user);
        $this->hit('ai-chat-day:'.$user->id, 86400, (int) config('ai_chat.messages_per_day', 30), 'rate_limited_day', 'The daily limit of questions is reached. Try again tomorrow.', $user);

        try {
            $run = $this->recorder->start(ChatAgent::ensure($user->organisation_id), 'chat_message', null, $user, AgentPermissions::CHAT_USE);
        } catch (AuthorizationException|ValidationException) {
            $this->audit($user, 'refused', ['reason' => 'agent_unavailable']);
            throw new ChatRefused('agent_unavailable', 'The chatbox is not available right now.', 503);
        }

        $turns = $this->context($thread);
        $turns[] = ['role' => 'user', 'content' => $text];
        $figures = [];
        $used = [];
        $tokensIn = 0;
        $tokensOut = 0;
        $answer = '';
        $report = null;

        try {
            for ($round = 0; $round <= (int) config('ai_chat.max_tool_calls', 6); $round++) {
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
                    $outcome = $this->tools->execute($user, $call['name'], $call['input']);
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
            $this->recorder->fail($run, 'chat.provider_error');
            $this->audit($user, 'error', ['reason' => 'provider_error', 'tokens_in' => $tokensIn, 'tokens_out' => $tokensOut]);
            throw new ChatRefused('error', "Couldn't answer. Nothing was changed. Try again.", 503);
        }

        $answer = mb_substr(strip_tags(trim($answer)), 0, (int) config('ai_chat.max_answer_chars', 4000));
        $cost = ChatSpend::costSen($tokensIn, $tokensOut);
        $saved = DB::transaction(function () use ($user, $thread, $text, $answer, $figures, $used, $tokensIn, $tokensOut, $cost, $report): array {
            $now = now();
            $thread ??= AiChatThread::query()->create([
                'organisation_id' => $user->organisation_id, 'user_id' => $user->id, 'surface' => AiChatThread::SURFACE_DIRECTOR_OVERVIEW,
                'title' => mb_substr($text, 0, 80), 'is_archived' => false, 'created_at' => $now, 'last_used_at' => $now,
            ]);
            AiChatMessage::query()->create(['thread_id' => $thread->id, 'organisation_id' => $user->organisation_id, 'user_id' => $user->id, 'role' => 'user', 'body' => $text, 'created_at' => $now]);
            $assistant = AiChatMessage::query()->create([
                'thread_id' => $thread->id, 'organisation_id' => $user->organisation_id, 'user_id' => $user->id, 'role' => 'assistant', 'body' => $answer,
                'figures' => $figures === [] && $report === null ? null : ['figures' => $figures, 'report' => $report],
                'tools' => array_values(array_unique($used)), 'tokens_in' => $tokensIn, 'tokens_out' => $tokensOut, 'cost_sen' => $cost, 'created_at' => $now,
            ]);
            $thread->forceFill(['last_used_at' => $now])->save();

            return ['thread' => $thread, 'assistant' => $assistant];
        });
        $this->recorder->complete($run);
        // The audit row comes first in the result: if it cannot be written, nothing is returned.
        abort_if($this->audit($user, 'ok', ['tools' => array_values(array_unique($used)), 'tokens_in' => $tokensIn, 'tokens_out' => $tokensOut, 'cost_sen' => $cost]) === null, 503, 'Unable to record this message. Try again later.');

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
