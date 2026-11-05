<?php

namespace App\Domain\Agents\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Append-only step of an agent run (a PostgreSQL trigger refuses update and delete).
 *
 * @property int $id
 * @property int $ai_agent_run_id
 * @property int $sequence
 * @property string $kind
 * @property string|null $tool_code
 * @property string $summary
 * @property CarbonInterface $created_at
 */
class AiAgentRunStep extends Model
{
    public const KINDS = ['data_read', 'decision', 'note'];

    public const UPDATED_AT = null;

    protected $table = 'ai_agent_run_steps';

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Agent run steps are append-only.'));
        static::deleting(fn () => throw new LogicException('Agent run steps are append-only.'));
    }

    protected function casts(): array
    {
        return ['sequence' => 'integer', 'created_at' => 'datetime'];
    }

    /** @return BelongsTo<AiAgentRun, $this> */
    public function run(): BelongsTo
    {
        return $this->belongsTo(AiAgentRun::class, 'ai_agent_run_id');
    }
}
