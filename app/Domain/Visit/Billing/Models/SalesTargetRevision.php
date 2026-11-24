<?php

namespace App\Domain\Visit\Billing\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * BIL-30: one set, change or clear of a sales target. Append-only.
 *
 * @property int $id
 * @property int $organisation_id
 * @property int $branch_id
 * @property int $sales_target_id
 * @property CarbonImmutable $month
 * @property int|null $doctor_user_id
 * @property string $action
 * @property int|null $old_amount_sen
 * @property int|null $new_amount_sen
 * @property string|null $reason
 * @property int $target_version
 * @property int $actor_user_id
 * @property string $idempotency_key
 * @property string $payload_hash
 * @property CarbonImmutable $created_at
 */
#[Guarded(['*'])]
class SalesTargetRevision extends Model
{
    public const UPDATED_AT = null;

    protected static function booted(): void
    {
        static::updating(fn (): never => throw new LogicException('Sales target revisions are append-only.'));
        static::deleting(fn (): never => throw new LogicException('Sales target revisions are retained.'));
    }

    protected function casts(): array
    {
        return [
            'month' => 'immutable_date', 'doctor_user_id' => 'integer', 'old_amount_sen' => 'integer', 'new_amount_sen' => 'integer',
            'target_version' => 'integer', 'actor_user_id' => 'integer', 'created_at' => 'immutable_datetime',
        ];
    }
}
