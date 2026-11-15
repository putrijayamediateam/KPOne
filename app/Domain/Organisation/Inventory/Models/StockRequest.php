<?php

namespace App\Domain\Organisation\Inventory\Models;

use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

#[Guarded(['*'])]
class StockRequest extends Model
{
    public const STATUS_REQUESTED = 'requested';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_DISPATCHED = 'dispatched';

    public const STATUS_RECEIVED = 'received';

    protected $table = 'inventory_stock_requests';

    protected static function booted(): void
    {
        static::updating(function (self $request): void {
            if ($request->isDirty(['organisation_id', 'requesting_branch_id', 'source_location_id', 'destination_location_id', 'public_id', 'request_number', 'requested_by_user_id', 'requested_at'])) {
                throw new LogicException('Stock Request identity is immutable.');
            }
        });
        static::deleting(fn (): never => throw new LogicException('Stock Requests are historical records.'));
    }

    protected function casts(): array
    {
        return ['requested_at' => 'immutable_datetime', 'decided_at' => 'immutable_datetime', 'dispatched_at' => 'immutable_datetime', 'received_at' => 'immutable_datetime'];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    /** @return HasMany<StockRequestLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(StockRequestLine::class, 'stock_request_id');
    }
}
