<?php

namespace App\Domain\Agents\Health;

use App\Domain\Agents\Models\AiAgent;
use App\Domain\Agents\Models\AiAgentRun;
use App\Domain\Agents\Services\AgentRunRecorder;
use App\Domain\Agents\Tools\AgentToolGateway;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Faris, the technical admin agent (AG-10, docs/agents/BRIEFS.md). Called by both daily runs (AG-8): reads
 * `system.health` through the gateway inside the caller's run, counting since the start of Faris's previous
 * completed health check (or the last 24 hours), records one counts-only decision step and returns the line
 * for the daily summary. Faris changes nothing, proposes nothing and sends no message.
 *
 * The requester is the person whose authority the read uses. The 07:00 and 14:00 runs act as "system"
 * (contract section 5), so AG-8 adds the gateway's system path and a matching entry point here. Call it outside
 * an outer transaction: a failed pending-migration lookup is swallowed and would abort that transaction.
 */
final class SystemHealthOfficer
{
    public const AGENT = 'technical_admin_agent';

    public const TASK = 'system_health';

    public function __construct(private AgentToolGateway $gateway, private AgentRunRecorder $recorder) {}

    public function check(AiAgentRun $run, User $requester): SystemHealthCheck
    {
        $agent = AiAgent::query()->whereKey($run->ai_agent_id)->where('organisation_id', $run->organisation_id)->first();
        if (! $agent || $agent->code !== self::AGENT) {
            throw new AuthorizationException('Only the technical admin agent runs this check.');
        }
        if ($run->task_type !== self::TASK) {
            throw new AuthorizationException('A system health check runs under the system_health task only.');
        }
        // The previous check read the log after it started, so counting from its start leaves no gap (a small
        // overlap with the previous window is accepted).
        $previous = AiAgentRun::query()->where('organisation_id', $run->organisation_id)->where('ai_agent_id', $agent->id)
            ->where('task_type', self::TASK)->where('status', 'completed')->whereKeyNot($run->id)->max('started_at');
        $input = $previous !== null ? ['since' => (string) $previous] : [];

        $result = SystemHealthCheck::fromRead($this->gateway->call($run, 'system.health', $requester, null, $input));
        $this->recorder->step($run, 'decision', $result->summary());

        return $result;
    }
}
