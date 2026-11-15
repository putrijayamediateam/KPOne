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
class InvoiceAdjustment extends Model
{
    protected $hidden = ['reason', 'payload_hash', 'idempotency_key'];

    protected static function booted(): void
    {
        static::deleting(fn (): never => throw new LogicException('Adjustment evidence is retained.'));
        static::updating(fn (): never => throw new LogicException('Adjustment evidence is immutable.'));
    }

    protected function casts(): array
    {
        return ['base_sen' => 'integer', 'amount_sen' => 'integer', 'input_value' => 'integer', 'threshold_version' => 'integer', 'threshold_sen' => 'integer', 'limit_overridden' => 'boolean', 'invoice_version' => 'integer'];
    }
}
