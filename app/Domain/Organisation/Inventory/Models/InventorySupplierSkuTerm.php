<?php

namespace App\Domain\Organisation\Inventory\Models;

use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * STK-13: one supplier's current terms for one SKU (pack, pack price, lead time). History is in the revisions table.
 *
 * @property int $id
 * @property string $public_id
 * @property int $organisation_id
 * @property int $inventory_supplier_id
 * @property int $inventory_sku_id
 * @property string $pack_description
 * @property string $pack_quantity
 * @property int|null $pack_price_sen
 * @property int $lead_time_days
 * @property int|null $minimum_order_packs
 * @property bool $is_preferred
 * @property bool $is_active
 * @property int $lock_version
 */
#[Guarded(['*'])]
class InventorySupplierSkuTerm extends Model
{
    protected $table = 'inventory_supplier_sku_terms';

    protected function casts(): array
    {
        return ['is_preferred' => 'boolean', 'is_active' => 'boolean', 'pack_price_sen' => 'integer', 'lead_time_days' => 'integer', 'minimum_order_packs' => 'integer', 'lock_version' => 'integer'];
    }

    protected static function booted(): void
    {
        static::updating(function (self $term): void {
            if ($term->isDirty(['organisation_id', 'inventory_supplier_id', 'inventory_sku_id', 'public_id'])) {
                throw new LogicException('A price list row keeps its supplier and SKU.');
            }
        });
        static::deleting(fn (): never => throw new LogicException('Price list rows are deactivated, never deleted.'));
    }
}
