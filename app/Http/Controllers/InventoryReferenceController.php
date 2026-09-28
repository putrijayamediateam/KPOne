<?php

namespace App\Http\Controllers;

use App\Domain\Clinical\Models\MedicineCatalogueItem;
use App\Domain\Organisation\Inventory\Models\InventoryBatch;
use App\Domain\Organisation\Inventory\Models\InventoryItem;
use App\Domain\Organisation\Inventory\Models\InventorySku;
use App\Domain\Organisation\Inventory\Services\InventoryReferenceAdministrationService;
use App\Domain\Organisation\Models\Branch;
use App\Http\Requests\InventoryReferenceBatchStoreRequest;
use App\Http\Requests\InventoryReferenceItemStoreRequest;
use App\Http\Requests\InventoryReferenceLocationStoreRequest;
use App\Http\Requests\InventoryReferenceMappingStoreRequest;
use App\Http\Requests\InventoryReferenceSkuStoreRequest;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

class InventoryReferenceController extends Controller
{
    public function storeItem(InventoryReferenceItemStoreRequest $request, InventoryReferenceAdministrationService $service): RedirectResponse
    {
        $service->createItem($request->user(), $request->validated());
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Inventory Item created.')]);

        return back();
    }

    public function storeSku(InventoryReferenceSkuStoreRequest $request, InventoryReferenceAdministrationService $service): RedirectResponse
    {
        $data = $request->validated();
        $actor = $request->user();
        $item = InventoryItem::query()
            ->where('organisation_id', $actor->organisation_id)
            ->where('public_id', $data['inventory_item_public_id'])
            ->firstOrFail();
        $service->createSku($actor, $item, collect($data)->except('inventory_item_public_id')->all());
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Inventory SKU created.')]);

        return back();
    }

    public function storeLocation(InventoryReferenceLocationStoreRequest $request, InventoryReferenceAdministrationService $service): RedirectResponse
    {
        $data = $request->validated();
        $actor = $request->user();
        $branch = filled($data['branch_id'] ?? null)
            ? Branch::query()->where('organisation_id', $actor->organisation_id)->where('id', (int) $data['branch_id'])->firstOrFail()
            : null;
        $service->createLocation($actor, $branch, null, ['code' => $data['code'], 'name' => $data['name'], 'type' => $data['type']]);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Inventory Location created.')]);

        return back();
    }

    public function storeBatch(InventoryReferenceBatchStoreRequest $request, InventoryReferenceAdministrationService $service): RedirectResponse
    {
        $data = $request->validated();
        $actor = $request->user();
        $sku = InventorySku::query()
            ->where('organisation_id', $actor->organisation_id)
            ->where('public_id', $data['inventory_sku_public_id'])
            ->firstOrFail();
        $service->createBatch($actor, $sku, [
            'batch_number' => $data['batch_number'],
            'expiry_date' => $data['expiry_date'],
            'received_at' => $data['received_at'] ?? null,
        ]);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Inventory Batch created.')]);

        return back();
    }

    public function skuBatches(Request $request, string $sku): JsonResponse
    {
        $inventorySku = InventorySku::query()
            ->where('organisation_id', $request->user()->organisation_id)
            ->where('public_id', $sku)
            ->firstOrFail();

        return response()->json([
            'data' => InventoryBatch::query()
                ->where('organisation_id', $inventorySku->organisation_id)
                ->where('inventory_sku_id', $inventorySku->id)
                ->orderBy('batch_number')
                ->orderBy('id')
                ->get()
                ->map(fn (InventoryBatch $batch): array => [
                    'publicId' => $batch->public_id,
                    'batchNumber' => $batch->batch_number,
                    'expiryDate' => CarbonImmutable::parse($batch->expiry_date)->toDateString(),
                ])
                ->values(),
        ]);
    }

    public function storeMapping(InventoryReferenceMappingStoreRequest $request, InventoryReferenceAdministrationService $service): RedirectResponse
    {
        $data = $request->validated();
        $actor = $request->user();
        $medicine = MedicineCatalogueItem::query()
            ->where('organisation_id', $actor->organisation_id)
            ->where('public_id', $data['medicine_public_id'])
            ->firstOrFail();
        $sku = InventorySku::query()
            ->where('organisation_id', $actor->organisation_id)
            ->where('public_id', $data['inventory_sku_public_id'])
            ->firstOrFail();
        $service->createMapping($actor, $medicine, $sku);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Medicine linked to the Inventory SKU.')]);

        return back();
    }
}
