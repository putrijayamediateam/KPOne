<?php

namespace App\Domain\Organisation\Inventory\Models;

use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Model;
use LogicException;

#[Guarded(['*'])]
class InventorySupplier extends Model
{
    protected static function booted(): void
    {
        static::updating(function (self $supplier): void {
            if ($supplier->isDirty(['organisation_id', 'code', 'public_id'])) {
                throw new LogicException('Supplier identity is immutable.');
            }
        });
        static::deleting(fn (): never => throw new LogicException('Suppliers with historical references cannot be deleted.'));
    }

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }
}
