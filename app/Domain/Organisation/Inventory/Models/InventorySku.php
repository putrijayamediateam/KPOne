<?php

namespace App\Domain\Organisation\Inventory\Models;

use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

#[Guarded(['*'])]
class InventorySku extends Model
{
    protected static function booted(): void
    {
        static::deleting(fn (): never => throw new LogicException('Inventory SKUs cannot be deleted after governance.'));
        static::updating(function (self $sku): void {
            foreach (['public_id', 'organisation_id', 'inventory_item_id'] as $attribute) {
                if ($sku->isDirty($attribute)) {
                    throw new LogicException('Inventory SKU ownership and Item identity are immutable.');
                }
            }
        });
    }

    protected function casts(): array
    {
        return ['pack_size' => 'decimal:3', 'unit_conversion' => 'decimal:3', 'minimum_temperature' => 'decimal:2', 'maximum_temperature' => 'decimal:2', 'cold_chain_required' => 'boolean', 'do_not_freeze' => 'boolean', 'protect_from_light' => 'boolean', 'batch_tracking_required' => 'boolean', 'expiry_tracking_required' => 'boolean', 'is_active' => 'boolean'];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    /** @return BelongsTo<InventoryItem, $this> */
    public function item(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class, 'inventory_item_id');
    }
}
