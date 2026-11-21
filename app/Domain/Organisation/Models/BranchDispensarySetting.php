<?php

namespace App\Domain\Organisation\Models;

use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * DSP-23: per-branch Dispensary settings. A missing row counts as "doctor's check off".
 *
 * @property int $id
 * @property int $organisation_id
 * @property int $branch_id
 * @property bool $doctor_check_required
 * @property int $lock_version
 * @property int|null $updated_by_user_id
 * @property string|null $last_retry_key
 */
#[Guarded(['*'])]
class BranchDispensarySetting extends Model
{
    protected static function booted(): void
    {
        static::deleting(fn (): never => throw new LogicException('Branch Dispensary settings are retained.'));
    }

    protected function casts(): array
    {
        return ['doctor_check_required' => 'boolean', 'lock_version' => 'integer'];
    }
}
