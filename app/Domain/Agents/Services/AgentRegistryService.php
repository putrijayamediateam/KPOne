<?php

namespace App\Domain\Agents\Services;

use App\Domain\Access\BranchAccessService;
use App\Domain\Access\TransactionalActorAuthority;
use App\Domain\Agents\AgentPermissions;
use App\Domain\Agents\Models\AiAgent;
use App\Domain\Audit\AuditRecorder;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** Enabling and disabling a registered agent (Director only; docs/agents/CONTRACT.md section 4). */
final class AgentRegistryService
{
    public function __construct(private BranchAccessService $branches, private TransactionalActorAuthority $authority, private AuditRecorder $audit) {}

    /** @param array<string, mixed> $input `is_enabled` and the `lock_version` the actor saw */
    public function setEnabled(User $actor, AiAgent $agent, array $input): AiAgent
    {
        $values = Validator::make($input, [
            'is_enabled' => ['required', 'boolean'],
            'lock_version' => ['required', 'integer', 'min:0'],
        ])->validate();
        $enabled = filter_var($values['is_enabled'], FILTER_VALIDATE_BOOLEAN);

        return DB::transaction(function () use ($actor, $agent, $values, $enabled): AiAgent {
            $branch = $this->branches->activeBranch($actor);
            abort_unless($branch && $branch->is_active && $branch->organisation_id === $actor->organisation_id, 403);
            $actor = $this->authority->lock($actor, $branch, AgentPermissions::MANAGE);
            if (! $actor->getAllPermissions()->pluck('name')->contains(AgentPermissions::MANAGE)) {
                throw new AuthorizationException('You may not perform this operation.');
            }
            $locked = AiAgent::query()->whereKey($agent->id)->where('organisation_id', $actor->organisation_id)->lockForUpdate()->first();
            abort_unless($locked !== null, 404);
            if ($locked->lock_version !== (int) $values['lock_version']) {
                throw ValidationException::withMessages(['lock_version' => 'This agent changed. Reload before saving.']);
            }
            if ($locked->is_enabled === $enabled) {
                return $locked;
            }
            $locked->forceFill(['is_enabled' => $enabled, 'lock_version' => $locked->lock_version + 1])->save();
            abort_if($this->audit->record($enabled ? 'ai_agent.enabled' : 'ai_agent.disabled', $locked, [
                'agent_id' => $locked->id,
                'agent_code' => $locked->code,
                'lock_version' => $locked->lock_version,
            ], $actor, $branch) === null, 503, 'Unable to record this change. Try again later.');

            return $locked;
        }, 3);
    }
}
