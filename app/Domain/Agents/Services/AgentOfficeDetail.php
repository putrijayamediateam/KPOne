<?php

namespace App\Domain\Agents\Services;

use App\Domain\Access\BranchAccessService;
use App\Domain\Agents\AgentPermissions;
use App\Domain\Agents\Models\AiAgent;
use App\Domain\Agents\Models\AiAgentRunStep;
use App\Domain\Agents\Proposals\AiProposal;
use App\Domain\Agents\Proposals\ProposalTypes;
use App\Models\User;
use Carbon\CarbonInterface;

/**
 * AG-3: what the office click panel shows for one agent. Step summaries (fixed templates), the agent's
 * pending count (the office feed already shows it) and, like the approval inbox, the pending proposals of
 * the viewer's active branch only when they hold `ai_proposals.view.branch` and an effective assignment
 * there. Types, branch and time only: never a proposal payload, patient or staff personal data.
 */
final class AgentOfficeDetail
{
    public const STEP_LIMIT = 5;

    public const PROPOSAL_LIMIT = 10;

    public function __construct(private readonly BranchAccessService $branches) {}

    /** @return array<string, mixed>|null */
    public function build(User $viewer, string $code): ?array
    {
        $organisationId = $viewer->organisation_id;
        $agent = AiAgent::query()->with('department:id,name')->where('organisation_id', $organisationId)->where('code', $code)->first();

        if ($agent === null) {
            return null;
        }

        $pending = AiProposal::query()->where('organisation_id', $organisationId)->where('ai_agent_id', $agent->id)->where('status', 'pending');
        $branch = $viewer->can(AgentPermissions::PROPOSALS_VIEW) ? $this->branches->activeBranch($viewer) : null;
        $branch = $branch !== null && $branch->organisation_id === $organisationId && $this->branches->hasEffectiveAssignment($viewer, $branch) ? $branch : null;

        return [
            'code' => $agent->code,
            'displayName' => $agent->display_name,
            'jobTitle' => $agent->job_title,
            'department' => $agent->department?->name,
            'isEnabled' => $agent->is_enabled,
            'steps' => AiAgentRunStep::query()
                ->join('ai_agent_runs', 'ai_agent_runs.id', '=', 'ai_agent_run_steps.ai_agent_run_id')
                ->where('ai_agent_runs.organisation_id', $organisationId)
                ->where('ai_agent_runs.ai_agent_id', $agent->id)
                ->orderByDesc('ai_agent_run_steps.id')
                ->limit(self::STEP_LIMIT)
                ->get(['ai_agent_run_steps.kind', 'ai_agent_run_steps.summary', 'ai_agent_run_steps.created_at'])
                ->map(fn (AiAgentRunStep $step): array => [
                    'kind' => $step->kind,
                    'summary' => $step->summary,
                    'at' => $this->iso($step->created_at),
                ])->values()->all(),
            'pendingTotal' => (clone $pending)->count(),
            'pendingBranch' => $branch?->name,
            'pending' => $branch === null ? [] : (clone $pending)->where('branch_id', $branch->id)->orderByDesc('id')->limit(self::PROPOSAL_LIMIT)->get()
                ->map(fn (AiProposal $proposal): array => [
                    'id' => $proposal->id,
                    'typeLabel' => ProposalTypes::all()[$proposal->type]['label'] ?? $proposal->type,
                    'createdAt' => $this->iso($proposal->created_at),
                ])->values()->all(),
        ];
    }

    private function iso(?CarbonInterface $time): ?string
    {
        return $time?->copy()->utc()->format('Y-m-d\TH:i:s\Z');
    }
}
