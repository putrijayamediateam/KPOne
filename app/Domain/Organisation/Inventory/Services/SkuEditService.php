<?php

namespace App\Domain\Organisation\Inventory\Services;

use App\Domain\Organisation\Inventory\Models\InventoryItem;
use App\Domain\Organisation\Inventory\Models\InventorySku;
use App\Domain\Organisation\Models\Organisation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * STK-14: one save from the SKU edit screen. Item and SKU changes and their active flags are applied together, in one
 * transaction, through the existing reference service (permission, organisation lock, row locks, evidence rules,
 * barcode uniqueness and audit), each checked against the version the screen showed.
 */
final class SkuEditService
{
    private const ITEM_FIELDS = ['generic_name', 'brand_name'];

    private const SKU_FIELDS = ['sku_code', 'barcode', 'pack_size', 'purchase_unit', 'stock_unit', 'dispensing_unit', 'unit_conversion',
        'storage_type', 'minimum_temperature', 'maximum_temperature', 'cold_chain_required', 'do_not_freeze', 'protect_from_light',
        'batch_tracking_required', 'expiry_tracking_required'];

    public function __construct(private InventoryReferenceAdministrationService $references) {}

    /** @param array<string, mixed> $input */
    public function save(User $actor, string $skuPublicId, array $input): InventorySku
    {
        $data = Validator::make($input, [
            'sku_lock_version' => ['required', 'integer', 'min:1'],
            'item_lock_version' => ['required', 'integer', 'min:1'],
            'sku_active' => ['required', 'boolean'],
            'item_active' => ['required', 'boolean'],
        ])->validate();
        $sku = InventorySku::query()->where('organisation_id', $actor->organisation_id)->where('public_id', $skuPublicId)->firstOrFail();
        $item = InventoryItem::query()->where('organisation_id', $actor->organisation_id)->whereKey($sku->inventory_item_id)->firstOrFail();
        $itemInput = array_intersect_key($input, array_flip(self::ITEM_FIELDS));
        $skuInput = array_intersect_key($input, array_flip(self::SKU_FIELDS));

        return DB::transaction(function () use ($actor, $sku, $item, $data, $itemInput, $skuInput): InventorySku {
            $itemVersion = (int) $data['item_lock_version'];
            $skuVersion = (int) $data['sku_lock_version'];
            // Decide once, under lock, whether the screen was current. A stale screen keeps its old versions for every
            // step, so any step that would change something is refused; a pure retry of saved values still passes.
            // Same lock order as every reference writer: organisation, then the rows.
            Organisation::query()->whereKey($actor->organisation_id)->where('is_active', true)->lockForUpdate()->firstOrFail();
            $current = (int) InventoryItem::query()->whereKey($item->id)->lockForUpdate()->value('lock_version') === $itemVersion
                && (int) InventorySku::query()->whereKey($sku->id)->lockForUpdate()->value('lock_version') === $skuVersion;
            // An item is activated before its SKU can be; a SKU is deactivated before its item can be.
            if ((bool) $data['item_active'] && ! $item->is_active) {
                $item = $this->references->activateItem($actor, $item, $itemVersion);
                $itemVersion = ($current ? (int) $item->lock_version : $itemVersion);
            }
            $item = $this->references->updateItem($actor, $item, [...$itemInput, 'expected_lock_version' => $itemVersion]);
            $itemVersion = ($current ? (int) $item->lock_version : $itemVersion);
            if (! (bool) $data['sku_active'] && $sku->is_active) {
                $sku = $this->references->deactivateSku($actor, $sku, $skuVersion);
                $skuVersion = ($current ? (int) $sku->lock_version : $skuVersion);
            }
            $sku = $this->references->updateSku($actor, $sku, [...$skuInput, 'expected_lock_version' => $skuVersion]);
            $skuVersion = ($current ? (int) $sku->lock_version : $skuVersion);
            if ((bool) $data['sku_active'] && ! $sku->is_active) {
                $sku = $this->references->activateSku($actor, $sku, $skuVersion);
            }
            if (! (bool) $data['item_active'] && $item->is_active) {
                $this->references->deactivateItem($actor, $item, $itemVersion);
            }

            return $sku;
        }, 3);
    }
}
