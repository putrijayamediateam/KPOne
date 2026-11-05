<?php

namespace App\Domain\Agents\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Append-only message from one agent to another (feeds the office speech bubbles). The body is
 * encrypted at rest and never holds patient or staff personal data.
 *
 * @property int $id
 * @property int $ai_agent_run_id
 * @property int $from_agent_id
 * @property int $to_agent_id
 * @property string $body
 * @property CarbonInterface $created_at
 */
class AiAgentMessage extends Model
{
    public const MAX_BODY = 2000;

    public const UPDATED_AT = null;

    protected $table = 'ai_agent_messages';

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Agent messages are append-only.'));
        static::deleting(fn () => throw new LogicException('Agent messages are append-only.'));
    }

    protected function casts(): array
    {
        return ['body' => 'encrypted', 'created_at' => 'datetime'];
    }

    /** @return BelongsTo<AiAgent, $this> */
    public function from(): BelongsTo
    {
        return $this->belongsTo(AiAgent::class, 'from_agent_id');
    }

    /** @return BelongsTo<AiAgent, $this> */
    public function to(): BelongsTo
    {
        return $this->belongsTo(AiAgent::class, 'to_agent_id');
    }
}
