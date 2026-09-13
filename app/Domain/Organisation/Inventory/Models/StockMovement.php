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

    public const TYPE_PURCHASE_RECEIPT = 'purchase_receipt';

    public const TYPE_TRANSFER_DISPATCH = 'transfer_dispatch';

    public const TYPE_TRANSFER_RECEIPT = 'transfer_receipt';

    public const TYPE_STOCKTAKE_GAIN = 'stocktake_gain';

    public const TYPE_STOCKTAKE_LOSS = 'stocktake_loss';

    public const TYPE_ADJUSTMENT_IN = 'adjustment_in';

    public const TYPE_ADJUSTMENT_OUT = 'adjustment_out';

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
