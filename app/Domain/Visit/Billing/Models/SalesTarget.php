<?php

namespace App\Domain\Visit\Billing\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * BIL-30: one monthly sales target for a branch (no doctor) or for one doctor at that branch. Cleared, never deleted.
 *
 * @property int $id
 * @property int $organisation_id
 * @property int $branch_id
 * @property CarbonImmutable $month
 * @property int|null $doctor_user_id
 * @property int|null $amount_sen
 * @property CarbonImmutable|null $cleared_at
 * @property int $lock_version
 * @property int $created_by_user_id
 * @property int $updated_by_user_id
 */
#[Guarded(['*'])]
class SalesTarget extends Model
{
    protected static function booted(): void
    {
        static::deleting(fn (): never => throw new LogicException('Sales targets are cleared, never deleted.'));
    }

    protected function casts(): array
    {
        return [
            'month' => 'immutable_date', 'doctor_user_id' => 'integer', 'amount_sen' => 'integer', 'cleared_at' => 'immutable_datetime',
            'lock_version' => 'integer', 'branch_id' => 'integer', 'organisation_id' => 'integer',
        ];
    }
}
