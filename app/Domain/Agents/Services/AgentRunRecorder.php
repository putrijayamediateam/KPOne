<?php

namespace App\Domain\Agents\Services;

use App\Domain\Access\TransactionalActorAuthority;
use App\Domain\Agents\Models\AiAgent;
use App\Domain\Agents\Models\AiAgentMessage;
use App\Domain\Agents\Models\AiAgentRun;
use App\Domain\Agents\Models\AiAgentRunStep;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Organisation\Models\Branch;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The only writer of agent runs, steps and messages (docs/agents/CONTRACT.md sections 2 and 3).
 *
 * A run either has a requesting person, who must be active, in the agent's organisation, hold the
 * caller's stated permission and have an effective assignment at the run's branch, or none (a scheduled run).
 * Each step, message and status change locks the run row inside a transaction. Starting and
 * finishing a run writes a structural audit row (identifiers only); steps and messages are
 * themselves append-only evidence.
 */
final class AgentRunRecorder
{
    /** Allowed status changes; finished statuses never change again. */
    private const TRANSITIONS = [
        'queued' => ['working', 'failed', 'cancelled'],
        'working' => ['awaiting_approval', 'completed', 'failed', 'cancelled'],
        'awaiting_approval' => ['working', 'completed', 'failed', 'cancelled'],
    ];

    public const MAX_SUMMARY = 200;

    public function __construct(private TransactionalActorAuthority $authority, private AuditRecorder $audit) {}

    /**
     * Start a run. With an idempotency key, a retry with the same agent, task and branch returns the
     * original run; a different request under the same key is refused.
     */
    public function start(
        AiAgent|string $agent,
        string $taskType,
        ?Branch $branch = null,
        ?User $requester = null,
        ?string $permission = null,
        ?string $idempotencyKey = null,
        bool $queued = false,
        ?int $organisationId = null,
    ): AiAgentRun {
        if (! preg_match('/^[a-z][a-z0-9_]{1,59}$/', $taskType)) {
            throw ValidationException::withMessages(['task_type' => 'The task type must be a short lowercase code.']);
        }
        if ($idempotencyKey !== null && ! preg_match('/^[A-Za-z0-9:._-]{8,100}$/', $idempotencyKey)) {
            throw ValidationException::withMessages(['idempotency_key' => 'The idempotency key is not valid.']);
        }

        try {
            return DB::transaction(fn (): AiAgentRun => $this->startLocked($agent, $taskType, $branch, $requester, $permission, $idempotencyKey, $queued, $organisationId), 3);
        } catch (QueryException $exception) {
            // A concurrent retry with the same key won the insert: return its run.
            if ($idempotencyKey !== null && $exception->getCode() === '23505') {
                return DB::transaction(fn (): AiAgentRun => $this->startLocked($agent, $taskType, $branch, $requester, $permission, $idempotencyKey, $queued, $organisationId), 3);
            }
            throw $exception;
        }
    }

    public function begin(AiAgentRun $run): AiAgentRun
    {
        return $this->transition($run, 'working');
    }

    public function awaitApproval(AiAgentRun $run): AiAgentRun
    {
        return $this->transition($run, 'awaiting_approval');
    }

    public function complete(AiAgentRun $run): AiAgentRun
    {
        return $this->transition($run, 'completed');
    }

    /** @param string $errorCode a short code such as `data_source.refused`, never free text */
    public function fail(AiAgentRun $run, string $errorCode): AiAgentRun
    {
        if (! preg_match('/^[a-z][a-z0-9_.]{0,59}$/', $errorCode)) {
            throw ValidationException::withMessages(['error_code' => 'The error code must be a short lowercase code.']);
        }

        return $this->transition($run, 'failed', $errorCode);
    }

    public function cancel(AiAgentRun $run): AiAgentRun
    {
        return $this->transition($run, 'cancelled');
    }

