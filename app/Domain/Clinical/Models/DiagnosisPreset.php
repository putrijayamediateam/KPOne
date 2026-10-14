<?php

namespace App\Domain\Clinical\Models;

use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Model;
use LogicException;

#[Guarded(['*'])]
class DiagnosisPreset extends Model
{
    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'lock_version' => 'integer'];
    }

    protected static function booted(): void
    {
        static::deleting(fn (): never => throw new LogicException('Diagnosis presets are retained; archive them instead.'));
        static::updating(function (self $preset): void {
            if ($preset->isDirty(['organisation_id', 'created_by_user_id'])) {
                throw new LogicException('Diagnosis preset ownership is immutable.');
            }
        });
    }
}
