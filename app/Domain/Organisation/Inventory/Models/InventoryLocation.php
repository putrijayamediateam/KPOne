<?php

namespace App\Domain\Organisation\Inventory\Models;

use App\Domain\Organisation\Models\Branch;
use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

#[Guarded(['*'])]
class InventoryLocation extends Model
{
    public const TYPE_MEDICAL_STOCK = 'medical_stock';

    public const TYPE_BRANCH_STORE = 'branch_store';

    public const TYPE_DISPENSARY = 'dispensary';

    protected static function booted(): void
    {
        static::deleting(fn (): never => throw new LogicException('Inventory Locations are retained reference records.'));
        static::updating(function (self $location): void {
            foreach (['public_id', 'organisation_id', 'branch_id', 'parent_id'] as $attribute) {
                if ($location->isDirty($attribute)) {
                    throw new LogicException('Inventory Location ownership and hierarchy are immutable.');
                }
            }
        });
    }

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** @return BelongsTo<InventoryLocation, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }
}