    /** Append a step. A data read must name a tool on the agent's allowlist. */
    public function step(AiAgentRun $run, string $kind, string $summary, ?string $toolCode = null): AiAgentRunStep
    {
        if (! in_array($kind, AiAgentRunStep::KINDS, true)) {
            throw ValidationException::withMessages(['kind' => 'Unknown step kind.']);
        }
        if (($kind === 'data_read') !== ($toolCode !== null)) {
            throw ValidationException::withMessages(['tool_code' => 'Only a data read names a tool, and every data read must.']);
        }
        $summary = $this->summary($summary, self::MAX_SUMMARY);

        return DB::transaction(function () use ($run, $kind, $summary, $toolCode): AiAgentRunStep {
            $locked = $this->lockActive($run);
            if ($toolCode !== null && ! $locked->agent()->firstOrFail()->allows($toolCode)) {
                throw new AuthorizationException('This agent may not use that data source.');
            }
            $sequence = (int) AiAgentRunStep::query()->where('ai_agent_run_id', $locked->id)->max('sequence') + 1;
            $step = AiAgentRunStep::query()->create([
                'ai_agent_run_id' => $locked->id,
                'sequence' => $sequence,
                'kind' => $kind,
                'tool_code' => $toolCode,
                'summary' => $summary,
                'created_at' => now(),
            ]);
            $locked->forceFill(['last_step_summary' => $summary])->save();
            $run->setRawAttributes($locked->getAttributes(), true);

            return $step;
        }, 3);
    }

    /** Record a message from the run's agent to another agent of the same organisation. */
    public function message(AiAgentRun $run, AiAgent|string $to, string $body): AiAgentMessage
    {
        $body = $this->summary($body, AiAgentMessage::MAX_BODY);

        return DB::transaction(function () use ($run, $to, $body): AiAgentMessage {
            $locked = $this->lockActive($run);
            $recipient = $this->agent($to, $locked->organisation_id);

            return AiAgentMessage::query()->create([
                'ai_agent_run_id' => $locked->id,
                'from_agent_id' => $locked->ai_agent_id,
                'to_agent_id' => $recipient->id,
                'body' => $body,
                'created_at' => now(),
            ]);
        }, 3);
    }

    private function startLocked(AiAgent|string $agent, string $taskType, ?Branch $branch, ?User $requester, ?string $permission, ?string $idempotencyKey, bool $queued, ?int $organisationId): AiAgentRun
    {
        $organisationId = match (true) {
            $requester !== null => $requester->organisation_id,
            $branch !== null => $branch->organisation_id,
            $agent instanceof AiAgent => $agent->organisation_id,
            default => $organisationId,
        };
        if ($organisationId === null) {
            throw new AuthorizationException('An agent run needs an organisation.');
        }
        // Lock order matches AgentRegistryService: requester and grants first, then the agent.
        if ($requester !== null) {
            $requester = $this->authoriseRequester($requester, $organisationId, $branch, $permission);
        }
        $agent = $this->agent($agent, $organisationId, lock: true);

        if ($idempotencyKey !== null) {
            $existing = AiAgentRun::query()->where('organisation_id', $organisationId)->where('idempotency_key', $idempotencyKey)->lockForUpdate()->first();
            if ($existing) {
                if ($existing->ai_agent_id !== $agent->id || $existing->task_type !== $taskType || $existing->branch_id !== $branch?->id || $existing->requested_by_user_id !== $requester?->id) {
                    throw ValidationException::withMessages(['idempotency_key' => 'This key was already used for a different agent task.']);
                }

                return $existing;
            }
        }

        if (! $agent->is_enabled) {
            throw ValidationException::withMessages(['agent' => 'This agent is disabled.']);
        }
        if ($branch !== null) {
            $lockedBranch = Branch::query()->whereKey($branch->id)->where('organisation_id', $organisationId)->sharedLock()->first();
            if (! $lockedBranch || ! $lockedBranch->is_active) {
                throw new AuthorizationException('This branch is not available.');
            }
        }

        $run = AiAgentRun::query()->create([
            'organisation_id' => $organisationId,
            'branch_id' => $branch?->id,
            'ai_agent_id' => $agent->id,
            'requested_by_user_id' => $requester?->id,
            'task_type' => $taskType,
            'status' => $queued ? 'queued' : 'working',
            'started_at' => $queued ? null : now(),
            'idempotency_key' => $idempotencyKey,
        ]);
        $this->recordAudit('ai_agent_run.started', $run, $requester, $branch);

        return $run;
    }

