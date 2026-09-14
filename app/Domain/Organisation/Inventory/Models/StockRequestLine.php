<?php

namespace App\Domain\Organisation\Inventory\Models;

use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Model;
use LogicException;

#[Guarded(['*'])]
class StockRequestLine extends Model
{
    protected $table = 'inventory_stock_request_lines';

    protected static function booted(): void
    {
        static::updating(function (self $line): void {
            if ($line->isDirty(['organisation_id', 'stock_request_id', 'inventory_sku_id', 'public_id', 'requested_quantity'])) {
                throw new LogicException('Stock Request line identity is immutable.');
            }
        });
        static::deleting(fn (): never => throw new LogicException('Stock Request lines are historical records.'));
    }

    protected function casts(): array
    {
        return ['requested_quantity' => 'decimal:3', 'dispatched_quantity' => 'decimal:3', 'received_quantity' => 'decimal:3'];
    }
}
