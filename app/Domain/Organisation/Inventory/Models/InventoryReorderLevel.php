<?php

namespace App\Domain\Organisation\Inventory\Models;

use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Model;
use LogicException;

#[Guarded(['*'])]
class InventoryReorderLevel extends Model
{
    protected static function booted(): void
    {
        static::updating(function (self $level): void {
            if ($level->isDirty(['organisation_id', 'inventory_location_id', 'inventory_sku_id'])) {
                throw new LogicException('Reorder level ownership and grain are immutable.');
            }
        });
        static::deleting(fn (): never => throw new LogicException('Reorder level records are retained.'));
    }

    protected function casts(): array
    {
        return ['reorder_level' => 'decimal:3'];
    }
}
