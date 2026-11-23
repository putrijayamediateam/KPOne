<?php

namespace App\Domain\Clinical\Triage;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * @property int $id
 * @property int $guideline_version_id
 * @property string $kind
 * @property string $code
 * @property string $label
 * @property int $position
 */
class TriageGuidelineSign extends Model
{
    public const RED_FLAG = 'red_flag';

    public const YELLOW_SIGN = 'yellow_sign';

    public const UPDATED_AT = null;

    protected $table = 'triage_guideline_signs';

    protected $guarded = ['*'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Triage guideline signs are append-only.'));
        static::deleting(fn () => throw new LogicException('Triage guideline signs are append-only.'));
    }

    protected function casts(): array
    {
        return ['position' => 'integer'];
    }
}
