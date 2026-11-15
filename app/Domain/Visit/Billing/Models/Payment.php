<?php

namespace App\Domain\Visit\Billing\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * @property int $id
 * @property string $public_id
 * @property int $organisation_id
 * @property int $branch_id
 * @property int $patient_id
 * @property int $payment_method_id
 * @property int $amount_sen
 * @property int $lock_version
 * @property int $recorded_by_user_id
 * @property string $status
 * @property string $receipt_number
 * @property string $method_snapshot
 * @property string $payload_hash
 * @property CarbonImmutable $received_at
 */
#[Guarded(['*'])]
class Payment extends Model
{
    protected static function booted(): void
    {
        static::deleting(fn (): never => throw new LogicException('Financial source and evidence records are retained.'));
        static::updating(function (self $payment): void {
            if ($payment->isDirty('status') && ! ($payment->getOriginal('status') === 'posted' && $payment->status === 'reversed')) {
                throw new LogicException('A reversed receipt cannot be reactivated.');
            }
            if (array_diff(array_keys($payment->getDirty()), ['status', 'lock_version', 'updated_at']) !== []) {
                throw new LogicException('Posted receipt evidence is immutable.');
            }
        });
    }

    protected function casts(): array
    {
        return ['amount_sen' => 'integer', 'lock_version' => 'integer', 'received_at' => 'immutable_datetime'];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }
}
