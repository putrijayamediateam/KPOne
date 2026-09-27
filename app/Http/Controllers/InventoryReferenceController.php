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
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class InventoryReferenceController extends Controller
{
    public function storeItem(InventoryReferenceItemStoreRequest $request, InventoryReferenceAdministrationService $service): RedirectResponse
    {
        try {
            $service->createItem($request->user(), $request->validated());
        } catch (ValidationException $exception) {
            throw $this->stayOnInventory($exception);
        }
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Inventory Item created.')]);

        return to_route('inventory.index');
    }

    public function storeSku(InventoryReferenceSkuStoreRequest $request, InventoryReferenceAdministrationService $service): RedirectResponse
    {
        $data = $request->validated();
        $actor = $request->user();
        $item = InventoryItem::query()
            ->where('organisation_id', $actor->organisation_id)
            ->where('public_id', $data['inventory_item_public_id'])
            ->firstOrFail();
        try {
            $service->createSku($actor, $item, collect($data)->except('inventory_item_public_id')->all());
        } catch (ValidationException $exception) {
            throw $this->stayOnInventory($exception);
        }
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Inventory SKU created.')]);

        return to_route('inventory.index');
    }

    public function storeLocation(InventoryReferenceLocationStoreRequest $request, InventoryReferenceAdministrationService $service): RedirectResponse
    {
        $data = $request->validated();
        $actor = $request->user();
        $branch = filled($data['branch_id'] ?? null)
            ? Branch::query()->where('organisation_id', $actor->organisation_id)->where('id', (int) $data['branch_id'])->firstOrFail()
            : null;
        try {
            $service->createLocation($actor, $branch, null, ['code' => $data['code'], 'name' => $data['name'], 'type' => $data['type']]);
        } catch (ValidationException $exception) {
            throw $this->stayOnInventory($exception);
        }
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Inventory Location created.')]);

        return to_route('inventory.index');
    }

    public function storeBatch(InventoryReferenceBatchStoreRequest $request, InventoryReferenceAdministrationService $service): RedirectResponse
    {
        $data = $request->validated();
        $actor = $request->user();
        $sku = InventorySku::query()
            ->where('organisation_id', $actor->organisation_id)
            ->where('public_id', $data['inventory_sku_public_id'])
            ->firstOrFail();
        try {
            $service->createBatch($actor, $sku, [
                'batch_number' => $data['batch_number'],
                'expiry_date' => $data['expiry_date'],
                'received_at' => $data['received_at'] ?? null,
            ]);
        } catch (ValidationException $exception) {
            throw $this->stayOnInventory($exception);
        }
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Inventory Batch created.')]);

        return to_route('inventory.index');
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
        try {
            $service->createMapping($actor, $medicine, $sku);
        } catch (ValidationException $exception) {
            throw $this->stayOnInventory($exception);
        }
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Medicine linked to the Inventory SKU.')]);

        return to_route('inventory.index');
    }

    /**
     * NAV-01: these forms are only ever submitted from the Inventory Stock Setup
     * panel, so a failed create must land back there - never Laravel's default
     * `back()` resolution, which (for an Inertia SPA where almost all navigation
     * is client-side XHR, not a full page load) can land on a much older page
     * from earlier in the session rather than the page the request came from.
     * This is the same fix OH-06d already made for the hold/resume path.
     */
    private function stayOnInventory(ValidationException $exception): ValidationException
    {
        return $exception->redirectTo(route('inventory.index'));
    }
}
