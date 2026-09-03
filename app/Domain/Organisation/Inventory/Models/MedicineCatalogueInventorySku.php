<?php

namespace App\Domain\Organisation\Inventory\Models;

use App\Domain\Clinical\Models\MedicineCatalogueItem;
use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Guarded(['*'])]
class MedicineCatalogueInventorySku extends Model
{
    protected $table = 'medicine_catalogue_inventory_skus';

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
