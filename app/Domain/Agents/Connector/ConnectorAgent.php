<?php

namespace App\Domain\Agents\Connector;

use App\Domain\Agents\Models\AiAgent;
use App\Domain\Agents\Models\AiAgentTool;
use App\Domain\Organisation\Models\Department;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * The registered "Claude connector" agent (docs/agents/MCP-CONNECTOR-DESIGN.md section 2): every connector call is one
 * short run of this agent, so the gateway's checks and the /agents activity log are reused. It is created on first use
 * (a migration cannot, because a fresh database has no organisation yet), is labelled "(AI agent)" and can be disabled
 * by the Director on /agents, which refuses every connector call. Its allowlist is exactly the connector's tools.
 */
final class ConnectorAgent
{
    public const CODE = 'claude_connector';

    public static function ensure(int $organisationId): AiAgent
    {
        $agent = AiAgent::query()->where('organisation_id', $organisationId)->where('code', self::CODE)->first();
        if (! $agent) {
            try {
                $agent = DB::transaction(fn (): AiAgent => AiAgent::query()->create([
                    'organisation_id' => $organisationId,
                    'code' => self::CODE,
                    'display_name' => 'Claude connector',
                    'job_title' => "Director's connector (AI agent)",
                    'department_id' => Department::query()->where('organisation_id', $organisationId)->where('code', 'TECHNOLOGY')->value('id'),
                    'kind' => 'runtime',
                    'is_enabled' => true,
                    'lock_version' => 0,
                ]));
            } catch (UniqueConstraintViolationException) {
                // Two first calls at once: the other one created it.
                $agent = AiAgent::query()->where('organisation_id', $organisationId)->where('code', self::CODE)->firstOrFail();
            }
        }
        $have = AiAgentTool::query()->where('ai_agent_id', $agent->id)->pluck('tool_code')->all();
        foreach (array_diff(ConnectorTools::codes(), $have) as $code) {
            try {
                AiAgentTool::query()->firstOrCreate(['ai_agent_id' => $agent->id, 'tool_code' => $code]);
            } catch (UniqueConstraintViolationException) {
                // Created by a concurrent first call.
            }
        }

        return $agent;
    }
}
