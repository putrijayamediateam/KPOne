<?php

namespace App\Domain\Visit\Billing\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * BIL-6: the approval or rejection of a refund by someone other than the recorder. Append-only, one per refund.
 *
 * @property int $id
 * @property int $organisation_id
 * @property int $payment_refund_id
 * @property string $decision
 * @property string|null $reason_code
 * @property int $decided_by_user_id
 * @property CarbonImmutable $decided_at
 * @property string $idempotency_key
 * @property string $payload_hash
 */
#[Guarded(['*'])]
class PaymentRefundDecision extends Model
{
    public $timestamps = false;

    protected static function booted(): void
    {
        static::updating(fn (): never => throw new LogicException('Refund decisions are immutable.'));
        static::deleting(fn (): never => throw new LogicException('Refund decisions are retained.'));
    }

    protected function casts(): array
    {
        return ['payment_refund_id' => 'integer', 'decided_by_user_id' => 'integer', 'decided_at' => 'immutable_datetime'];
    }
}
