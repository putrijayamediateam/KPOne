<?php

namespace App\Domain\Agents\Ask;

use App\Domain\Agents\AgentPermissions;
use App\Domain\Agents\Connector\ConnectorAgent;
use App\Domain\Agents\Services\AgentRunRecorder;
use App\Domain\Agents\Services\PersonalText;
use App\Domain\Audit\AuditRecorder;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * "Ask through Claude" (docs/agents/CHAT-2-ASK-THROUGH-CLAUDE-DESIGN.md). The Director types a question in KPOne; KPOne keeps a
 * pending request and gives the Director a prompt to send to their own Claude; Claude answers with the connector tools and
 * calls `save_answer`, which is the only write the connector has: the answer text of one pending request of the caller.
 * Never touches clinical, patient, money, stock, staff or settings data. Audit rows hold ids, lengths and outcomes, never text.
 */
final class AskRequestService
{
    public const MAX_QUESTION = 1000;

    public const MAX_ANSWER = 4000;

    public const MAX_PENDING = 3;

    private const CODE_ALPHABET = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';

    private const NOT_VALID = 'That request id is not valid, has expired or was already answered. Ask again from the KPOne panel.';

    public function __construct(private AuditRecorder $audit, private AgentRunRecorder $recorder) {}

    /**
     * Start a request. A retry with the same `key` returns the same request (nothing new is created or counted).
     *
     * @return array<string, mixed>
     *
     * @throws AskRefused
     */
    public function create(User $user, string $text, ?string $key = null): array
    {
        $this->assertMayAsk($user);
        $text = trim($text);
        if ($text === '' || mb_strlen($text) > self::MAX_QUESTION) {
            throw new AskRefused('invalid_question', 'Type a question of 1 to '.self::MAX_QUESTION.' characters.');
        }
        if (PersonalText::looksPersonal($text)) {
            $this->audit($user, 'ai_ask.refused', ['reason' => 'personal_data']);
            throw new AskRefused('personal_data', "Please don't type identity numbers, phone numbers or emails.");
        }
        if ($key !== null) {
            $existing = AiAskRequest::query()->where('user_id', $user->id)->where('request_key', $key)->first();
            if ($existing) {
                return $this->present($this->expireIfDue($existing));
            }
        }
        if (RateLimiter::tooManyAttempts('ask-create-min:'.$user->id, 10) || RateLimiter::tooManyAttempts('ask-create-day:'.$user->id, 30)) {
            $this->audit($user, 'ai_ask.refused', ['reason' => 'rate_limited']);
            throw new AskRefused('rate_limited', 'You have asked a lot of questions. Wait a little and try again.', 429);
        }

        $request = DB::transaction(function () use ($user, $text, $key): AiAskRequest {
            // One person's requests are counted and created under their own user row lock, so two clicks cannot pass the cap together.
            User::query()->whereKey($user->id)->lockForUpdate()->first();
            $this->expireStale($user);
            $pending = AiAskRequest::query()->where('user_id', $user->id)->where('status', 'pending')->count();
            if ($pending >= self::MAX_PENDING) {
                throw new AskRefused('too_many_pending', 'You already have '.self::MAX_PENDING.' questions waiting for Claude. Wait for an answer or let them expire.', 429);
            }
            $created = AiAskRequest::query()->create([
                'organisation_id' => $user->organisation_id,
                'user_id' => $user->id,
                'request_code' => $this->newCode(),
                'request_key' => $key,
                'question' => $text,
                'question_chars' => mb_strlen($text),
                'status' => 'pending',
                'created_at' => now(),
                'expires_at' => now()->addMinutes((int) config('agents.ask_expiry_minutes', 30)),
            ]);
            $this->audit($user, 'ai_ask.created', ['request' => $created->id, 'chars' => $created->question_chars]);

            return $created;
        });
        RateLimiter::hit('ask-create-min:'.$user->id, 60);
        RateLimiter::hit('ask-create-day:'.$user->id, 86400);

        return $this->present($request);
    }

    /**
     * One of the caller's own requests (the poll).
     *
     * @return array<string, mixed>
     *
     * @throws AskRefused
     */
    public function show(User $user, string $code): array
    {
        $this->assertMayAsk($user);

        return $this->present($this->expireIfDue($this->own($user, $code)));
    }

