<?php

namespace App\Domain\Organisation\Inventory\Models;

use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Model;
use LogicException;

#[Guarded(['*'])]
class InventoryStocktakeLine extends Model
{
    protected $table = 'inventory_stocktake_lines';

    protected static function booted(): void
    {
        static::updating(function (self $line): void {
            if ($line->isDirty(['organisation_id', 'stocktake_id', 'inventory_sku_id', 'inventory_batch_id', 'expected_quantity', 'expected_balance_lock_version', 'public_id'])) {
                throw new LogicException('Stocktake snapshot identity is immutable.');
            }
        });
        static::deleting(fn (): never => throw new LogicException('Stocktake lines are historical records.'));
    }

    protected function casts(): array
    {
        return ['expected_quantity' => 'decimal:3', 'physical_quantity' => 'decimal:3', 'variance_quantity' => 'decimal:3'];
    }
}