    /**
     * The requester must be active, in the run's organisation and hold the caller's stated permission.
     * At a branch, TransactionalActorAuthority also requires an effective assignment there and locks
     * the grant rows. A branch-scoped (`.branch` / `.own`) permission never authorises a run without a branch.
     */
    private function authoriseRequester(User $requester, int $organisationId, ?Branch $branch, ?string $permission): User
    {
        if ($permission === null || $requester->organisation_id !== $organisationId) {
            throw new AuthorizationException('You may not ask this agent to work.');
        }
        if ($branch !== null) {
            return $this->authority->lock($requester, $branch, $permission);
        }
        if (! str_ends_with($permission, '.organisation')) {
            throw new AuthorizationException('A branch is needed for this request.');
        }
        $locked = User::query()->whereKey($requester->id)->where('organisation_id', $organisationId)->lockForUpdate()->first();
        if (! $locked || ! $locked->is_active || ! $locked->can($permission)) {
            throw new AuthorizationException('You may not ask this agent to work.');
        }

        return $locked;
    }

    private function transition(AiAgentRun $run, string $status, ?string $errorCode = null): AiAgentRun
    {
        return DB::transaction(function () use ($run, $status, $errorCode): AiAgentRun {
            $locked = AiAgentRun::query()->whereKey($run->id)->lockForUpdate()->firstOrFail();
            if ($locked->status === $status && $status !== 'working') {
                $run->setRawAttributes($locked->getAttributes(), true);

                return $run;
            }
            if (! in_array($status, self::TRANSITIONS[$locked->status] ?? [], true)) {
                throw ValidationException::withMessages(['status' => "An agent run cannot move from {$locked->status} to {$status}."]);
            }
            $values = ['status' => $status];
            if ($status === 'working' && $locked->started_at === null) {
                $values['started_at'] = now();
            }
            if (in_array($status, AiAgentRun::FINISHED, true)) {
                $values['finished_at'] = now();
                $values['error_code'] = $errorCode;
                if ($status !== 'cancelled' && $locked->started_at === null) {
                    $values['started_at'] = $values['finished_at'];
                }
            }
            $locked->forceFill($values)->save();
            if (in_array($status, AiAgentRun::FINISHED, true)) {
                $branch = $locked->branch_id !== null ? Branch::query()->find($locked->branch_id) : null;
                $requester = $locked->requested_by_user_id !== null ? User::query()->find($locked->requested_by_user_id) : null;
                $this->recordAudit('ai_agent_run.finished', $locked, $requester, $branch, ['status' => $status, 'error_code' => $errorCode]);
            }
            $run->setRawAttributes($locked->getAttributes(), true);

            return $run;
        }, 3);
    }

    private function lockActive(AiAgentRun $run): AiAgentRun
    {
        $locked = AiAgentRun::query()->whereKey($run->id)->lockForUpdate()->firstOrFail();
        if ($locked->isFinished()) {
            throw ValidationException::withMessages(['status' => 'This agent run has finished.']);
        }
        if ($locked->status === 'queued') {
            throw ValidationException::withMessages(['status' => 'This agent run has not started.']);
        }

        return $locked;
    }

    private function agent(AiAgent|string $agent, int $organisationId, bool $lock = false): AiAgent
    {
        $query = AiAgent::query()->where('organisation_id', $organisationId)
            ->where($agent instanceof AiAgent ? 'id' : 'code', $agent instanceof AiAgent ? $agent->id : $agent);
        $found = ($lock ? $query->lockForUpdate() : $query)->first();
        if (! $found) {
            throw new AuthorizationException('Unknown agent for this organisation.');
        }

        return $found;
    }

    /**
     * Summaries and messages are short, single-line operational text. Refuse text that looks like an
     * identity number, email address or phone number. Best-effort defence in depth only: callers must
     * build summaries from fixed templates and aggregate figures.
     */
    private function summary(string $text, int $max): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? '');
        if ($text === '' || mb_strlen($text) > $max) {
            throw ValidationException::withMessages(['summary' => "The text must be between 1 and {$max} characters."]);
        }
        if (PersonalText::looksPersonal($text)) {
            throw ValidationException::withMessages(['summary' => 'The text must not contain identity numbers, email addresses or phone numbers.']);
        }

        return $text;
    }

    /** @param array<string, mixed> $extra */
    private function recordAudit(string $event, AiAgentRun $run, ?User $actor, ?Branch $branch, array $extra = []): void
    {
        $recorded = $this->audit->record($event, $run, [
            'run_id' => $run->id,
            'agent_id' => $run->ai_agent_id,
            'task_type' => $run->task_type,
            'branch_id' => $run->branch_id,
            ...$extra,
        ], $actor, $branch, $run->organisation_id);
        abort_if($recorded === null, 503, 'Unable to record this agent activity. Try again later.');
    }
}
