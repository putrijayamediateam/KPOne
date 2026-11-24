<?php

namespace App\Domain\Agents\Chat;

use App\Domain\Agents\Models\AiAgent;
use App\Domain\Organisation\Models\Department;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * The registered "AI chatbox" agent (docs/agents/AI-CHATBOX-DESIGN.md section 5): every chatbox message is one short run of this
 * agent, so the /agents activity log shows it and the Director can disable it there to refuse every message at once. It reads no
 * data itself (its questions go through the connector's tools, which have their own agent and audit), so it has no allowlist.
 * Created on first use, labelled "(AI agent)".
 */
final class ChatAgent
{
    public const CODE = 'ai_chatbox';

    public static function ensure(int $organisationId): AiAgent
    {
        $agent = AiAgent::query()->where('organisation_id', $organisationId)->where('code', self::CODE)->first();
        if ($agent) {
            return $agent;
        }
        try {
            return DB::transaction(fn (): AiAgent => AiAgent::query()->create([
                'organisation_id' => $organisationId,
                'code' => self::CODE,
                'display_name' => 'AI chatbox',
                'job_title' => "Director's chatbox (AI agent)",
                'department_id' => Department::query()->where('organisation_id', $organisationId)->where('code', 'TECHNOLOGY')->value('id'),
                'kind' => 'runtime',
                'is_enabled' => true,
                'lock_version' => 0,
            ]));
        } catch (UniqueConstraintViolationException) {
            return AiAgent::query()->where('organisation_id', $organisationId)->where('code', self::CODE)->firstOrFail();
        }
    }
}
