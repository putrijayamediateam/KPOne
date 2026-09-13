<?php

namespace App\Domain\Organisation\Inventory\Models;

use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

#[Guarded(['*'])]
class GoodsReceipt extends Model
{
    protected $table = 'inventory_goods_receipts';

    protected static function booted(): void
    {
        static::updating(fn (): never => throw new LogicException('Goods Receipts are immutable.'));
        static::deleting(fn (): never => throw new LogicException('Goods Receipts are immutable.'));
    }

    protected function casts(): array
    {
        return ['received_at' => 'immutable_datetime'];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    /** @return HasMany<GoodsReceiptLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(GoodsReceiptLine::class, 'goods_receipt_id');
    }
}
