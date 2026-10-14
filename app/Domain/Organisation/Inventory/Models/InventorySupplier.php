<?php

namespace App\Domain\Organisation\Inventory\Models;

use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * @property int $id
 * @property int $organisation_id
 * @property string $public_id
 * @property string $code
 * @property string $name
 * @property string|null $contact_name
 * @property string|null $business_phone
 * @property bool $is_active
 * @property int $lock_version
 */
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
        return ['is_active' => 'boolean', 'lock_version' => 'integer'];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }
}
