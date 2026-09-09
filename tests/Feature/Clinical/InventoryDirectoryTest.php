<?php

namespace Tests\Feature\Clinical;

use App\Domain\Access\PermissionCatalogue;
use App\Domain\Clinical\Dispensary\Models\DispensaryHandoff;
use App\Domain\Clinical\Dispensary\Services\DispensaryHandoffService;
use App\Domain\Clinical\Dispensary\Services\DispensaryService;
use App\Domain\Clinical\Models\MedicineCatalogueItem;
use App\Domain\Clinical\Services\MedicineAdministrationService;
use App\Domain\Clinical\Services\PatientAllergyService;
use App\Domain\Clinical\Services\TreatmentPlanService;
use App\Domain\Organisation\Inventory\Models\InventoryBatch;
use App\Domain\Organisation\Inventory\Models\InventoryItem;
use App\Domain\Organisation\Inventory\Models\InventoryLocation;
use App\Domain\Organisation\Inventory\Models\InventorySku;
use App\Domain\Organisation\Inventory\Models\MedicineCatalogueInventorySku;
use App\Domain\Organisation\Inventory\Services\InventoryMovementService;
use App\Domain\Organisation\Inventory\Services\InventoryReferenceAdministrationService;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Organisation\Models\Organisation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

class InventoryDirectoryTest extends ClinicalTestCase
{
    public function test_branch_inventory_workspace_permission_and_navigation_are_narrow(): void
    {
        foreach (['director', 'ca_supervisor', 'ca'] as $role) {
            $actor = $this->actor($role);
            $this->selectBranch($actor);
            $this->get(route('inventory.index'))->assertOk()->assertHeader('Cache-Control', 'max-age=0, must-revalidate, no-store, private');
            $this->get(route('dashboard'))->assertOk()->assertInertia(fn (Assert $page) => $page->where('workspace.navigation.inventory', true));
        }

        foreach (['resident_doctor', 'panel_officer', 'finance_officer', 'technical_admin'] as $role) {
            $actor = $this->actor($role);
            $this->selectBranch($actor);
            $this->get(route('inventory.index'))->assertForbidden();
            $this->get(route('dashboard'))->assertOk()->assertInertia(fn (Assert $page) => $page->where('workspace.navigation.inventory', false));
        }

        $this->assertContains('inventory.view.branch', PermissionCatalogue::roles()['director']);
        foreach (['inventory.opening_balance.branch', 'inventory.transfer.branch', 'inventory.transfer.organisation'] as $permission) {
            $this->assertNotContains($permission, PermissionCatalogue::roles()['director']);
        }
    }

    public function test_stock_batch_and_opening_movement_projections_are_tenant_scoped_and_read_only(): void
    {
        $actor = $this->actor('ca_supervisor');
        $this->selectBranch($actor);
        $fixture = $this->inventoryFixture($actor, 'LOCAL', 'Synthetic Paracetamol');
        app(InventoryMovementService::class)->openingBalance($actor, $this->openingAttributes($fixture, '1000.000'));
        $this->foreignInventoryFixture();
        $this->selectBranch($actor);

        $before = [
            DB::table('inventory_stock_balances')->orderBy('id')->get()->toJson(),
            DB::table('stock_movements')->orderBy('id')->get()->toJson(),
            DB::table('inventory_batches')->orderBy('id')->get()->toJson(),
            DB::table('medicine_catalogue_inventory_skus')->orderBy('id')->get()->toJson(),
        ];

        $this->get(route('inventory.index'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Inventory/Index')
            ->where('inventory.branch', $this->branch->name)
            ->where('inventory.summary.skusWithStock', 1)
            ->where('inventory.summary.totalBatches', 1)
            ->where('inventory.summary.expiredBatches', 0)
            ->has('inventory.data', 1)
            ->where('inventory.data.0.skuCode', 'LOCAL-SKU')
            ->where('inventory.data.0.medicineName', 'Synthetic Paracetamol')
            ->where('inventory.data.0.quantity', '1000')
            ->where('inventory.data.0.stockUnit', 'tablet')
            ->where('inventory.data.0.batchNumber', 'LOCAL-BATCH')
            ->where('inventory.data.0.branch', $this->branch->name)
            ->missing('inventory.data.0.organisation_id')
            ->missing('inventory.data.0.inventory_sku_id'));

        $this->get(route('inventory.index', ['tab' => 'batches']))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('inventory.tab', 'batches')
            ->has('inventory.data', 1)
            ->where('inventory.data.0.batchNumber', 'LOCAL-BATCH')
            ->where('inventory.data.0.expiryDate', '31 Dec 2028')
            ->where('inventory.data.0.receivedDate', '9 Sep 2026')
            ->where('inventory.data.0.expiryStatus', 'Valid')
            ->where('inventory.data.0.batchStatus', 'Available'));

        $this->get(route('inventory.index', ['tab' => 'movements']))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('inventory.data', 1)
            ->where('inventory.data.0.typeLabel', 'Opening Balance')
            ->where('inventory.data.0.quantityDisplay', '+1000')
            ->where('inventory.data.0.direction', 'To Synthetic Cheras Dispensary')
            ->where('inventory.data.0.reference', 'Opening balance'));

