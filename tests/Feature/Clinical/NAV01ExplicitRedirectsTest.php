<?php

namespace Tests\Feature\Clinical;

use App\Domain\Clinical\Services\MedicineAdministrationService;
use App\Domain\Organisation\Inventory\Models\InventoryItem;
use App\Domain\Organisation\Inventory\Models\InventoryLocation;
use App\Domain\Organisation\Inventory\Services\InventoryMovementService;
use App\Domain\Organisation\Inventory\Services\InventoryReferenceAdministrationService;
use App\Domain\Organisation\Inventory\Services\SupplierAdministrationService;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * NAV-01: Laravel's `back()` resolves to `url()->previous()`, which for an
 * Inertia SPA - where almost all navigation is client-side XHR, never a full
 * page load - is very often not the page the request came from. A feature
 * test's session has no previous URL at all, so `back()` there resolves to
 * "/", not the real page; a test that only asserts `assertRedirect()` (any
 * 3xx) passes either way and cannot catch this. Every assertion below names
 * the explicit target, the way OH-06d's own regression test did.
 */
class NAV01ExplicitRedirectsTest extends ClinicalTestCase
{
    public function test_store_item_redirects_to_inventory_index_on_success_and_on_a_duplicate_code(): void
    {
        $supervisor = $this->actor('ca_supervisor');
        $this->selectBranch($supervisor);

        $this->post(route('inventory-references.items.store'), ['code' => 'NAV-ITEM', 'generic_name' => 'Synthetic item'])
            ->assertRedirect(route('inventory.index'));
        $this->assertDatabaseHas('inventory_items', ['code' => 'NAV-ITEM']);

        // A duplicate code fails inside the domain service (after the request's
        // own validation already passed), and must land back on the same page
        // with the error visible, not on whatever page url()->previous() picks.
        $response = $this->post(route('inventory-references.items.store'), ['code' => 'NAV-ITEM', 'generic_name' => 'Synthetic duplicate']);
        $response->assertRedirect(route('inventory.index'));
        $response->assertSessionHasErrors('code');
    }

    public function test_store_item_redirects_to_inventory_index_on_a_form_request_validation_failure(): void
    {
        $supervisor = $this->actor('ca_supervisor');
        $this->selectBranch($supervisor);

        // Missing generic_name fails InventoryReferenceItemStoreRequest's own
        // rules, before the controller method (or the domain service) runs at
        // all - a different failure stage from the test above.
        $response = $this->post(route('inventory-references.items.store'), ['code' => 'NAV-BLANK']);
        $response->assertRedirect(route('inventory.index'));
        $response->assertSessionHasErrors('generic_name');
        $this->assertDatabaseMissing('inventory_items', ['code' => 'NAV-BLANK']);
    }

    public function test_store_sku_redirects_to_inventory_index(): void
    {
        $supervisor = $this->actor('ca_supervisor');
        $this->selectBranch($supervisor);
        $item = app(InventoryReferenceAdministrationService::class)->createItem($supervisor, ['code' => 'NAV-SKU-ITEM', 'generic_name' => 'Synthetic']);

        $this->post(route('inventory-references.skus.store'), [
            'inventory_item_public_id' => $item->public_id, 'sku_code' => 'NAV-SKU', 'pack_size' => '1',
            'purchase_unit' => 'box', 'stock_unit' => 'tablet', 'dispensing_unit' => 'tablet', 'unit_conversion' => '1',
        ])->assertRedirect(route('inventory.index'));
    }

    public function test_store_location_redirects_to_inventory_index(): void
    {
        $supervisor = $this->actor('ca_supervisor');
        $this->selectBranch($supervisor);

        $this->post(route('inventory-references.locations.store'), [
            'branch_id' => $this->branch->id, 'code' => 'NAV-LOC', 'name' => 'Synthetic location', 'type' => 'branch_store',
        ])->assertRedirect(route('inventory.index'));
    }

    public function test_store_batch_redirects_to_inventory_index(): void
    {
        $supervisor = $this->actor('ca_supervisor');
        $this->selectBranch($supervisor);
        $service = app(InventoryReferenceAdministrationService::class);
        $item = $service->createItem($supervisor, ['code' => 'NAV-BATCH-ITEM', 'generic_name' => 'Synthetic']);
        $sku = $service->createSku($supervisor, $item, [
            'sku_code' => 'NAV-BATCH-SKU', 'pack_size' => '1', 'purchase_unit' => 'box',
            'stock_unit' => 'tablet', 'dispensing_unit' => 'tablet', 'unit_conversion' => '1',
        ]);

        $this->post(route('inventory-references.batches.store'), [
            'inventory_sku_public_id' => $sku->public_id, 'batch_number' => 'NAV-BATCH', 'expiry_date' => now()->addYear()->toDateString(),
        ])->assertRedirect(route('inventory.index'));
    }

