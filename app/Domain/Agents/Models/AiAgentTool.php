<?php

namespace App\Domain\Agents\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One entry in an agent's tool allowlist.
 *
 * @property int $id
 * @property int $ai_agent_id
 * @property string $tool_code
 */
class AiAgentTool extends Model
{
    protected $table = 'ai_agent_tools';

    protected $guarded = ['id'];

    /** @return BelongsTo<AiAgent, $this> */
    public function agent(): BelongsTo
    {
        return $this->belongsTo(AiAgent::class, 'ai_agent_id');
    }
}
