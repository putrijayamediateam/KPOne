<?php

namespace App\Domain\Organisation\Inventory\Models;

use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Model;
use LogicException;

#[Guarded(['*'])]
class InventoryStockBalance extends Model
{
    protected static function booted(): void
    {
        static::creating(fn (): never => throw new LogicException('Stock balances may only be created by InventoryMovementService.'));
        static::updating(fn (): never => throw new LogicException('Stock balances may only be updated by InventoryMovementService.'));
        static::deleting(fn (): never => throw new LogicException('Stock balances cannot be deleted.'));
    }

    protected function casts(): array
    {
        return ['quantity' => 'decimal:3', 'lock_version' => 'integer'];
    }
}
