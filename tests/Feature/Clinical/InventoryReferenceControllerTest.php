<?php

namespace Tests\Feature\Clinical;

use App\Domain\Clinical\Services\MedicineAdministrationService;
use App\Domain\Organisation\Inventory\Models\InventoryItem;
use App\Domain\Organisation\Inventory\Models\InventoryLocation;
use App\Domain\Organisation\Inventory\Models\InventorySku;
use App\Domain\Organisation\Inventory\Services\InventoryReferenceAdministrationService;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Organisation\Models\Organisation;
use App\Models\User;

class InventoryReferenceControllerTest extends ClinicalTestCase
{
    public function test_every_route_requires_the_organisation_permission(): void
    {
        foreach (['resident_doctor', 'ca', 'panel_officer', 'finance_officer', 'technical_admin'] as $role) {
            $actor = $this->actor($role);
            $this->selectBranch($actor);
            $this->actingAs($actor);

            $this->post(route('inventory-references.items.store'), ['code' => 'DENY-'.$role, 'generic_name' => 'Denied item'])->assertForbidden();
            $this->post(route('inventory-references.locations.store'), ['code' => 'DENY-LOC-'.$role, 'name' => 'Denied location', 'type' => 'branch_store'])->assertForbidden();
        }

        $this->assertDatabaseMissing('inventory_items', ['code' => 'DENY-resident_doctor']);
        $this->assertDatabaseMissing('inventory_locations', ['code' => 'DENY-LOC-resident_doctor']);
    }

    public function test_happy_path_creates_item_sku_location_batch_and_links_a_medicine(): void
    {
        $supervisor = $this->actor('ca_supervisor');
        $this->selectBranch($supervisor);
        $this->actingAs($supervisor);
        $medicine = app(MedicineAdministrationService::class)->create($supervisor, [
            'code' => 'HTTP-STOCK-MED', 'display_name' => 'Synthetic stocked medicine',
            'strength_text' => null, 'dosage_form' => null, 'order_unit' => 'tablet',
        ]);

        $this->post(route('inventory-references.items.store'), [
            'code' => 'HTTP-ITEM', 'generic_name' => 'Synthetic HTTP item',
        ])->assertRedirect();
        $item = InventoryItem::query()->where('code', 'HTTP-ITEM')->sole();

        $this->post(route('inventory-references.locations.store'), [
            'branch_id' => $this->branch->id, 'code' => 'HTTP-LOC', 'name' => 'Synthetic HTTP location', 'type' => 'branch_store',
        ])->assertRedirect();
        $location = InventoryLocation::query()->where('code', 'HTTP-LOC')->sole();
        $this->assertSame($this->branch->id, $location->branch_id);

        $this->post(route('inventory-references.skus.store'), [
            'inventory_item_public_id' => $item->public_id,
            'sku_code' => 'HTTP-SKU', 'pack_size' => '1', 'purchase_unit' => 'box',
            'stock_unit' => 'tablet', 'dispensing_unit' => 'tablet', 'unit_conversion' => '1',
        ])->assertRedirect();
        $sku = InventorySku::query()->where('sku_code', 'HTTP-SKU')->sole();
        $this->assertSame($item->id, $sku->inventory_item_id);

        $this->post(route('inventory-references.batches.store'), [
            'inventory_sku_public_id' => $sku->public_id,
            'batch_number' => 'HTTP-BATCH', 'expiry_date' => now()->addYear()->toDateString(),
        ])->assertRedirect();
        $this->assertDatabaseHas('inventory_batches', ['inventory_sku_id' => $sku->id, 'batch_number' => 'HTTP-BATCH']);

        $this->post(route('inventory-references.mappings.store'), [
            'medicine_public_id' => $medicine->public_id, 'inventory_sku_public_id' => $sku->public_id,
        ])->assertRedirect();
        $this->assertDatabaseHas('medicine_catalogue_inventory_skus', [
            'medicine_catalogue_item_id' => $medicine->id, 'inventory_sku_id' => $sku->id, 'is_active' => true,
        ]);
    }

