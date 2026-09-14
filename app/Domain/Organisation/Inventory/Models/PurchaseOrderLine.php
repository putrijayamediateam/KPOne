<?php

namespace App\Domain\Organisation\Inventory\Models;

use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Model;
use LogicException;

#[Guarded(['*'])]
class PurchaseOrderLine extends Model
{
    protected $table = 'inventory_purchase_order_lines';

    protected static function booted(): void
    {
        static::updating(function (self $line): void {
            if ($line->isDirty(['organisation_id', 'purchase_order_id', 'inventory_sku_id', 'public_id'])) {
                throw new LogicException('Purchase Order line identity is immutable.');
            }
        });
        static::deleting(fn (): never => throw new LogicException('Purchase Order lines are historical records.'));
    }

    protected function casts(): array
    {
        return ['ordered_quantity' => 'decimal:3', 'received_quantity' => 'decimal:3'];
    }
}
