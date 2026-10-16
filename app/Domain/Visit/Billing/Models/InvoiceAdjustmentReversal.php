<?php

namespace App\Domain\Visit\Billing\Models;

use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * @property int $id
 * @property string $public_id
 * @property int $actor_user_id
 * @property string $payload_hash
 */
#[Guarded(['*'])]
class InvoiceAdjustmentReversal extends Model
{
    protected $hidden = ['reason', 'payload_hash', 'idempotency_key'];

    protected static function booted(): void
    {
        static::deleting(fn (): never => throw new LogicException('Adjustment evidence is retained.'));
        static::updating(fn (): never => throw new LogicException('Adjustment evidence is immutable.'));
    }

    protected function casts(): array
    {
        return ['amount_sen' => 'integer', 'invoice_version' => 'integer'];
    }
}
