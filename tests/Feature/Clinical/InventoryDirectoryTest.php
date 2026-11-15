<?php

namespace Tests\Feature\Clinical;

use App\Domain\Access\BranchAccessService;
use App\Domain\Access\PermissionCatalogue;
use App\Domain\Clinical\Dispensary\Models\DispensaryHandoff;
use App\Domain\Clinical\Dispensary\Services\DispensaryHandoffService;
use App\Domain\Clinical\Dispensary\Services\DispensaryService;
use App\Domain\Clinical\Models\MedicineCatalogueItem;
use App\Domain\Clinical\Services\MedicineAdministrationService;
use App\Domain\Clinical\Services\PatientAllergyService;
use App\Domain\Clinical\Services\TreatmentPlanService;
use App\Domain\Identity\Models\StaffBranchAssignment;
use App\Domain\Organisation\Inventory\Models\InventoryBatch;
use App\Domain\Organisation\Inventory\Models\InventoryItem;
use App\Domain\Organisation\Inventory\Models\InventoryLocation;
use App\Domain\Organisation\Inventory\Models\InventorySku;
use App\Domain\Organisation\Inventory\Models\MedicineCatalogueInventorySku;
use App\Domain\Organisation\Inventory\Models\StockMovement;
use App\Domain\Organisation\Inventory\Services\InventoryMovementService;
use App\Domain\Organisation\Inventory\Services\InventoryReferenceAdministrationService;
use App\Domain\Organisation\Inventory\Services\ProcurementService;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Organisation\Models\Organisation;
use App\Models\User;
use Illuminate\Support\Facades\Date;
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

        foreach (['resident_doctor', 'panel_officer', 'technical_admin'] as $role) {
            $actor = $this->actor($role);
            $this->selectBranch($actor);
            $this->get(route('inventory.index'))->assertForbidden();
            $this->get(route('dashboard'))->assertOk()->assertInertia(fn (Assert $page) => $page->where('workspace.navigation.inventory', false));
        }

        $finance = $this->actor('finance_officer');
        $this->selectBranch($finance);
        $this->get(route('inventory.index'))->assertOk();

        $this->assertContains('inventory.view.branch', PermissionCatalogue::roles()['director']);
        foreach (['inventory.opening_balance.branch', 'inventory.transfer.branch', 'inventory.transfer.organisation'] as $permission) {
            $this->assertNotContains($permission, PermissionCatalogue::roles()['director']);
        }
    }

    public function test_inventory_navigation_requires_a_current_branch_assignment_at_the_kuala_lumpur_date_boundary(): void
    {
        Date::setTestNow('2026-09-15 16:30:00 UTC');

        try {
            $branchDate = now()->setTimezone($this->branch->timezone);
            $this->assertSame('Asia/Kuala_Lumpur', $this->branch->timezone);
            $this->assertSame('2026-09-16', $branchDate->toDateString());

            $current = $this->actor('ca_supervisor');
            StaffBranchAssignment::query()->where('staff_profile_id', $current->staffProfile->id)->update([
                'valid_from' => $branchDate->toDateString(),
                'valid_until' => $branchDate->toDateString(),
            ]);
            $this->selectBranch($current);
            $this->assertTrue($current->can(ProcurementService::WAREHOUSE_PERMISSION));
            $this->get(route('dashboard'))->assertOk()->assertInertia(
                fn (Assert $page) => $page->where('workspace.navigation.inventory', true),
            );
            $this->get(route('inventory.index'))->assertOk();

            $expired = $this->actor('ca');
            StaffBranchAssignment::query()->where('staff_profile_id', $expired->staffProfile->id)->update([
                'valid_until' => $branchDate->subDay()->toDateString(),
            ]);
            $this->selectBranch($expired);
            $this->get(route('dashboard'))->assertOk()->assertInertia(
                fn (Assert $page) => $page->where('workspace.navigation.inventory', false),
            );
            $this->get(route('inventory.index'))->assertNotFound();

            $future = $this->actor('ca');
            StaffBranchAssignment::query()->where('staff_profile_id', $future->staffProfile->id)->update([
                'valid_from' => $branchDate->addDay()->toDateString(),
                'valid_until' => null,
            ]);
            $this->selectBranch($future);
            $this->get(route('dashboard'))->assertOk()->assertInertia(
                fn (Assert $page) => $page->where('workspace.navigation.inventory', false),
            );
            $this->get(route('inventory.index'))->assertNotFound();

            $technical = $this->actor('technical_admin');
            $this->selectBranch($technical);
            $this->get(route('dashboard'))->assertOk()->assertInertia(
                fn (Assert $page) => $page->where('workspace.navigation.inventory', false),
            );
            $this->get(route('inventory.index'))->assertForbidden();
        } finally {
            Date::setTestNow();
        }
    }

    public function test_receipt_memory_scope_is_server_owned_and_rotates_with_the_authenticated_session(): void
    {
        $actorA = $this->actor('ca_supervisor');
        $actorB = $this->actor('ca_supervisor');
        $loginResponse = $this->get(route('login'))->assertOk();

        $this->post(route('login.store'), ['email' => $actorA->email, 'password' => 'password'])
            ->assertRedirect(route('workspace', absolute: false));
        $dashboardResponse = $this->get(route('dashboard'))->assertOk();
        session([BranchAccessService::SESSION_KEY => $this->branch->id]);

        $firstResponse = $this->get(route('inventory.index'))->assertOk();
        $firstPage = $firstResponse->inertiaPage();
        $scopeA = $firstResponse->inertiaProps('receiptMemoryContext');
        $sessionIdA = $this->app['session']->getId();
        $serializedFirstPage = json_encode($firstPage, JSON_THROW_ON_ERROR);

        $this->assertTrue(config('inertia.history.encrypt'));
        $this->assertTrue($loginResponse->inertiaPage()['encryptHistory']);
        $this->assertTrue($dashboardResponse->inertiaPage()['encryptHistory']);
        $this->assertTrue($firstPage['encryptHistory']);
        $this->assertStringNotContainsString($sessionIdA, $serializedFirstPage);
        $this->assertStringNotContainsString((string) config('app.key'), $serializedFirstPage);

        $this->assertSame(2, $scopeA['version']);
        foreach (['organisationPublicId', 'actorPublicId', 'branchPublicId'] as $field) {
            $this->assertMatchesRegularExpression('/\A[0-9a-f]{64}\z/', $scopeA[$field]);
        }
        $this->assertTrue(Str::isUuid($scopeA['sessionNonce']));
        $this->assertNotSame($sessionIdA, $scopeA['sessionNonce']);
        $this->assertStringNotContainsString($sessionIdA, json_encode($scopeA, JSON_THROW_ON_ERROR));
        $this->assertNotSame((string) $actorA->id, $scopeA['actorPublicId']);
        $this->assertNotSame((string) $this->organisation->id, $scopeA['organisationPublicId']);
        $this->assertNotSame((string) $this->branch->id, $scopeA['branchPublicId']);

        $sameSessionResponse = $this->get(route('inventory.index', [
            'receiptMemoryContext' => [
                'organisationPublicId' => str_repeat('f', 64),
                'actorPublicId' => str_repeat('f', 64),
                'sessionNonce' => (string) Str::uuid(),
                'branchPublicId' => str_repeat('f', 64),
            ],
            'encryptHistory' => false,
        ]))->assertOk();
        $sameSession = $sameSessionResponse->inertiaProps('receiptMemoryContext');
        $this->assertSame($scopeA, $sameSession);
        $this->assertTrue($sameSessionResponse->inertiaPage()['encryptHistory']);

        $this->post(route('logout'))->assertRedirect(route('home'));
        $this->assertGuest();
        $this->post(route('login.store'), ['email' => $actorB->email, 'password' => 'password'])
            ->assertRedirect(route('workspace', absolute: false));
        session([BranchAccessService::SESSION_KEY => $this->branch->id]);
        $responseB = $this->get(route('inventory.index'))->assertOk();
        $scopeB = $responseB->inertiaProps('receiptMemoryContext');

        $this->assertSame($scopeA['organisationPublicId'], $scopeB['organisationPublicId']);
        $this->assertSame($scopeA['branchPublicId'], $scopeB['branchPublicId']);
        $this->assertNotSame($scopeA['actorPublicId'], $scopeB['actorPublicId']);
        $this->assertNotSame($scopeA['sessionNonce'], $scopeB['sessionNonce']);
        $this->assertTrue($responseB->inertiaPage()['encryptHistory']);

        $this->post(route('logout'))->assertRedirect(route('home'));
        $this->post(route('login.store'), ['email' => $actorA->email, 'password' => 'password'])
            ->assertRedirect(route('workspace', absolute: false));
        session([BranchAccessService::SESSION_KEY => $this->branch->id]);
        $scopeANewSession = $this->get(route('inventory.index'))->assertOk()->inertiaProps('receiptMemoryContext');

        $this->assertSame($scopeA['actorPublicId'], $scopeANewSession['actorPublicId']);
        $this->assertNotSame($scopeA['sessionNonce'], $scopeANewSession['sessionNonce']);
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

    public function test_movement_dates_are_inclusive_in_kuala_lumpur_and_combine_with_scoped_filters(): void
    {
        Date::setTestNow('2026-09-17 04:00:00 UTC');

        try {
            $actor = $this->actor('ca_supervisor');
            $this->selectBranch($actor);
            $fixture = $this->inventoryFixture($actor, 'DATED', null);
            $otherLocation = app(InventoryReferenceAdministrationService::class)->createLocation($actor, $this->branch, null, [
                'code' => 'DATED-OTHER', 'name' => 'Synthetic Other Cheras Store', 'type' => InventoryLocation::TYPE_BRANCH_STORE,
            ]);

            $insert = function (string $occurredAt, string $type, InventoryLocation $location) use ($actor, $fixture): void {
                $movement = new StockMovement;
                $movement->forceFill([
                    'public_id' => (string) Str::uuid(), 'organisation_id' => $actor->organisation_id,
                    'inventory_sku_id' => $fixture['sku']->id, 'inventory_batch_id' => $fixture['batch']->id,
                    'source_location_id' => null, 'destination_location_id' => $location->id,
                    'quantity' => '1.000', 'movement_type' => $type, 'reference_type' => 'inventory_opening_balance',
                    'reference_public_id' => (string) Str::uuid(), 'actor_user_id' => $actor->id, 'occurred_at' => $occurredAt,
                ])->save();
            };

            for ($index = 0; $index < 26; $index++) {
                $insert('2026-09-17 00:00:00+08:00', StockMovement::TYPE_OPENING, $fixture['location']);
            }
            $insert('2026-09-16 12:00:00+08:00', StockMovement::TYPE_ADJUSTMENT_IN, $otherLocation);
            $insert('2026-09-18 12:00:00+08:00', StockMovement::TYPE_ADJUSTMENT_IN, $otherLocation);

            $this->foreignInventoryFixture();
            $this->selectBranch($actor);
            $movementCount = DB::table('stock_movements')->count();

            $this->get(route('inventory.index', ['tab' => 'movements', 'date_from' => '2026-09-18']))
                ->assertOk()->assertInertia(fn (Assert $page) => $page->where('inventory.total', 1));
            $this->get(route('inventory.index', ['tab' => 'movements', 'date_to' => '2026-09-16']))
                ->assertOk()->assertInertia(fn (Assert $page) => $page->where('inventory.total', 1));
            $this->get(route('inventory.index', [
                'tab' => 'movements', 'date_from' => '2026-09-17', 'date_to' => '2026-09-17',
                'movement_type' => StockMovement::TYPE_OPENING, 'location' => $fixture['location']->public_id,
            ]))->assertOk()->assertInertia(fn (Assert $page) => $page
                ->where('inventory.total', 26)
                ->where('inventory.filters.dateFrom', '2026-09-17')
                ->where('inventory.filters.dateTo', '2026-09-17')
                ->where('inventory.filters.movementType', StockMovement::TYPE_OPENING)
                ->where('inventory.filters.location', $fixture['location']->public_id));
            $this->get(route('inventory.index', [
                'tab' => 'movements', 'date_from' => '2026-09-17', 'date_to' => '2026-09-17', 'page' => 2,
            ]))->assertOk()->assertInertia(fn (Assert $page) => $page
                ->where('inventory.total', 26)->where('inventory.currentPage', 2)->has('inventory.data', 1)
                ->where('inventory.filters.dateFrom', '2026-09-17')->where('inventory.filters.dateTo', '2026-09-17'));
            $this->get(route('inventory.index', ['tab' => 'movements', 'date_from' => '2026-09-20', 'date_to' => '2026-09-20']))
                ->assertOk()->assertInertia(fn (Assert $page) => $page->where('inventory.total', 0)->has('inventory.data', 0));
            $this->get(route('inventory.index', ['tab' => 'movements', 'date_from' => '2026-09-18', 'date_to' => '2026-09-17']))
                ->assertSessionHasErrors('date_to');

            $this->assertSame($movementCount, DB::table('stock_movements')->count());
        } finally {
            Date::setTestNow();
        }
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