        $after = [
            DB::table('inventory_stock_balances')->orderBy('id')->get()->toJson(),
            DB::table('stock_movements')->orderBy('id')->get()->toJson(),
            DB::table('inventory_batches')->orderBy('id')->get()->toJson(),
            DB::table('medicine_catalogue_inventory_skus')->orderBy('id')->get()->toJson(),
        ];
        $this->assertSame($before, $after);
    }

    public function test_workspace_reflects_released_dispense_ledger_result_without_a_second_deduction(): void
    {
        [$doctor, $ca, $visit, $queue] = $this->servingFixture();
        $this->startEncounter($doctor, $visit, $queue);
        $profile = app(PatientAllergyService::class)->declareNoKnown($doctor, $visit, [
            'expected_branch_id' => $visit->branch_id,
            'profile_lock_version' => null,
        ]);
        app(PatientAllergyService::class)->review($doctor, $visit, [
            'expected_branch_id' => $visit->branch_id,
            'profile_lock_version' => $profile->lock_version,
        ]);

        $supervisor = $this->actor('ca_supervisor');
        $medicine = app(MedicineAdministrationService::class)->create($supervisor, [
            'code' => 'FLOW-MED', 'display_name' => 'Synthetic Friday Medicine', 'strength_text' => '500 mg',
            'dosage_form' => 'tablet', 'order_unit' => 'tablet',
        ]);
        $this->selectBranch($doctor);
        $plan = app(TreatmentPlanService::class)->save($doctor, $visit, [
            'expected_branch_id' => $visit->branch_id,
            'lock_version' => null,
            'medicines' => [[
                'public_id' => null, 'catalogue_public_id' => $medicine->public_id, 'quantity_ordered' => '10.000',
                'dosage' => 'One tablet', 'frequency' => 'As directed', 'duration' => null, 'route' => 'oral',
                'administration_instruction' => null, 'indication' => null, 'precaution' => null,
            ]],
            'services' => [],
        ]);
        $case = app(DispensaryHandoffService::class)->send($doctor, $visit, [
            'expected_branch_id' => $visit->branch_id,
            'lock_version' => $plan->lock_version,
        ]);
        $this->selectBranch($ca);
        $case = app(DispensaryService::class)->start($ca, $case, [
            'expected_branch_id' => $visit->branch_id,
            'case_lock_version' => $case->lock_version,
        ]);
        $item = $case->handoffs()->where('status', DispensaryHandoff::STATUS_OPEN)->sole()->items()->sole();

        $this->selectBranch($supervisor);
        $fixture = $this->inventoryFixture($supervisor, 'FLOW', null, $medicine);
        app(InventoryMovementService::class)->openingBalance($supervisor, $this->openingAttributes($fixture, '1000.000'));
        $this->selectBranch($ca);
        app(DispensaryService::class)->updateItem($ca, $case->refresh(), $item->refresh(), [
            'expected_branch_id' => $visit->branch_id,
            'case_lock_version' => $case->lock_version,
            'item_lock_version' => $item->lock_version,
            'status' => 'dispensed',
            'quantity_dispensed' => '10.000',
            'reason' => null,
            'allocations' => [[
                'location_public_id' => $fixture['location']->public_id,
                'sku_public_id' => $fixture['sku']->public_id,
                'batch_public_id' => $fixture['batch']->public_id,
                'quantity' => '10.000',
            ]],
        ]);
        $case->refresh();
        app(DispensaryService::class)->complete($ca, $case, [
            'expected_branch_id' => $visit->branch_id,
            'case_lock_version' => $case->lock_version,
        ]);

        $movementCount = DB::table('stock_movements')->count();
        $this->selectBranch($supervisor);
        $this->get(route('inventory.index'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('inventory.data.0.quantity', '990'));
        $this->get(route('inventory.index', ['tab' => 'movements', 'movement_type' => 'dispense']))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('inventory.data', 1)
            ->where('inventory.data.0.typeLabel', 'Dispense')
            ->where('inventory.data.0.quantityDisplay', '-10')
            ->where('inventory.data.0.direction', 'From Synthetic Cheras Dispensary'));

        $this->assertDatabaseHas('inventory_stock_balances', [
            'inventory_location_id' => $fixture['location']->id,
            'inventory_sku_id' => $fixture['sku']->id,
            'inventory_batch_id' => $fixture['batch']->id,
            'quantity' => 990,
        ]);
        $this->assertSame($movementCount, DB::table('stock_movements')->count());
    }

    public function test_filters_remain_inside_active_branch_and_no_balance_is_not_fabricated(): void
    {
        $actor = $this->actor('ca_supervisor');
        $this->selectBranch($actor);
        $visible = $this->inventoryFixture($actor, 'VISIBLE', null);
        app(InventoryMovementService::class)->openingBalance($actor, $this->openingAttributes($visible, '3.500'));
        $noBalance = $this->inventoryFixture($actor, 'NO-BALANCE', null);

        $this->get(route('inventory.index', ['search' => 'visible-sku', 'location' => $visible['location']->public_id]))
            ->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('inventory.data', 1)
            ->where('inventory.data.0.skuCode', 'VISIBLE-SKU')
            ->where('inventory.data.0.medicineName', null));
        $this->get(route('inventory.index', ['search' => 'NO-BALANCE-SKU']))
            ->assertOk()->assertInertia(fn (Assert $page) => $page->has('inventory.data', 0));
        $this->get(route('inventory.index', ['location' => (string) Str::uuid()]))->assertNotFound();
        $this->assertDatabaseMissing('inventory_stock_balances', ['inventory_sku_id' => $noBalance['sku']->id]);
    }

    /** @return array{item:InventoryItem,sku:InventorySku,location:InventoryLocation,batch:InventoryBatch,mapping:?MedicineCatalogueInventorySku} */
    private function inventoryFixture(User $actor, string $prefix, ?string $medicineName, ?MedicineCatalogueItem $existingMedicine = null, ?Branch $branch = null): array
    {
        $branch ??= $this->branch;
        $service = app(InventoryReferenceAdministrationService::class);
        $item = $service->createItem($actor, [
            'code' => $prefix.'-ITEM', 'generic_name' => 'Synthetic '.$prefix.' Item', 'brand_name' => null,
            'strength' => '500 mg', 'dosage_form' => 'tablet', 'route' => 'oral', 'manufacturer' => null, 'mal_number' => null,
        ]);
        $sku = $service->createSku($actor, $item, [
            'sku_code' => $prefix.'-SKU', 'barcode' => null, 'pack_size' => '1', 'purchase_unit' => 'tablet',
            'stock_unit' => 'tablet', 'dispensing_unit' => 'tablet', 'unit_conversion' => '1', 'storage_type' => 'ambient',
            'cold_chain_required' => false, 'do_not_freeze' => false, 'protect_from_light' => false,
            'batch_tracking_required' => true, 'expiry_tracking_required' => true,
        ]);
        $location = $service->createLocation($actor, $branch, null, [
            'code' => $prefix.'-DISP', 'name' => 'Synthetic Cheras Dispensary', 'type' => InventoryLocation::TYPE_DISPENSARY,
        ]);
        $batch = $service->createBatch($actor, $sku, [
            'batch_number' => $prefix.'-BATCH', 'expiry_date' => '2028-12-31', 'received_at' => '2026-09-09', 'status' => InventoryBatch::STATUS_AVAILABLE,
        ]);
        $mapping = null;
        if ($medicineName || $existingMedicine) {
            $medicine = $existingMedicine ?? app(MedicineAdministrationService::class)->create($actor, [
                'code' => $prefix.'-MED', 'display_name' => $medicineName, 'strength_text' => '500 mg', 'dosage_form' => 'tablet', 'order_unit' => 'tablet',
            ]);
            $mapping = $service->createMapping($actor, $medicine, $sku);
        }

        return ['item' => $item, 'sku' => $sku, 'location' => $location, 'batch' => $batch, 'mapping' => $mapping];
    }

    /** @param array{sku:InventorySku,location:InventoryLocation,batch:InventoryBatch} $fixture
     * @return array<string, mixed>
     */
    private function openingAttributes(array $fixture, string $quantity): array
    {
        $attributes = [
            'expected_branch_id' => $this->branch->id,
            'location_public_id' => $fixture['location']->public_id,
            'sku_public_id' => $fixture['sku']->public_id,
            'batch_public_id' => $fixture['batch']->public_id,
            'quantity' => $quantity,
        ];

        return $attributes;
    }

    private function foreignInventoryFixture(): void
    {
        $organisation = new Organisation;
        $organisation->forceFill(['code' => 'FOREIGN-'.Str::upper(Str::random(5)), 'name' => 'Synthetic Foreign Organisation', 'is_active' => true])->save();
        $branch = new Branch;
        $branch->forceFill(['organisation_id' => $organisation->id, 'code' => 'FOREIGN', 'name' => 'Synthetic Foreign Branch', 'timezone' => 'Asia/Kuala_Lumpur', 'is_active' => true])->save();
        $actor = $this->actor('ca_supervisor', $branch);
        $this->selectBranch($actor, $branch);
        $fixture = $this->inventoryFixture($actor, 'FOREIGN', 'Foreign Medicine', null, $branch);
        app(InventoryMovementService::class)->openingBalance($actor, [
            'expected_branch_id' => $branch->id,
            'location_public_id' => $fixture['location']->public_id,
            'sku_public_id' => $fixture['sku']->public_id,
            'batch_public_id' => $fixture['batch']->public_id,
            'quantity' => '500.000',
        ]);
    }
}
