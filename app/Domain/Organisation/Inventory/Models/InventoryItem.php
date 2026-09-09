<?php

namespace App\Domain\Organisation\Inventory\Models;

use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

#[Guarded(['*'])]
class InventoryItem extends Model
{
    protected static function booted(): void
    {
        static::deleting(fn (): never => throw new LogicException('Inventory Items with governed identity cannot be deleted.'));
        static::updating(function (self $item): void {
            foreach (['public_id', 'organisation_id'] as $attribute) {
                if ($item->isDirty($attribute)) {
                    throw new LogicException('Inventory Item ownership and public identity are immutable.');
                }
            }
        });
    }

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    /** @return HasMany<InventorySku, $this> */
    public function skus(): HasMany
    {
        return $this->hasMany(InventorySku::class);
    }
}
