<?php

namespace App\Domain\Clinical\Models;

use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/** SUG-1b: a standard dosing or instruction phrase in English and Bahasa Melayu. Retained: archive, never delete. */
#[Guarded(['*'])]
class PrescribingPhrase extends Model
{
    public const KINDS = ['frequency' => 'Frequency', 'instruction' => 'Instruction', 'precaution' => 'Precaution', 'duration' => 'Duration'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'lock_version' => 'integer', 'approved_at' => 'datetime', 'edited_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::deleting(fn (): never => throw new LogicException('Prescribing phrases are retained; archive them instead.'));
        static::updating(function (self $phrase): void {
            if ($phrase->isDirty(['organisation_id', 'created_by_user_id', 'kind'])) {
                throw new LogicException('Prescribing phrase ownership and kind are immutable.');
            }
        });
    }
}
