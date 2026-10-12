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
 * @property int $panel_claim_id
 * @property int $amount_sen
 * @property string $currency
 * @property string|null $reference
 * @property string $idempotency_key
 * @property string $payload_hash
 * @property CarbonImmutable $received_at
 * @property int $recorded_by_user_id
 */
#[Guarded(['*'])]
class PanelClaimPayment extends Model
{
    protected static function booted(): void
    {
        static::updating(fn (): never => throw new LogicException('Panel remittance evidence is immutable.'));
        static::deleting(fn (): never => throw new LogicException('Panel remittance evidence is retained.'));
    }

    protected function casts(): array
    {
        return ['amount_sen' => 'integer', 'received_at' => 'immutable_datetime'];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }
}
