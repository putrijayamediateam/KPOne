<?php

namespace App\Domain\Agents\Tools;

use App\Domain\Access\BranchAccessService;
use App\Domain\Agents\Models\AiAgent;
use App\Domain\Agents\Models\AiAgentRun;
use App\Domain\Agents\Services\AgentRunRecorder;
use App\Domain\Organisation\Models\Branch;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Validator;

/**
 * The only way an agent reads a data source (contract principles 2 and 5, section 5). Every read happens
 * inside an agent run, so it is always logged as a `data_read` step on that run. Every call checks: the
 * run's agent is in the requester's organisation, enabled and allowed the tool; the requester is active,
 * is the person who asked for the run (when the run has one) and holds the tool's permission; the branch
 * is the run's branch (when the run has one), active and in the organisation, and a `.branch` tool needs a
 * branch where the requester has an effective assignment; the input matches the tool's schema. The result
 * is scanned for personal data. Nothing here writes clinic data.
 */
final class AgentToolGateway
{
    public function __construct(
        private AgentToolRegistry $registry,
        private BranchAccessService $branches,
        private AggregatePayloadGuard $guard,
        private AgentRunRecorder $recorder,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function call(AiAgentRun $run, string $toolCode, User $requester, ?Branch $branch = null, array $input = []): array
    {
        $tool = $this->registry->get($toolCode);
        $requester = User::query()->whereKey($requester->id)->with(['roles.permissions', 'permissions'])->first();
        if (! $requester || ! $requester->is_active) {
            throw new AuthorizationException('You may not ask an agent to read this.');
        }
        $run = AiAgentRun::query()->whereKey($run->id)->where('organisation_id', $requester->organisation_id)->first();
        if ($run !== null && ! in_array($run->status, ['working', 'awaiting_approval'], true)) {
            throw new AuthorizationException('Data is read only inside a started, unfinished agent run.');
        }
        if (! $run || ($run->requested_by_user_id !== null && $run->requested_by_user_id !== $requester->id)) {
            throw new AuthorizationException('This agent run was asked for by someone else.');
        }
        if ($run->branch_id !== null && $branch !== null && $branch->id !== $run->branch_id) {
            throw new AuthorizationException('This agent run is for another branch.');
        }
        $agent = AiAgent::query()->whereKey($run->ai_agent_id)->where('organisation_id', $requester->organisation_id)->first();
        if (! $agent || ! $agent->is_enabled || ! $agent->allows($tool->code())) {
            throw new AuthorizationException('This agent may not use that data source.');
        }
        if (! $requester->can($tool->requiredPermission())) {
            throw new AuthorizationException('You may not see this data.');
        }
        $branch ??= $run->branch_id !== null ? Branch::query()->find($run->branch_id) : null;
        if ($branch !== null) {
            $branch = Branch::query()->whereKey($branch->id)->where('organisation_id', $requester->organisation_id)->where('is_active', true)->first();
            if (! $branch) {
                throw new AuthorizationException('This branch is not available.');
            }
        }
        if (str_ends_with($tool->requiredPermission(), '.branch') && ($branch === null || ! $this->branches->hasEffectiveAssignment($requester, $branch))) {
            throw new AuthorizationException('You may not see this data for this branch.');
        }

        $validated = Validator::make($input, $tool->inputSchema())->validate();
        $result = $tool->run($requester, $branch, $validated);
        $this->guard->assertAggregate($result);
        $this->recorder->step($run, 'data_read', 'Read '.$tool->code().($branch ? ' for '.$branch->code : ''), $tool->code());

        return $result;
    }
}