    public function test_a_foreign_organisation_sku_is_not_found_when_creating_a_batch(): void
    {
        $supervisor = $this->actor('ca_supervisor');
        $this->selectBranch($supervisor);
        $this->actingAs($supervisor);

        $foreignSupervisor = $this->foreignOrganisationSupervisor();
        $foreignItem = app(InventoryReferenceAdministrationService::class)->createItem($foreignSupervisor, [
            'code' => 'FOREIGN-ITEM', 'generic_name' => 'Foreign item',
        ]);
        $foreignSku = app(InventoryReferenceAdministrationService::class)->createSku($foreignSupervisor, $foreignItem, [
            'sku_code' => 'FOREIGN-SKU', 'pack_size' => '1', 'purchase_unit' => 'box',
            'stock_unit' => 'tablet', 'dispensing_unit' => 'tablet', 'unit_conversion' => '1',
        ]);

        $this->post(route('inventory-references.batches.store'), [
            'inventory_sku_public_id' => $foreignSku->public_id,
            'batch_number' => 'CROSS-TENANT', 'expiry_date' => now()->addYear()->toDateString(),
        ])->assertNotFound();
    }

    public function test_sku_batches_endpoint_requires_the_organisation_permission(): void
    {
        $supervisor = $this->actor('ca_supervisor');
        $sku = $this->skuWithBatches($supervisor, 'PERM', ['B-1']);

        foreach (['resident_doctor', 'ca', 'panel_officer', 'finance_officer', 'technical_admin'] as $role) {
            $actor = $this->actor($role);
            $this->selectBranch($actor);
            $this->actingAs($actor);

            $this->getJson(route('inventory-references.skus.batches', $sku->public_id))->assertForbidden();
        }
    }

    public function test_sku_batches_endpoint_returns_only_that_skus_batches(): void
    {
        $supervisor = $this->actor('ca_supervisor');
        $sku = $this->skuWithBatches($supervisor, 'MINE', ['B-2', 'B-1']);
        $this->skuWithBatches($supervisor, 'OTHER', ['OTHER-BATCH']);
        $this->selectBranch($supervisor);
        $this->actingAs($supervisor);

        $response = $this->getJson(route('inventory-references.skus.batches', $sku->public_id))->assertOk();

        $this->assertSame(['B-1', 'B-2'], array_column($response->json('data'), 'batchNumber'));
        $this->assertSame(['publicId', 'batchNumber', 'expiryDate'], array_keys($response->json('data.0')));
    }

    public function test_sku_batches_endpoint_returns_not_found_for_a_foreign_organisation_sku(): void
    {
        $foreign = $this->foreignOrganisationSupervisor();
        $foreignSku = $this->skuWithBatches($foreign, 'FOREIGN-B', ['X-1']);
        $supervisor = $this->actor('ca_supervisor');
        $this->selectBranch($supervisor);
        $this->actingAs($supervisor);

        $this->getJson(route('inventory-references.skus.batches', $foreignSku->public_id))->assertNotFound();
    }

    public function test_inventory_page_no_longer_ships_batches_in_its_props(): void
    {
        $supervisor = $this->actor('ca_supervisor');
        $this->skuWithBatches($supervisor, 'PROPS', ['P-1']);
        $this->selectBranch($supervisor);
        $this->actingAs($supervisor);

        $this->get(route('inventory.index'))->assertOk()
            ->assertInertia(fn ($page) => $page->has('referenceData')->missing('referenceData.batches'));
    }

    /**
     * @param  list<string>  $batchNumbers
     */
    private function skuWithBatches(User $actor, string $code, array $batchNumbers): InventorySku
    {
        $service = app(InventoryReferenceAdministrationService::class);
        $item = $service->createItem($actor, ['code' => 'ITEM-'.$code, 'generic_name' => 'Item '.$code]);
        $sku = $service->createSku($actor, $item, [
            'sku_code' => 'SKU-'.$code, 'pack_size' => '1', 'purchase_unit' => 'box',
            'stock_unit' => 'tablet', 'dispensing_unit' => 'tablet', 'unit_conversion' => '1',
        ]);
        foreach ($batchNumbers as $number) {
            $service->createBatch($actor, $sku, ['batch_number' => $number, 'expiry_date' => now()->addYear()->toDateString()]);
        }

        return $sku;
    }

    private function foreignOrganisationSupervisor(): User
    {
        $organisation = new Organisation;
        $organisation->forceFill([
            'code' => 'FOREIGN-'.strtoupper(bin2hex(random_bytes(3))),
            'name' => 'Synthetic Foreign Organisation',
            'is_active' => true,
        ])->save();
        $branch = new Branch;
        $branch->forceFill([
            'organisation_id' => $organisation->id,
            'code' => 'FOREIGN',
            'name' => 'Synthetic Foreign Branch',
            'timezone' => 'Asia/Kuala_Lumpur',
            'is_active' => true,
        ])->save();

        return $this->actor('ca_supervisor', $branch);
    }
}
