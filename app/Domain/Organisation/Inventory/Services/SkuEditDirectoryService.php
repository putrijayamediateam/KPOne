<?php

namespace App\Domain\Organisation\Inventory\Services;

use App\Domain\Access\BranchAccessService;
use App\Domain\Organisation\Inventory\Models\InventorySku;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/** STK-14: read side of the SKU edit screen. Writes go through InventoryReferenceAdministrationService. */
final class SkuEditDirectoryService
{
    public function __construct(private InventoryReferenceAdministrationService $references) {}

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function page(User $actor, array $input): array
    {
        $actor = $actor->fresh()?->load(['roles.permissions', 'permissions']);
        abort_unless($actor && $actor->is_active && $actor->can('inventory.view.branch'), 403);
        $branches = app(BranchAccessService::class);
        $branch = $branches->activeBranch($actor);
        abort_unless($branch && $branches->hasEffectiveAssignment($actor, $branch), 403);
        $data = Validator::make($input, [
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'in:active,inactive,all'],
            'page' => ['sometimes', 'integer', 'min:1', 'max:1000000'],
        ])->validate();
        $status = $data['status'] ?? 'active';
        $needle = filled($data['q'] ?? null) ? '%'.addcslashes((string) $data['q'], '%_\\').'%' : null;
        $rows = DB::table('inventory_skus as s')
            ->join('inventory_items as i', fn ($join) => $join->on('i.id', '=', 's.inventory_item_id')->on('i.organisation_id', '=', 's.organisation_id'))
            ->where('s.organisation_id', $actor->organisation_id)
            ->when($status === 'active', fn ($query) => $query->where('s.is_active', true))
            ->when($status === 'inactive', fn ($query) => $query->where('s.is_active', false))
            ->when($needle, fn ($query) => $query->where(fn ($match) => $match->where('s.sku_code', 'ilike', $needle)
                ->orWhere('i.generic_name', 'ilike', $needle)->orWhere('i.brand_name', 'ilike', $needle)->orWhere('s.barcode', 'ilike', $needle)))
            ->orderBy('s.sku_code')->orderBy('s.id')
            ->paginate(25, ['s.id', 's.public_id', 's.sku_code', 's.barcode', 's.pack_size', 's.purchase_unit', 's.stock_unit', 's.dispensing_unit', 's.unit_conversion',
                's.storage_type', 's.minimum_temperature', 's.maximum_temperature', 's.cold_chain_required', 's.do_not_freeze', 's.protect_from_light',
                's.batch_tracking_required', 's.expiry_tracking_required', 's.is_active', 's.lock_version',
                'i.public_id as item_public_id', 'i.code as item_code', 'i.generic_name', 'i.brand_name', 'i.is_active as item_active', 'i.lock_version as item_lock_version'],
                'page', (int) ($data['page'] ?? 1));
        /** @var list<\stdClass> $items */
        $items = $rows->items();
        $locked = [];
        foreach ($items as $row) {
            $locked[(int) $row->id] = $this->references->skuUnitsLocked(InventorySku::query()->findOrFail((int) $row->id));
        }
        $ids = array_map(fn (\stdClass $row): int => (int) $row->id, $items);
        // Stock on hand anywhere in the organisation: deactivating such a SKU asks for confirmation on screen.
        $onHand = DB::table('inventory_stock_balances')->where('organisation_id', $actor->organisation_id)->whereIn('inventory_sku_id', $ids)
            ->where('quantity', '>', 0)->distinct()->pluck('inventory_sku_id')->map(fn ($id) => (int) $id)->all();
        // A barcode another active SKU also has (legacy data from before STK-9's one-barcode rule).
        $codes = array_values(array_filter(array_map(fn (\stdClass $row): ?string => $row->barcode === null ? null : (string) $row->barcode, $items)));
        $shared = $codes === [] ? [] : DB::table('inventory_skus')->where('organisation_id', $actor->organisation_id)->whereIn('barcode', $codes)
            ->groupBy('barcode')->havingRaw('count(*) > 1')->pluck('barcode')->map(fn ($code) => (string) $code)->all();

        return [
            'branchName' => $branch->name,
            'canManage' => $actor->can(InventoryReferenceAdministrationService::PERMISSION),
            'filters' => ['q' => $data['q'] ?? '', 'status' => $status],
            'rows' => array_map(fn (\stdClass $row): array => [
                'publicId' => $row->public_id, 'skuCode' => $row->sku_code, 'barcode' => $row->barcode,
                'packSize' => (string) $row->pack_size, 'purchaseUnit' => $row->purchase_unit, 'stockUnit' => $row->stock_unit,
                'dispensingUnit' => $row->dispensing_unit, 'unitConversion' => (string) $row->unit_conversion,
                'storageType' => $row->storage_type,
                'minimumTemperature' => $row->minimum_temperature === null ? null : (string) $row->minimum_temperature,
                'maximumTemperature' => $row->maximum_temperature === null ? null : (string) $row->maximum_temperature,
                'coldChainRequired' => (bool) $row->cold_chain_required, 'doNotFreeze' => (bool) $row->do_not_freeze, 'protectFromLight' => (bool) $row->protect_from_light,
                'batchTrackingRequired' => (bool) $row->batch_tracking_required, 'expiryTrackingRequired' => (bool) $row->expiry_tracking_required,
                'active' => (bool) $row->is_active, 'lockVersion' => (int) $row->lock_version, 'unitsLocked' => $locked[(int) $row->id],
                'onHand' => in_array((int) $row->id, $onHand, true), 'barcodeShared' => $row->barcode !== null && in_array((string) $row->barcode, $shared, true),
                'item' => ['publicId' => $row->item_public_id, 'code' => $row->item_code, 'genericName' => $row->generic_name, 'brandName' => $row->brand_name,
                    'active' => (bool) $row->item_active, 'lockVersion' => (int) $row->item_lock_version],
            ], $items),
            'page' => $rows->currentPage(), 'lastPage' => $rows->lastPage(), 'total' => $rows->total(),
        ];
    }
}
