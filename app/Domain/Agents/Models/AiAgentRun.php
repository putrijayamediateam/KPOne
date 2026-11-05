<?php

namespace App\Domain\Agents\Models;

use App\Domain\Organisation\Models\Branch;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One agent task. Created and moved through its statuses only by AgentRunRecorder.
 *
 * @property int $id
 * @property int $organisation_id
 * @property int|null $branch_id
 * @property int $ai_agent_id
 * @property int|null $requested_by_user_id
 * @property string $task_type
 * @property string $status
 * @property CarbonInterface|null $started_at
 * @property CarbonInterface|null $finished_at
 * @property string|null $last_step_summary
 * @property string|null $error_code
 * @property string|null $idempotency_key
 */
class AiAgentRun extends Model
{
    public const STATUSES = ['queued', 'working', 'awaiting_approval', 'completed', 'failed', 'cancelled'];

    public const ACTIVE = ['queued', 'working', 'awaiting_approval'];

    public const FINISHED = ['completed', 'failed', 'cancelled'];

    protected $table = 'ai_agent_runs';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['started_at' => 'datetime', 'finished_at' => 'datetime'];
    }

    public function isFinished(): bool
    {
        return in_array($this->status, self::FINISHED, true);
    }

    /** @return BelongsTo<AiAgent, $this> */
    public function agent(): BelongsTo
    {
        return $this->belongsTo(AiAgent::class, 'ai_agent_id');
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** @return HasMany<AiAgentRunStep, $this> */
    public function steps(): HasMany
    {
        return $this->hasMany(AiAgentRunStep::class, 'ai_agent_run_id');
    }
}
