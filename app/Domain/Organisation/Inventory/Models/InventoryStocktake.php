<?php

namespace App\Domain\Organisation\Inventory\Models;

use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

#[Guarded(['*'])]
class InventoryStocktake extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_COUNTING = 'counting';

    public const STATUS_REVIEW = 'review';

    public const STATUS_POSTED = 'posted';

    public const STATUS_CANCELLED = 'cancelled';

    protected $table = 'inventory_stocktakes';

    protected static function booted(): void
    {
        static::updating(function (self $stocktake): void {
            if ($stocktake->isDirty(['organisation_id', 'inventory_location_id', 'public_id', 'stocktake_number', 'created_by_user_id'])) {
                throw new LogicException('Stocktake identity is immutable.');
            }
        });
        static::deleting(fn (): never => throw new LogicException('Stocktakes are historical records.'));
    }

    protected function casts(): array
    {
        return ['counted_at' => 'immutable_datetime', 'posted_at' => 'immutable_datetime', 'cancelled_at' => 'immutable_datetime'];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    /** @return HasMany<InventoryStocktakeLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(InventoryStocktakeLine::class, 'stocktake_id');
    }
}
