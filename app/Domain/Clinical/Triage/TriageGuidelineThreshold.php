<?php

namespace App\Domain\Clinical\Triage;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * @property int $id
 * @property int $guideline_version_id
 * @property string $reading
 * @property string $age_band
 * @property string $direction
 * @property string $limit_value
 * @property string $zone
 */
class TriageGuidelineThreshold extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'triage_guideline_thresholds';

    protected $guarded = ['*'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Triage guideline thresholds are append-only.'));
        static::deleting(fn () => throw new LogicException('Triage guideline thresholds are append-only.'));
    }

    protected function casts(): array
    {
        return ['limit_value' => 'decimal:2'];
    }
}