    /** @return array<string, mixed> */
    public function recent(User $user): array
    {
        $this->assertMayAsk($user);
        $rows = AiAskRequest::query()->where('user_id', $user->id)->orderByDesc('id')->limit(20)->get()->map(fn (AiAskRequest $each): AiAskRequest => $this->expireIfDue($each));

        return [
            'maxChars' => self::MAX_QUESTION,
            'expiryMinutes' => (int) config('agents.ask_expiry_minutes', 30),
            'requests' => $rows->map(fn (AiAskRequest $each): array => $this->present($each))->values()->all(),
        ];
    }

    /**
     * Cancel a pending request of the caller.
     *
     * @return array<string, mixed>
     *
     * @throws AskRefused
     */
    public function cancel(User $user, string $code): array
    {
        $this->assertMayAsk($user);

        return DB::transaction(function () use ($user, $code): array {
            $row = AiAskRequest::query()->where('user_id', $user->id)->where('request_code', $code)->lockForUpdate()->first();
            if (! $row) {
                throw new AskRefused('not_found', self::NOT_VALID, 404);
            }
            if ($row->status === 'pending') {
                $row->forceFill(['status' => 'cancelled'])->save();
                $this->audit($user, 'ai_ask.cancelled', ['request' => $row->id]);
            }

            return $this->present($row);
        });
    }

    /**
     * The connector's one write. Only the caller's own pending, unexpired request; only the answer text, once.
     * Every refusal gives the same plain message for an unknown, expired, answered, cancelled or someone else's id.
     *
     * @throws AskRefused
     */
    public function saveAnswer(User $user, string $code, string $text): void
    {
        if (! AskSwitch::enabled() || ! $user->is_active || ! $user->can(AgentPermissions::CONNECTOR_USE) || ! $user->can(AgentPermissions::CHAT_USE)) {
            throw new AskRefused('not_allowed', 'Saving an answer is not available to you.', 403);
        }
        if (RateLimiter::tooManyAttempts('ask-save-min:'.$user->id, 10) || RateLimiter::tooManyAttempts('ask-save-day:'.$user->id, 30)) {
            $this->audit($user, 'ai_ask.refused', ['reason' => 'rate_limited']);
            throw new AskRefused('rate_limited', 'Too many answers were saved. Wait a little and try again.', 429);
        }
        RateLimiter::hit('ask-save-min:'.$user->id, 60);
        RateLimiter::hit('ask-save-day:'.$user->id, 86400);

        $code = strtoupper(trim($code));
        $clean = trim((string) preg_replace('/<\/?[a-zA-Z!][^>]*>/', '', $text));
        if ($clean === '' || mb_strlen($clean) > self::MAX_ANSWER) {
            $this->audit($user, 'ai_ask.refused', ['reason' => 'invalid_answer', 'chars' => mb_strlen($text)]);
            throw new AskRefused('invalid_answer', 'Give an answer of 1 to '.self::MAX_ANSWER.' characters.');
        }
        if (PersonalText::looksPersonal($clean)) {
            $this->audit($user, 'ai_ask.refused', ['reason' => 'personal_data', 'chars' => mb_strlen($clean)]);
            throw new AskRefused('personal_data', 'The answer looks like it holds an identity number, phone number or email, so it was not saved. Leave those out and try again.');
        }

        try {
            $run = $this->recorder->start(ConnectorAgent::ensure($user->organisation_id), 'ask_answer', null, $user, AgentPermissions::CONNECTOR_USE);
        } catch (AuthorizationException|ValidationException) {
            throw new AskRefused('agent_unavailable', 'The connector is not available right now.', 503);
        }

        $outcome = DB::transaction(function () use ($user, $code, $clean): string {
            $row = AiAskRequest::query()->where('organisation_id', $user->organisation_id)->where('user_id', $user->id)->where('request_code', $code)->lockForUpdate()->first();
            if (! $row) {
                return 'not_found';
            }
            if ($row->status === 'answered') {
                // A retry of the same answer is safe; a different text after the first answer is refused.
                return $row->answer !== null && hash_equals($row->answer, $clean) ? 'saved' : 'closed';
            }
            if ($row->status !== 'pending') {
                return 'closed';
            }
            if ($row->expires_at->isPast()) {
                $row->forceFill(['status' => 'expired'])->save();
                $this->audit($user, 'ai_ask.expired', ['request' => $row->id]);

                return 'expired';
            }
            $row->forceFill(['status' => 'answered', 'answer' => $clean, 'answered_at' => now()])->save();
            $this->audit($user, 'ai_ask.answered', ['request' => $row->id, 'chars' => mb_strlen($clean)]);

            return 'saved';
        });

        if ($outcome !== 'saved') {
            $this->recorder->fail($run, 'ask.'.$outcome);
            $this->audit($user, 'ai_ask.refused', ['reason' => $outcome]);
            throw new AskRefused($outcome, self::NOT_VALID);
        }
        $this->recorder->step($run, 'decision', 'Saved one answer to an Ask through Claude request ('.mb_strlen($clean).' characters).');
        $this->recorder->complete($run);
    }

