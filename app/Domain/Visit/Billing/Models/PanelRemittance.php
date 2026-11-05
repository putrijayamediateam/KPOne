<?php

namespace App\Domain\Visit\Billing\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * PNL-3: one Panel payment split across several of that Panel's accepted claims. Append-only.
 *
 * @property int $id
 * @property string $public_id
 * @property int $organisation_id
 * @property int $branch_id
 * @property int $panel_id
 * @property int $amount_sen
 * @property string $currency
 * @property string|null $reference
 * @property string $idempotency_key
 * @property string $payload_hash
 * @property CarbonImmutable $received_at
 * @property int $recorded_by_user_id
 * @property CarbonImmutable|null $created_at
 */
#[Guarded(['*'])]
class PanelRemittance extends Model
{
    protected static function booted(): void
    {
        static::updating(fn (): never => throw new LogicException('Panel remittance evidence is immutable.'));
        static::deleting(fn (): never => throw new LogicException('Panel remittance evidence is retained.'));
    }

    protected function casts(): array
    {
        return ['amount_sen' => 'integer', 'received_at' => 'immutable_datetime', 'created_at' => 'immutable_datetime'];
    }

    /** @return HasMany<PanelClaimPayment, $this> */
    public function splits(): HasMany
    {
        return $this->hasMany(PanelClaimPayment::class, 'panel_remittance_id');
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }
}
