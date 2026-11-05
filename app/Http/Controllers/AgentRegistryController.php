<?php

namespace App\Http\Controllers;

use App\Domain\Agents\AgentPermissions;
use App\Domain\Agents\Models\AiAgent;
use App\Domain\Agents\Models\AiAgentRun;
use App\Domain\Agents\Services\AgentOfficeFeed;
use App\Domain\Agents\Services\AgentRegistryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** AG-1: agent registry page, activity log, enable/disable and the read-only office feed. */
class AgentRegistryController extends Controller
{
    public const ACTIVITY_LIMIT = 25;

    public function index(Request $request, AgentOfficeFeed $feed): Response
    {
        $organisationId = $request->user()->organisation_id;
        $office = collect($feed->build($organisationId)['agents'])->keyBy('code');
        $agents = AiAgent::query()->with(['department:id,name', 'tools' => fn ($q) => $q->orderBy('tool_code')])
            ->where('organisation_id', $organisationId)->orderByDesc('kind')->orderBy('display_name')->orderBy('id')->get();

        return Inertia::render('Agents/Index', [
            'canManage' => $request->user()->can(AgentPermissions::MANAGE),
            'agents' => $agents->map(fn (AiAgent $agent): array => [
                'id' => $agent->id,
                'code' => $agent->code,
                'displayName' => $agent->display_name,
                'jobTitle' => $agent->job_title,
                'department' => $agent->department?->name,
                'kind' => $agent->kind,
                'isEnabled' => $agent->is_enabled,
                'lockVersion' => $agent->lock_version,
                'tools' => $agent->tools->pluck('tool_code')->values()->all(),
                'light' => $office[$agent->code]['light'] ?? 'grey',
                'currentTask' => $office[$agent->code]['currentTask'] ?? null,
                'lastStep' => $office[$agent->code]['lastStep'] ?? null,
                'lastStepAt' => $office[$agent->code]['lastStepAt'] ?? null,
            ])->values()->all(),
            'activity' => AiAgentRun::query()->with(['agent:id,display_name', 'branch:id,name'])
                ->where('organisation_id', $organisationId)->orderByDesc('id')->limit(self::ACTIVITY_LIMIT)->get()
                ->map(fn (AiAgentRun $run): array => [
                    'id' => $run->id,
                    'agent' => $run->agent?->display_name,
                    'taskType' => $run->task_type,
                    'branch' => $run->branch?->name,
                    'status' => $run->status,
                    'scheduled' => $run->requested_by_user_id === null,
                    'startedAt' => $run->started_at?->utc()->format('Y-m-d\TH:i:s\Z'),
                    'finishedAt' => $run->finished_at?->utc()->format('Y-m-d\TH:i:s\Z'),
                    'lastStep' => $run->last_step_summary,
                    'errorCode' => $run->error_code,
                ])->values()->all(),
        ]);
    }

    public function enabled(Request $request, AiAgent $agent, AgentRegistryService $registry): RedirectResponse
    {
        abort_unless($agent->organisation_id === $request->user()->organisation_id, 404);
        try {
            $updated = $registry->setEnabled($request->user(), $agent, $request->only(['is_enabled', 'lock_version']));
        } catch (ValidationException $exception) {
            throw $exception->redirectTo(route('agents.index'));
        }
        Inertia::flash('toast', ['type' => 'success', 'message' => $updated->is_enabled ? 'Agent enabled.' : 'Agent disabled.']);

        return to_route('agents.index');
    }

    public function feed(Request $request, AgentOfficeFeed $feed): JsonResponse
    {
        return response()->json($feed->build($request->user()->organisation_id));
    }
}