    /**
     * Mark requests past their time as expired for everyone (the daily command); returns how many.
     */
    public function expireAllDue(): int
    {
        $expired = 0;
        AiAskRequest::query()->where('status', 'pending')->where('expires_at', '<', now())->orderBy('id')->chunkById(200, function ($rows) use (&$expired): void {
            foreach ($rows as $row) {
                DB::transaction(function () use ($row, &$expired): void {
                    $locked = AiAskRequest::query()->whereKey($row->id)->lockForUpdate()->first();
                    if ($locked && $locked->status === 'pending' && $locked->expires_at->isPast()) {
                        $locked->forceFill(['status' => 'expired'])->save();
                        $this->audit(null, 'ai_ask.expired', ['request' => $locked->id], $locked->organisation_id);
                        $expired++;
                    }
                });
            }
        });

        return $expired;
    }

    /** @return array<string, mixed> */
    private function present(AiAskRequest $row): array
    {
        $out = [
            'id' => $row->request_code,
            'status' => $row->status,
            'question' => $row->question,
            'answer' => $row->answer,
            'createdAt' => $row->created_at->toIso8601String(),
            'expiresAt' => $row->expires_at->toIso8601String(),
            'answeredAt' => $row->answered_at?->toIso8601String(),
            'label' => $row->status === 'answered' ? 'Answered by Claude. KPOne did not check this answer.' : null,
        ];
        if ($row->status === 'pending' && $row->question !== null) {
            $out += AskPrompt::links(AskPrompt::build($row->request_code, $row->question));
        }

        return $out;
    }

    private function own(User $user, string $code): AiAskRequest
    {
        $row = AiAskRequest::query()->where('organisation_id', $user->organisation_id)->where('user_id', $user->id)->where('request_code', strtoupper(trim($code)))->first();
        if (! $row) {
            throw new AskRefused('not_found', self::NOT_VALID, 404);
        }

        return $row;
    }

    private function expireIfDue(AiAskRequest $row): AiAskRequest
    {
        if ($row->status !== 'pending' || ! $row->expires_at->isPast()) {
            return $row;
        }

        return DB::transaction(function () use ($row): AiAskRequest {
            $locked = AiAskRequest::query()->whereKey($row->id)->lockForUpdate()->firstOrFail();
            if ($locked->status === 'pending' && $locked->expires_at->isPast()) {
                $locked->forceFill(['status' => 'expired'])->save();
                $this->audit(null, 'ai_ask.expired', ['request' => $locked->id], $locked->organisation_id);
            }

            return $locked;
        });
    }

    /** Called with the user's row locked: stale pending requests no longer count against the cap. */
    private function expireStale(User $user): void
    {
        AiAskRequest::query()->where('user_id', $user->id)->where('status', 'pending')->where('expires_at', '<', now())->lockForUpdate()->get()->each(function (AiAskRequest $row): void {
            $row->forceFill(['status' => 'expired'])->save();
            $this->audit(null, 'ai_ask.expired', ['request' => $row->id], $row->organisation_id);
        });
    }

    private function newCode(): string
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $code = '';
            for ($i = 0; $i < 10; $i++) {
                $code .= self::CODE_ALPHABET[random_int(0, strlen(self::CODE_ALPHABET) - 1)];
            }
            if (! AiAskRequest::query()->where('request_code', $code)->exists()) {
                return $code;
            }
        }

        return strtoupper(Str::random(12));
    }

    private function assertMayAsk(User $user): void
    {
        if (! AskSwitch::enabled()) {
            throw new AskRefused('off', 'Ask through Claude is not switched on.', 404);
        }
        if (! $user->is_active || ! $user->can(AgentPermissions::CHAT_USE)) {
            throw new AskRefused('not_allowed', 'You may not use Ask through Claude.', 403);
        }
    }

    /** @param array<string, mixed> $metadata */
    private function audit(?User $user, string $event, array $metadata, ?int $organisationId = null): void
    {
        $this->audit->record($event, null, $metadata, $user, null, $user instanceof User ? $user->organisation_id : $organisationId);
    }
}
