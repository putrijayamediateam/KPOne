<?php

namespace App\Domain\Agents\Services;

use App\Domain\Agents\Models\AiAgent;
use App\Domain\Agents\Models\AiAgentMessage;
use App\Domain\Agents\Models\AiAgentRun;
use App\Domain\Agents\Models\AiAgentRunStep;
use App\Domain\Organisation\Models\Branch;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Read-only office feed (docs/agents/CONTRACT.md sections 3 and 11). Agent names, titles, task codes,
 * step summaries and message summaries only: no patient or staff personal data.
 */
final class AgentOfficeFeed
{
    public const MESSAGE_LIMIT = 20;

    public const MESSAGE_SUMMARY = 120;

    /** @return array<string, mixed> */
    public function build(int $organisationId): array
    {
        $now = now();
        $agents = AiAgent::query()->with('department:id,name')->where('organisation_id', $organisationId)->orderBy('department_id')->orderBy('display_name')->orderBy('id')->get();
        $agentIds = $agents->pluck('id');

        // The newest run per agent and the newest active run per agent.
        $latestRuns = AiAgentRun::query()->whereIn('id', AiAgentRun::query()->selectRaw('max(id)')->whereIn('ai_agent_id', $agentIds)->groupBy('ai_agent_id'))->get()->keyBy('ai_agent_id');
        $activeRuns = AiAgentRun::query()->whereIn('id', AiAgentRun::query()->selectRaw('max(id)')->whereIn('ai_agent_id', $agentIds)->whereIn('status', AiAgentRun::ACTIVE)->groupBy('ai_agent_id'))->get()->keyBy('ai_agent_id');
        $working = AiAgentRun::query()->whereIn('ai_agent_id', $agentIds)->where('status', 'working')->distinct()->pluck('ai_agent_id')->flip();
        $awaiting = AiAgentRun::query()->whereIn('ai_agent_id', $agentIds)->where('status', 'awaiting_approval')->distinct()->pluck('ai_agent_id')->flip();
        $lastSteps = AiAgentRunStep::query()
            ->join('ai_agent_runs', 'ai_agent_runs.id', '=', 'ai_agent_run_steps.ai_agent_run_id')
            ->whereIn('ai_agent_run_steps.id', AiAgentRunStep::query()->selectRaw('max(ai_agent_run_steps.id)')->join('ai_agent_runs', 'ai_agent_runs.id', '=', 'ai_agent_run_steps.ai_agent_run_id')->whereIn('ai_agent_runs.ai_agent_id', $agentIds)->groupBy('ai_agent_runs.ai_agent_id'))
            ->get(['ai_agent_runs.ai_agent_id', 'ai_agent_run_steps.summary', 'ai_agent_run_steps.created_at'])
            ->keyBy('ai_agent_id');
        $pending = $this->pendingProposals($organisationId);
        $branchNames = Branch::query()->whereIn('id', $activeRuns->pluck('branch_id')->filter())->pluck('name', 'id');
        $codes = $agents->pluck('code', 'id');

        return [
            'serverTime' => $this->iso($now),
            'pendingProposals' => (int) $pending->sum(),
            'agents' => $agents->map(function (AiAgent $agent) use ($latestRuns, $activeRuns, $working, $awaiting, $lastSteps, $pending, $branchNames, $now): array {
                $agentPending = (int) ($pending[$agent->id] ?? 0);
                $latest = $latestRuns->get($agent->id);
                $active = $activeRuns->get($agent->id);
                $step = $lastSteps->get($agent->id);

                return [
                    'code' => $agent->code,
                    'displayName' => $agent->display_name,
                    'jobTitle' => $agent->job_title,
                    'department' => $agent->department?->name,
                    'kind' => $agent->kind,
                    'avatarKey' => $agent->avatar_key,
                    'isEnabled' => $agent->is_enabled,
                    'light' => match (true) {
                        $working->has($agent->id) => 'green',
                        $agentPending > 0 || $awaiting->has($agent->id) => 'yellow',
                        $latest !== null && $latest->status === 'failed' && $latest->finished_at !== null && $latest->finished_at->greaterThanOrEqualTo($now->copy()->subDay()) => 'red',
                        default => 'grey',
                    },
                    'currentTask' => $active ? $this->task($active, $branchNames) : null,
                    'lastStep' => $step?->getAttribute('summary'),
                    'lastStepAt' => $step ? $this->iso(Date::parse($step->getAttribute('created_at'))) : null,
                    'pendingProposals' => $agentPending,
                ];
            })->values()->all(),
            'messages' => AiAgentMessage::query()
                ->join('ai_agent_runs', 'ai_agent_runs.id', '=', 'ai_agent_messages.ai_agent_run_id')
                ->where('ai_agent_runs.organisation_id', $organisationId)
                ->orderByDesc('ai_agent_messages.id')
                ->limit(self::MESSAGE_LIMIT)
                ->get(['ai_agent_messages.*'])
                ->map(fn (AiAgentMessage $message): array => [
                    'from' => $codes[$message->from_agent_id] ?? null,
                    'to' => $codes[$message->to_agent_id] ?? null,
                    'summary' => $this->shorten($message),
                    'at' => $this->iso($message->created_at),
                ])->values()->all(),
        ];
    }

    /** @param Collection<int, string> $branchNames */
    private function task(AiAgentRun $run, Collection $branchNames): string
    {
        $label = Str::ucfirst(str_replace('_', ' ', $run->task_type));

        return $run->branch_id !== null && isset($branchNames[$run->branch_id]) ? $label.', '.$branchNames[$run->branch_id] : $label;
    }

    /**
     * Pending proposals per agent. The `ai_proposals` table arrives with AG-2 (contract section 2);
     * until then every count is zero.
     *
     * @return Collection<int, int>
     */
    private function pendingProposals(int $organisationId): Collection
    {
        if (! Schema::hasTable('ai_proposals')) {
            return collect();
        }

        return DB::table('ai_proposals')->where('organisation_id', $organisationId)->where('status', 'pending')
            ->groupBy('ai_agent_id')->selectRaw('ai_agent_id, count(*) as aggregate')->pluck('aggregate', 'ai_agent_id')
            ->map(fn ($count): int => (int) $count);
    }

    /** At most 120 characters; an unreadable body (for example after a key problem) never breaks the feed. */
    private function shorten(AiAgentMessage $message): string
    {
        try {
            $body = (string) $message->getAttribute('body');
        } catch (DecryptException) {
            return 'Message unavailable';
        }

        return mb_strlen($body) > self::MESSAGE_SUMMARY ? mb_substr($body, 0, self::MESSAGE_SUMMARY - 1).'…' : $body;
    }

    private function iso(CarbonInterface $time): string
    {
        return $time->copy()->utc()->format('Y-m-d\TH:i:s\Z');
    }
}
