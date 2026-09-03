<?php

namespace App\Domain\Organisation\Inventory\Models;

use App\Domain\Clinical\Dispensary\Models\DispensaryItemBatchAllocation;
use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

#[Guarded(['*'])]
class StockMovement extends Model
{
    public const TYPE_OPENING = 'opening_balance';

    public const TYPE_TRANSFER = 'transfer';

    public const TYPE_DISPENSE = 'dispense';

    protected static function booted(): void
    {
        static::updating(fn (): never => throw new LogicException('Stock Movements are immutable.'));
        static::deleting(fn (): never => throw new LogicException('Stock Movements are immutable.'));
    }

    protected function casts(): array
    {
        return ['quantity' => 'decimal:3', 'occurred_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<DispensaryItemBatchAllocation, $this> */
    public function allocation(): BelongsTo
    {
        return $this->belongsTo(DispensaryItemBatchAllocation::class, 'dispensary_item_batch_allocation_id');
    }
}
