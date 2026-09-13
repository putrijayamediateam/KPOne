<?php

namespace App\Domain\Organisation\Inventory\Models;

use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Model;
use LogicException;

#[Guarded(['*'])]
class InventoryAdjustment extends Model
{
    protected static function booted(): void
    {
        static::updating(fn (): never => throw new LogicException('Posted adjustments are immutable.'));
        static::deleting(fn (): never => throw new LogicException('Posted adjustments are immutable.'));
    }

    protected function casts(): array
    {
        return ['quantity' => 'decimal:3', 'posted_at' => 'immutable_datetime'];
    }
}
