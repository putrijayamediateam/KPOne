<?php

namespace App\Domain\Agents\Proposals;

use App\Domain\Access\TransactionalActorAuthority;
use App\Domain\Agents\Models\AiAgent;
use App\Domain\Agents\Models\AiAgentRun;
use App\Domain\Agents\Services\AgentRunRecorder;
use App\Domain\Agents\Services\PersonalText;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Organisation\Models\Branch;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * The approval inbox (docs/agents/CONTRACT.md section 6). Agents propose; a person holding the type's
 * domain permission at the proposal's branch approves or rejects. In wave 1 a decision records the
 * decision and an audit row only: it creates no purchase order, transfer, write-off or stock movement.
 */
final class ProposalService
{
    public const MAX_REASON = 500;

    public function __construct(private TransactionalActorAuthority $authority, private AuditRecorder $audit, private AgentRunRecorder $recorder) {}

    /**
     * Called by an agent during its run. Idempotent: the same key with the same proposal returns the
     * original; the same key with a different proposal is refused.
     *
     * @param  array<string, mixed>  $payload
     */
    public function propose(AiAgentRun $run, string $type, Branch $branch, array $payload, string $idempotencyKey): AiProposal
    {
        $definition = ProposalTypes::get($type);
        if (! preg_match('/^[A-Za-z0-9:._-]{8,100}$/', $idempotencyKey)) {
            throw ValidationException::withMessages(['idempotency_key' => 'The idempotency key is not valid.']);
        }
        if (array_diff(array_keys($payload), array_keys($definition['rules'])) !== []) {
            throw ValidationException::withMessages(['payload' => 'The proposal holds fields this type does not allow.']);
        }
        $values = $this->normalise(Validator::make($payload, $definition['rules'])->validate(), $definition['rules']);
        ksort($values);
        $json = json_encode($values, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $hash = hash('sha256', $json);

        try {
            return DB::transaction(fn (): AiProposal => $this->proposeLocked($run, $type, $definition, $branch, $values, $hash, $idempotencyKey), 3);
        } catch (QueryException $exception) {
            if ($exception->getCode() === '23505') {
                return DB::transaction(fn (): AiProposal => $this->proposeLocked($run, $type, $definition, $branch, $values, $hash, $idempotencyKey), 3);
            }
            throw $exception;
        }
    }

    /** @param array<string, mixed> $input `lock_version`, optional `reason` */
    public function approve(User $actor, AiProposal $proposal, array $input): AiProposal
    {
        return $this->decide($actor, $proposal, 'approved', $input);
    }

    /** @param array<string, mixed> $input `lock_version` and a required `reason` */
    public function reject(User $actor, AiProposal $proposal, array $input): AiProposal
    {
        return $this->decide($actor, $proposal, 'rejected', $input);
    }

    /**
     * The proposing agent withdraws a pending proposal it no longer stands by (for example, superseded by a
     * later review). Any active run of the same agent may withdraw, but only at the proposal's branch.
     */
    public function withdraw(AiAgentRun $run, AiProposal $proposal): AiProposal
    {
        return DB::transaction(function () use ($run, $proposal): AiProposal {
            $run = AiAgentRun::query()->whereKey($run->id)->lockForUpdate()->firstOrFail();
            $locked = AiProposal::query()->whereKey($proposal->id)->where('organisation_id', $run->organisation_id)->lockForUpdate()->first();
            if (! $locked || $locked->ai_agent_id !== $run->ai_agent_id || ! in_array($run->status, ['working', 'awaiting_approval'], true)
                || ($run->branch_id !== null && $run->branch_id !== $locked->branch_id)) {
                throw new AuthorizationException('Only the proposing agent, during a run, may withdraw this proposal.');
            }
            if ($locked->status === 'withdrawn') {
                return $locked;
            }
            if ($locked->status !== 'pending') {
                throw ValidationException::withMessages(['status' => 'This proposal has already been decided.']);
            }
            $locked->forceFill(['status' => 'withdrawn', 'decided_at' => now(), 'lock_version' => $locked->lock_version + 1])->save();
            $this->recordAudit('ai_proposal.withdrawn', $locked, null, ['run_id' => $run->id]);

            return $locked;
        }, 3);
    }

    /**
     * Whether this person may decide this proposal now: the type's permission with an effective
     * assignment at the proposal's branch, and not the person who asked for the run that proposed it.
     */
    public function canDecide(User $actor, AiProposal $proposal, bool $assigned): bool
    {
        return $proposal->status === 'pending' && $this->cannotDecideReason($actor, $proposal, $assigned) === null;
    }

    /**
     * Why this person cannot decide a pending proposal, in plain words for the inbox, or null when they can (or when it
     * is no longer pending, which the page already says). Read only: it decides nothing and the decision paths keep
     * their own checks. The wording never names another person.
     */
    public function cannotDecideReason(User $actor, AiProposal $proposal, bool $assigned): ?string
    {
        if ($proposal->status !== 'pending') {
            return null;
        }

        if (! $assigned) {
            return 'You are not assigned to this branch now, so someone else must decide.';
        }

        if (! $actor->can(ProposalTypes::get($proposal->type)['permission'])) {
            return 'Your role can see this suggestion but not decide it, so someone else must decide.';
        }

        if ($proposal->run?->requested_by_user_id === $actor->id) {
            return 'You started the run that made this suggestion, so someone else must decide.';
        }

        return null;
    }

    /**
     * @param  array{label: string, agent: string, permission: string, rules: array<string, list<string>>}  $definition
     * @param  array<string, mixed>  $values
     */
    private function proposeLocked(AiAgentRun $run, string $type, array $definition, Branch $branch, array $values, string $hash, string $idempotencyKey): AiProposal
    {
        $run = AiAgentRun::query()->whereKey($run->id)->lockForUpdate()->firstOrFail();
        $existing = AiProposal::query()->where('organisation_id', $run->organisation_id)->where('idempotency_key', $idempotencyKey)->lockForUpdate()->first();
        if ($existing) {
            if ($existing->type !== $type || $existing->branch_id !== $branch->id || $existing->ai_agent_id !== $run->ai_agent_id || $existing->payload_hash !== $hash) {
                throw ValidationException::withMessages(['idempotency_key' => 'This key was already used for a different proposal.']);
            }

            return $existing;
        }
        if (! in_array($run->status, ['working', 'awaiting_approval'], true)) {
            throw ValidationException::withMessages(['status' => 'Proposals are made only during a started, unfinished run.']);
        }
        $agent = AiAgent::query()->whereKey($run->ai_agent_id)->where('organisation_id', $run->organisation_id)->sharedLock()->first();
        if (! $agent || ! $agent->is_enabled || $agent->code !== $definition['agent']) {
            throw new AuthorizationException('This agent may not make this kind of proposal.');
        }
        $branch = Branch::query()->whereKey($branch->id)->where('organisation_id', $run->organisation_id)->where('is_active', true)->sharedLock()->first();
        if (! $branch || ($run->branch_id !== null && $run->branch_id !== $branch->id)) {
            throw new AuthorizationException('Proposals are made for the run\'s own branch.');
        }
        $this->assertReferences($run->organisation_id, $branch, $type, $values);

        $proposal = AiProposal::query()->create([
            'organisation_id' => $run->organisation_id,
            'branch_id' => $branch->id,
            'ai_agent_run_id' => $run->id,
            'ai_agent_id' => $agent->id,
            'type' => $type,
            'payload' => $values,
            'payload_hash' => $hash,
            'status' => 'pending',
            'lock_version' => 0,
            'idempotency_key' => $idempotencyKey,
        ]);
        $this->recordAudit('ai_proposal.proposed', $proposal, null, ['run_id' => $run->id]);
        $this->recorder->step($run, 'decision', 'Proposed: '.$definition['label']);

        return $proposal;
    }

    /** @param array<string, mixed> $input */
    private function decide(User $actor, AiProposal $proposal, string $status, array $input): AiProposal
    {
        $input['reason'] = isset($input['reason']) && is_string($input['reason']) ? trim((string) preg_replace('/\s+/u', ' ', $input['reason'])) : ($input['reason'] ?? null);
        if ($input['reason'] === '') {
            $input['reason'] = null;
        }
        $values = Validator::make($input, [
            'lock_version' => ['required', 'integer', 'min:0'],
            'reason' => [$status === 'rejected' ? 'required' : 'nullable', 'string', 'min:3', 'max:'.self::MAX_REASON],
        ])->validate();
        $reason = $values['reason'] ?? null;
        if ($reason !== null && PersonalText::looksPersonal($reason)) {
            throw ValidationException::withMessages(['reason' => 'The reason must not contain identity numbers, email addresses or phone numbers.']);
        }

        return DB::transaction(function () use ($actor, $proposal, $status, $values, $reason): AiProposal {
            $branch = Branch::query()->whereKey($proposal->branch_id)->where('organisation_id', $actor->organisation_id)->first();
            abort_unless($branch !== null, 404);
            $permission = ProposalTypes::get($proposal->type)['permission'];
            $actor = $this->authority->lock($actor, $branch, $permission);
            $locked = AiProposal::query()->whereKey($proposal->id)->where('organisation_id', $actor->organisation_id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'pending') {
                if ($locked->status === $status && $locked->decided_by_user_id === $actor->id) {
                    return $locked; // a retry of the same decision
                }
                throw ValidationException::withMessages(['status' => 'This proposal has already been decided.']);
            }
            if ($locked->lock_version !== (int) $values['lock_version']) {
                throw ValidationException::withMessages(['lock_version' => 'This proposal changed. Reload before deciding.']);
            }
            $requester = AiAgentRun::query()->whereKey($locked->ai_agent_run_id)->value('requested_by_user_id');
            if ($requester !== null && (int) $requester === $actor->id) {
                throw new AuthorizationException('You asked for the run that made this proposal, so someone else must decide it.');
            }
            $locked->forceFill([
                'status' => $status,
                'decided_by_user_id' => $actor->id,
                'decided_at' => now(),
                'decision_reason' => $reason,
                'lock_version' => $locked->lock_version + 1,
            ])->save();
            $this->recordAudit('ai_proposal.'.$status, $locked, $actor, ['has_reason' => $reason !== null]);

            return $locked;
        }, 3);
    }

    /**
     * One meaning, one form: quantities become exact three-decimal text and whole-number fields integers,
     * so a retry that writes "3" for "3.000" is the same proposal (and the same hash).
     *
     * @param  array<string, mixed>  $values
     * @param  array<string, list<string>>  $rules
     * @return array<string, mixed>
     */
    private function normalise(array $values, array $rules): array
    {
        foreach ($rules as $key => $rule) {
            if (! array_key_exists($key, $values) || $values[$key] === null) {
                continue;
            }
            if (in_array('integer', $rule, true)) {
                $values[$key] = (int) $values[$key];
            } elseif (in_array(ProposalTypes::QUANTITY[2], $rule, true)) {
                [$whole, $fraction] = array_pad(explode('.', (string) $values[$key], 2), 2, '');
                $values[$key] = ltrim($whole, '0').'.'.str_pad($fraction, 3, '0');
                $values[$key] = str_starts_with($values[$key], '.') ? '0'.$values[$key] : $values[$key];
            }
        }

        return $values;
    }

    /** @param array<string, mixed> $values */
    private function assertReferences(int $organisationId, Branch $branch, string $type, array $values): void
    {
        $sku = DB::table('inventory_skus')->where('organisation_id', $organisationId)->where('public_id', $values['sku_public_id'])->value('id');
        $ok = $sku !== null;
        if ($ok && isset($values['location_public_id'])) {
            $ok = DB::table('inventory_locations')->where('organisation_id', $organisationId)->where('branch_id', $branch->id)->where('public_id', $values['location_public_id'])->exists();
        }
        if ($ok && isset($values['batch_public_id'])) {
            $ok = DB::table('inventory_batches')->where('organisation_id', $organisationId)->where('inventory_sku_id', $sku)->where('public_id', $values['batch_public_id'])->exists();
        }
        if ($ok && $type === 'inventory.transfer_suggestion') {
            $ok = $values['to_branch_code'] === $branch->code
                && DB::table('branches')->where('organisation_id', $organisationId)->where('code', $values['from_branch_code'])->where('is_active', true)->exists();
        }
        if (! $ok) {
            throw ValidationException::withMessages(['payload' => 'The proposal refers to stock, a location or a branch that does not match.']);
        }
    }

    /** @param array<string, mixed> $extra */
    private function recordAudit(string $event, AiProposal $proposal, ?User $actor, array $extra = []): void
    {
        $branch = Branch::query()->find($proposal->branch_id);
        $recorded = $this->audit->record($event, $proposal, [
            'proposal_id' => $proposal->id,
            'type' => $proposal->type,
            'agent_id' => $proposal->ai_agent_id,
            'branch_id' => $proposal->branch_id,
            'lock_version' => $proposal->lock_version,
            ...$extra,
        ], $actor, $branch, $proposal->organisation_id);
        abort_if($recorded === null, 503, 'Unable to record this proposal change. Try again later.');
    }
}