    public function test_store_mapping_redirects_to_inventory_index(): void
    {
        $supervisor = $this->actor('ca_supervisor');
        $this->selectBranch($supervisor);
        $service = app(InventoryReferenceAdministrationService::class);
        $medicine = app(MedicineAdministrationService::class)->create($supervisor, [
            'code' => 'NAV-MAP-MED', 'display_name' => 'Synthetic', 'strength_text' => null, 'dosage_form' => null, 'order_unit' => 'tablet',
        ]);
        $item = $service->createItem($supervisor, ['code' => 'NAV-MAP-ITEM', 'generic_name' => 'Synthetic']);
        $sku = $service->createSku($supervisor, $item, [
            'sku_code' => 'NAV-MAP-SKU', 'pack_size' => '1', 'purchase_unit' => 'box',
            'stock_unit' => 'tablet', 'dispensing_unit' => 'tablet', 'unit_conversion' => '1',
        ]);

        $this->post(route('inventory-references.mappings.store'), [
            'medicine_public_id' => $medicine->public_id, 'inventory_sku_public_id' => $sku->public_id,
        ])->assertRedirect(route('inventory.index'));
    }

    public function test_opening_balance_redirects_to_inventory_index(): void
    {
        $supervisor = $this->actor('ca_supervisor');
        $this->selectBranch($supervisor);
        $fixture = $this->inventoryFixture($supervisor, 'NAV-OB');

        $this->post(route('inventory.opening-balances.store'), [
            'expected_branch_id' => $this->branch->id,
            'location_public_id' => $fixture['location']->public_id,
            'sku_public_id' => $fixture['sku']->public_id,
            'batch_public_id' => $fixture['batch']->public_id,
            'quantity' => '5.000',
        ])->assertRedirect(route('inventory.index'));
    }

    public function test_transfer_redirects_to_inventory_index(): void
    {
        $supervisor = $this->actor('ca_supervisor');
        $this->selectBranch($supervisor);
        $fixture = $this->inventoryFixture($supervisor, 'NAV-TR');
        app(InventoryMovementService::class)->openingBalance($supervisor, [
            'expected_branch_id' => $this->branch->id,
            'location_public_id' => $fixture['location']->public_id,
            'sku_public_id' => $fixture['sku']->public_id,
            'batch_public_id' => $fixture['batch']->public_id,
            'quantity' => '10.000',
        ]);
        $destination = new InventoryLocation;
        $destination->forceFill([
            'public_id' => (string) Str::uuid(), 'organisation_id' => $supervisor->organisation_id, 'branch_id' => $this->branch->id,
            'code' => 'NAV-TR-DEST', 'name' => 'Synthetic transfer destination', 'type' => InventoryLocation::TYPE_DISPENSARY, 'is_active' => true,
        ])->save();

        $this->post(route('inventory.transfers.store'), [
            'expected_branch_id' => $this->branch->id,
            'source_location_public_id' => $fixture['location']->public_id,
            'destination_location_public_id' => $destination->public_id,
            'sku_public_id' => $fixture['sku']->public_id,
            'batch_public_id' => $fixture['batch']->public_id,
            'quantity' => '4.000',
        ])->assertRedirect(route('inventory.index'));
    }

    public function test_a_representative_operations_action_redirects_to_inventory_index_on_success_and_on_failure(): void
    {
        $finance = $this->actor('finance_officer');
        $this->selectBranch($finance);

        $this->post(route('inventory.suppliers.store'), [
            'code' => 'NAV-SUP', 'name' => 'Synthetic NAV Supplier', 'is_active' => true,
        ])->assertRedirect(route('inventory.index'));
        $supplier = app(SupplierAdministrationService::class)->create($finance, ['code' => 'NAV-SUP-DUP', 'name' => 'Synthetic']);

        // supplierStatus validates inline with $request->validate(); a missing
        // field must fail the same way, to the same page.
        $response = $this->patch(route('inventory.suppliers.status', $supplier), []);
        $response->assertRedirect(route('inventory.index'));
        $response->assertSessionHasErrors('is_active');
    }

    /** @return array{item: InventoryItem, sku: mixed, location: InventoryLocation, batch: mixed} */
    private function inventoryFixture(User $actor, string $prefix): array
    {
        $service = app(InventoryReferenceAdministrationService::class);
        $item = $service->createItem($actor, ['code' => $prefix.'-ITEM', 'generic_name' => 'Synthetic '.$prefix]);
        $location = new InventoryLocation;
        $location->forceFill([
            'public_id' => (string) Str::uuid(), 'organisation_id' => $actor->organisation_id, 'branch_id' => $this->branch->id,
            'code' => $prefix.'-LOC', 'name' => 'Synthetic '.$prefix.' location', 'type' => InventoryLocation::TYPE_BRANCH_STORE, 'is_active' => true,
        ])->save();
        $sku = $service->createSku($actor, $item, [
            'sku_code' => $prefix.'-SKU', 'pack_size' => '1', 'purchase_unit' => 'box',
            'stock_unit' => 'tablet', 'dispensing_unit' => 'tablet', 'unit_conversion' => '1',
        ]);
        $batch = $service->createBatch($actor, $sku, ['batch_number' => $prefix.'-BATCH', 'expiry_date' => now()->addYear()->toDateString()]);

        return ['item' => $item, 'sku' => $sku, 'location' => $location, 'batch' => $batch];
    }
}
