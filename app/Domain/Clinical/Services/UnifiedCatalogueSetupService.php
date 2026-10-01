<?php

namespace App\Domain\Clinical\Services;

use App\Domain\Clinical\Models\ClinicalServiceCatalogueItem;
use App\Domain\Clinical\Models\MedicineCatalogueItem;
use App\Domain\Organisation\Inventory\Models\InventoryBatch;
use App\Domain\Organisation\Inventory\Models\InventoryItem;
use App\Domain\Organisation\Inventory\Models\InventorySku;
use App\Domain\Organisation\Inventory\Models\MedicineCatalogueInventorySku;
use App\Domain\Organisation\Inventory\Services\InventoryMovementService;
use App\Domain\Organisation\Inventory\Services\InventoryReferenceAdministrationService;
use App\Domain\Visit\Billing\Models\ChargeDefinition;
use App\Domain\Visit\Billing\Models\PriceBook;
use App\Domain\Visit\Billing\Models\PriceEntry;
use App\Domain\Visit\Billing\Services\ExactMoney;
use App\Domain\Visit\Billing\Services\PricePublicationService;
use App\Domain\Visit\Billing\Services\PricingReferenceAdministrationService;
use App\Domain\Visit\Models\Panel;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class UnifiedCatalogueSetupService
{
    public function __construct(
        private readonly MedicineAdministrationService $medicines,
        private readonly ClinicalServiceCatalogueAdministrationService $services,
        private readonly InventoryReferenceAdministrationService $inventory,
        private readonly InventoryMovementService $movements,
        private readonly PricingReferenceAdministrationService $pricing,
        private readonly PricePublicationService $publication,
    ) {}

    /** @param array<string, mixed> $attributes */
    public function createMedicine(User $actor, array $attributes): MedicineCatalogueItem
    {
        return DB::transaction(function () use ($actor, $attributes): MedicineCatalogueItem {
            $medicine = $this->medicines->create($actor, Arr::only($attributes, [
                'code', 'display_name', 'strength_text', 'dosage_form', 'order_unit',
                'generic_name', 'category', 'group_name', 'default_dosage_amount',
                'default_dosage_unit', 'default_instruction', 'default_precaution',
                'default_frequency', 'default_duration', 'default_indication',
            ]));
            $sku = $this->ensureMedicineSku($actor, $medicine, $attributes);
            $this->publishPrices($actor, $medicine, $attributes['prices'] ?? [], $attributes['expected_branch_id'] ?? null);
            $this->recordOpeningStock($actor, $sku, $attributes['opening_stock'] ?? [], $attributes['batch'] ?? [], $attributes['supplier_public_id'] ?? null);

            return $medicine->refresh();
        }, 3);
    }

    /** @param array<string, mixed> $attributes */
    public function createClinicalService(User $actor, array $attributes): ClinicalServiceCatalogueItem
    {
        return DB::transaction(function () use ($actor, $attributes): ClinicalServiceCatalogueItem {
            $service = $this->services->create($actor, Arr::only($attributes, [
                'code', 'display_name', 'order_unit', 'category',
            ]));
            $this->publishPrices($actor, $service, $attributes['prices'] ?? [], $attributes['expected_branch_id'] ?? null);

            return $service->refresh();
        }, 3);
    }

    /** @param array<string, mixed> $attributes */
    private function ensureMedicineSku(User $actor, MedicineCatalogueItem $medicine, array $attributes): InventorySku
    {
        $selected = $attributes['inventory_sku_public_id'] ?? null;
        if (is_string($selected) && $selected !== '') {
            $sku = InventorySku::query()->where('organisation_id', $actor->organisation_id)
                ->where('public_id', $selected)->where('is_active', true)->firstOrFail();
        } else {
            $skuInput = is_array($attributes['sku'] ?? null) ? $attributes['sku'] : [];
            $skuCode = strtoupper(trim((string) ($skuInput['sku_code'] ?? $medicine->code)));
            $sku = InventorySku::query()->where('organisation_id', $actor->organisation_id)
                ->whereRaw('LOWER(sku_code) = ?', [mb_strtolower($skuCode)])->first();

            $item = InventoryItem::query()->where('organisation_id', $actor->organisation_id)
                ->whereRaw('LOWER(code) = ?', [mb_strtolower($medicine->code)])->first();
            if ($item === null) {
                $item = $this->inventory->createItem($actor, [
                    'code' => $medicine->code,
                    'generic_name' => (string) ($medicine->generic_name ?: $medicine->display_name),
                    'brand_name' => $medicine->display_name,
                    'strength' => $medicine->strength_text,
                    'dosage_form' => $medicine->dosage_form,
                    'route' => $attributes['route'] ?? null,
                    'manufacturer' => $attributes['manufacturer'] ?? null,
                    'mal_number' => $attributes['mal_number'] ?? null,
                ]);
            }

            if ($sku !== null && $sku->inventory_item_id !== $item->id) {
                throw ValidationException::withMessages([
                    'sku.sku_code' => 'That SKU already belongs to a different Inventory Item. Select its existing SKU or use a unique SKU code.',
                ]);
            }
            if ($sku === null) {
                $sku = $this->inventory->createSku($actor, $item, [
                    ...$skuInput,
                    'sku_code' => $skuCode,
                    'pack_size' => $skuInput['pack_size'] ?? '1',
                    'purchase_unit' => $skuInput['purchase_unit'] ?? $medicine->order_unit,
                    'stock_unit' => $skuInput['stock_unit'] ?? $medicine->order_unit,
                    'dispensing_unit' => $skuInput['dispensing_unit'] ?? $medicine->order_unit,
                    'unit_conversion' => $skuInput['unit_conversion'] ?? '1',
                ]);
            }
        }

        $mapping = MedicineCatalogueInventorySku::query()
            ->where('organisation_id', $actor->organisation_id)
            ->where('medicine_catalogue_item_id', $medicine->id)
            ->where('inventory_sku_id', $sku->id)
            ->first();
        if ($mapping === null) {
            $this->inventory->createMapping($actor, $medicine, $sku);
        } elseif (! $mapping->is_active) {
            $this->inventory->activateMapping($actor, $mapping);
        }

        return $sku;
    }

    /**
     * @param array{
     *     self_pay_sen?: mixed,
     *     panel_default_sen?: mixed,
     *     panel_overrides?: list<array{panel_id?: mixed, amount_sen?: mixed}>
     * } $prices
     */
    private function publishPrices(User $actor, MedicineCatalogueItem|ClinicalServiceCatalogueItem $source, array $prices, mixed $expectedBranchId): void
    {
        $requestedPrices = [];
        foreach ([
            ['key' => 'self_pay_sen', 'tier' => 'self_pay', 'panel_id' => null, 'name' => 'Self-pay'],
            ['key' => 'panel_default_sen', 'tier' => 'panel', 'panel_id' => null, 'name' => 'Panel'],
        ] as $default) {
            if (array_key_exists($default['key'], $prices) && $prices[$default['key']] !== null && $prices[$default['key']] !== '') {
                $requestedPrices[] = [...$default, 'amount_sen' => ExactMoney::sen($prices[$default['key']], $default['key'])];
            }
        }
        foreach ($prices['panel_overrides'] ?? [] as $override) {
            if (! filled($override['panel_id'] ?? null)
                || ! array_key_exists('amount_sen', $override) || $override['amount_sen'] === '') {
                continue;
            }
            $panel = Panel::query()->where('organisation_id', $actor->organisation_id)
                ->whereKey((int) $override['panel_id'])->where('is_active', true)->firstOrFail();
            $requestedPrices[] = [
                'key' => 'panel_override',
                'tier' => 'panel',
                'panel_id' => $panel->id,
                'name' => $panel->name,
                'amount_sen' => ExactMoney::sen($override['amount_sen'], 'panel_overrides.amount_sen'),
            ];
        }

        if ($requestedPrices === []) {
            return;
        }
        $branchId = filter_var($expectedBranchId, FILTER_VALIDATE_INT);
        if ($branchId === false || $branchId < 1) {
            throw ValidationException::withMessages(['expected_branch_id' => 'Select the active branch before publishing prices.']);
        }

        $charge = $source instanceof MedicineCatalogueItem
            ? ChargeDefinition::query()->where('organisation_id', $actor->organisation_id)->where('medicine_catalogue_item_id', $source->id)->first()
            : ChargeDefinition::query()->where('organisation_id', $actor->organisation_id)->where('clinical_service_catalogue_item_id', $source->id)->first();
        if ($charge === null) {
            $charge = $source instanceof MedicineCatalogueItem
                ? $this->pricing->createMedicineCharge($actor, $source, ['code' => $source->code, 'display_name' => $source->display_name])
                : $this->pricing->createServiceCharge($actor, $source, ['code' => $source->code, 'display_name' => $source->display_name]);
        } elseif (! $charge->is_active) {
            $charge = $this->pricing->activateCharge($actor, $charge);
        }

        foreach ($requestedPrices as $entry) {
            $scopeKey = $entry['tier'] === 'self_pay'
                ? 'organisation'
                : ($entry['panel_id'] === null ? 'panel:default' : 'panel:'.$entry['panel_id']);
            $book = PriceBook::query()->where('organisation_id', $actor->organisation_id)
                ->where('scope_key', $scopeKey)->first();
            if ($book === null) {
                $book = $this->pricing->createPriceBook($actor, null, [
                    'name' => $entry['name'],
                    'currency' => 'MYR',
                    'price_tier' => $entry['tier'],
                    'panel_id' => $entry['panel_id'],
                ]);
            } elseif (! $book->is_active) {
                $book = $this->pricing->activatePriceBook($actor, $book);
            }

            $latestVersion = (int) PriceEntry::query()->where('price_book_id', $book->id)
                ->where('charge_definition_id', $charge->id)->max('version');
            $this->publication->publish($actor, $book, $charge, $entry['amount_sen'], $latestVersion, (int) $branchId);
        }
    }

    /**
     * @param  list<array<string, mixed>>  $locations
     * @param  array<string, mixed>  $batchData
     */
    private function recordOpeningStock(User $actor, InventorySku $sku, array $locations, array $batchData, mixed $supplierPublicId): void
    {
        $receivingLocations = array_values(array_filter($locations, fn (array $location): bool => (float) ($location['quantity'] ?? 0) > 0));
        if ($receivingLocations === []) {
            return;
        }
        $batchNumber = trim((string) ($batchData['batch_number'] ?? ''));
        $expiryDate = trim((string) ($batchData['expiry_date'] ?? ''));
        if ($batchNumber === '' || $expiryDate === '') {
            throw ValidationException::withMessages(['batch' => 'Batch number and expiry date are required when initial stock is entered.']);
        }
        $batch = InventoryBatch::query()->where('organisation_id', $actor->organisation_id)
            ->where('inventory_sku_id', $sku->id)->where('batch_number', $batchNumber)
            ->whereDate('expiry_date', $expiryDate)->first();
        if ($batch === null) {
            $batch = $this->inventory->createBatch($actor, $sku, [
                'batch_number' => $batchNumber,
                'expiry_date' => $expiryDate,
                'received_at' => now()->toDateString(),
                'status' => InventoryBatch::STATUS_AVAILABLE,
            ]);
        }

        foreach ($receivingLocations as $location) {
            $unitCost = $location['unit_cost_sen'] ?? null;
            $this->movements->openingBalance($actor, [
                'expected_branch_id' => $location['branch_id'],
                'location_public_id' => $location['location_public_id'],
                'sku_public_id' => $sku->public_id,
                'batch_public_id' => $batch->public_id,
                'quantity' => (string) $location['quantity'],
                'unit_cost_sen' => $unitCost === null || $unitCost === '' ? null : ExactMoney::sen($unitCost, 'opening_stock.unit_cost_sen'),
                'supplier_public_id' => $supplierPublicId,
            ]);
        }
    }
}
