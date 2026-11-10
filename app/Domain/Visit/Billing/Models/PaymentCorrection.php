<?php

namespace App\Domain\Visit\Billing\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * BIL-16: links a receipt reversed as a recording error on a completed visit to the replacement receipt for what was
 * really received (none when nothing was received). Append-only evidence.
 *
 * @property int $id
 * @property string $public_id
 * @property int $organisation_id
 * @property int $branch_id
 * @property int $invoice_id
 * @property int $original_payment_id
 * @property int $payment_reversal_id
 * @property int|null $replacement_payment_id
 * @property int $received_sen
 * @property int $corrected_by_user_id
 * @property string $idempotency_key
 * @property string $payload_hash
 * @property CarbonImmutable $created_at
 */
#[Guarded(['*'])]
class PaymentCorrection extends Model
{
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return ['created_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn (): never => throw new LogicException('Receipt corrections are immutable.'));
        static::deleting(fn (): never => throw new LogicException('Receipt corrections are retained.'));
    }
}
