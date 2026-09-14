<?php

namespace App\Domain\Organisation\Inventory\Models;

use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Model;
use LogicException;

#[Guarded(['*'])]
class GoodsReceiptLine extends Model
{
    protected $table = 'inventory_goods_receipt_lines';

    protected static function booted(): void
    {
        static::updating(fn (): never => throw new LogicException('Goods Receipt lines are immutable.'));
        static::deleting(fn (): never => throw new LogicException('Goods Receipt lines are immutable.'));
    }

    protected function casts(): array
    {
        return ['quantity' => 'decimal:3'];
    }
}
