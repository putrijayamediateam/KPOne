<?php

namespace App\Domain\Organisation\Inventory\Models;

use App\Domain\Clinical\Models\MedicineCatalogueItem;
use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

#[Guarded(['*'])]
class MedicineCatalogueInventorySku extends Model
{
    protected $table = 'medicine_catalogue_inventory_skus';

    protected static function booted(): void
    {
        static::deleting(fn (): never => throw new LogicException('Medicine Inventory mappings must be deactivated, not deleted.'));
        static::updating(function (self $mapping): void {
            foreach (['organisation_id', 'medicine_catalogue_item_id', 'inventory_sku_id'] as $attribute) {
                if ($mapping->isDirty($attribute)) {
                    throw new LogicException('Medicine Inventory mapping ownership and identities are immutable.');
                }
            }
        });
    }

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'approved_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<MedicineCatalogueItem, $this> */
    public function catalogueItem(): BelongsTo
    {
        return $this->belongsTo(MedicineCatalogueItem::class, 'medicine_catalogue_item_id');
    }

    /** @return BelongsTo<InventorySku, $this> */
    public function sku(): BelongsTo
    {
        return $this->belongsTo(InventorySku::class, 'inventory_sku_id');
    }
}
