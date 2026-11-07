<?php

namespace App\Domain\Agents\DailySummary;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * One stored section of the daily summary (AG-8, contract section 14): the result of one completed 07:00 or
 * 14:00 agent run for one branch (Faris: the organisation). Aggregates only; never changed or deleted.
 *
 * @property int $id
 * @property int $organisation_id
 * @property int|null $branch_id
 * @property CarbonInterface $local_date
 * @property string $slot
 * @property string $section
 * @property int $ai_agent_run_id
 * @property array<string, mixed> $payload
 * @property string $payload_hash
 * @property CarbonInterface|null $created_at
 */
class AiDailySummarySection extends Model
{
    public const SECTIONS = ['lina', 'hana', 'rizal', 'faris'];

    public const SLOTS = ['0700', '1400'];

    public const UPDATED_AT = null;

    protected $table = 'ai_daily_summary_sections';

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Daily summary sections never change.'));
        static::deleting(fn () => throw new LogicException('Daily summary sections are never deleted.'));
    }

    protected function casts(): array
    {
        return ['payload' => 'encrypted:array', 'local_date' => 'date'];
    }
}
