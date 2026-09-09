<?php

namespace App\Domain\Organisation\Inventory\Models;

use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

#[Guarded(['*'])]
class InventoryBatch extends Model
{
    public const STATUS_AVAILABLE = 'available';

    public const STATUS_QUARANTINED = 'quarantined';

    public const STATUS_DAMAGED = 'damaged';

    protected static function booted(): void
    {
        static::deleting(fn (): never => throw new LogicException('Inventory Batches are retained ledger identities.'));
        static::updating(function (self $batch): void {
            foreach (['public_id', 'organisation_id', 'inventory_sku_id'] as $attribute) {
                if ($batch->isDirty($attribute)) {
                    throw new LogicException('Inventory Batch ownership and SKU identity are immutable.');
                }
            }
        });
    }

    protected function casts(): array
    {
        return ['expiry_date' => 'immutable_date', 'received_at' => 'immutable_date'];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    /** @return BelongsTo<InventorySku, $this> */
    public function sku(): BelongsTo
    {
        return $this->belongsTo(InventorySku::class, 'inventory_sku_id');
    }
}
