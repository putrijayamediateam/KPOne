<?php

namespace App\Domain\Clinical\Models;

use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

#[Guarded(['*'])]
class ConsultationCheckout extends Model
{
    protected static function booted(): void
    {
        static::deleting(fn (): never => throw new LogicException('Checkout evidence is retained.'));
        static::updating(function (self $checkout): void {
            if ($checkout->getOriginal('status') !== 'current' || $checkout->status !== 'superseded'
                || array_diff(array_keys($checkout->getDirty()), ['status', 'current_visit_guard', 'superseded_at', 'lock_version', 'updated_at']) !== []) {
                throw new LogicException('Checkout source evidence is immutable.');
            }
        });
    }

    protected function casts(): array
    {
        return ['lock_version' => 'integer', 'plan_version' => 'integer', 'encounter_version' => 'integer', 'checked_out_at' => 'immutable_datetime', 'superseded_at' => 'immutable_datetime'];
    }

    /** @return HasMany<ServiceDelivery, $this> */
    public function deliveries(): HasMany
    {
        return $this->hasMany(ServiceDelivery::class);
    }
}
