<?php

namespace Tests\Feature\Clinical;

use App\Domain\Access\PermissionCatalogue;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Clinical\Models\MedicineCatalogueItem;
use App\Domain\Clinical\Services\MedicineAdministrationService;
use App\Domain\Organisation\Inventory\Models\InventoryBatch;
use App\Domain\Organisation\Inventory\Models\InventoryItem;
use App\Domain\Organisation\Inventory\Models\InventoryLocation;
use App\Domain\Organisation\Inventory\Models\InventorySku;
use App\Domain\Organisation\Inventory\Services\InventoryMovementService;
use App\Domain\Organisation\Inventory\Services\InventoryReferenceAdministrationService;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Organisation\Models\Organisation;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LogicException;

class InventoryReferenceAdministrationServiceTest extends ClinicalTestCase
{
    public function test_permission_mapping_is_limited_to_director_and_ca_supervisor(): void
    {
        $roles = PermissionCatalogue::roles();
        $this->assertContains(InventoryReferenceAdministrationService::PERMISSION, PermissionCatalogue::all());
        foreach (['director', 'ca_supervisor'] as $role) {
            $this->assertContains(InventoryReferenceAdministrationService::PERMISSION, $roles[$role]);
        }
        foreach (['resident_doctor', 'ca', 'panel_officer', 'finance_officer', 'business_development', 'marketing', 'hr_manager', 'technical_admin'] as $role) {
            $this->assertNotContains(InventoryReferenceAdministrationService::PERMISSION, $roles[$role]);
        }
        $this->assertContains('inventory.view.branch', $roles['ca']);
        $this->assertContains('inventory.transfer.branch', $roles['ca']);
        $this->assertContains('inventory.opening_balance.branch', $roles['ca_supervisor']);
        $this->assertContains('inventory.transfer.organisation', $roles['ca_supervisor']);

        foreach (['resident_doctor', 'ca', 'panel_officer', 'finance_officer', 'technical_admin'] as $role) {
            try {
                $this->service()->createItem($this->actor($role), $this->itemAttributes(['code' => 'DENY-'.$role]));
                $this->fail("{$role} managed Inventory references.");
            } catch (AuthorizationException) {
                $this->assertDatabaseMissing('inventory_items', ['code' => Str::upper('DENY-'.$role)]);
            }
        }
    }

    public function test_item_governance_normalizes_rejects_duplicates_audits_and_never_deletes(): void
    {
        $actor = $this->actor('director');
        $item = $this->service()->createItem($actor, $this->itemAttributes([
            'code' => ' syn-paracetamol ',
            'generic_name' => ' Synthetic   Paracetamol ',
            'brand_name' => ' Demo Brand ',
        ]));
        $this->assertSame('SYN-PARACETAMOL', $item->code);
        $this->assertSame('Synthetic Paracetamol', $item->generic_name);
        $this->assertSame('Demo Brand', $item->brand_name);

        try {
            $this->service()->createItem($actor, $this->itemAttributes(['code' => ' syn-PARACETAMOL ']));
            $this->fail('A case-insensitive duplicate Inventory Item code was accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('code', $exception->errors());
        }

        $item = $this->service()->updateItem($actor, $item, ['generic_name' => 'Synthetic Updated Item']);
        $this->assertSame('Synthetic Updated Item', $item->generic_name);
        $this->assertFalse($this->service()->deactivateItem($actor, $item)->is_active);
        $this->assertTrue($this->service()->activateItem($actor, $item)->is_active);
        foreach (['inventory_item.created', 'inventory_item.updated', 'inventory_item.deactivated', 'inventory_item.activated'] as $event) {
            $this->assertDatabaseHas('audit_logs', ['event' => $event, 'subject_id' => $item->id, 'actor_user_id' => $actor->id]);
        }

        try {
            $item->delete();
            $this->fail('Inventory Item hard delete was allowed.');
        } catch (LogicException) {
            $this->assertDatabaseHas('inventory_items', ['id' => $item->id]);
        }
    }

    public function test_sku_supports_friday_units_and_locks_identity_after_evidence(): void
    {
        $actor = $this->actor('ca_supervisor');
        $item = $this->service()->createItem($actor, $this->itemAttributes());
        $sku = $this->service()->createSku($actor, $item, $this->skuAttributes());
        $this->assertSame('SYN-PARA500-TAB', $sku->sku_code);
        $this->assertSame('1.000', $sku->pack_size);
        $this->assertSame('tablet', $sku->purchase_unit);
        $this->assertSame('tablet', $sku->stock_unit);
        $this->assertSame('tablet', $sku->dispensing_unit);
        $this->assertSame('1.000', $sku->unit_conversion);

        $sku = $this->service()->updateSku($actor, $sku, ['sku_code' => 'SYN-PARA500-TAB-CORRECTED', 'stock_unit' => 'dose', 'dispensing_unit' => 'dose']);
        $this->assertSame('SYN-PARA500-TAB-CORRECTED', $sku->sku_code);
        $this->assertSame('dose', $sku->stock_unit);

        $batch = $this->service()->createBatch($actor, $sku, $this->batchAttributes());
        foreach ([['sku_code' => 'LOCKED'], ['pack_size' => '2'], ['stock_unit' => 'tablet'], ['dispensing_unit' => 'tablet'], ['unit_conversion' => '2']] as $change) {
            try {
                $this->service()->updateSku($actor, $sku, $change);
                $this->fail('An established SKU identity field was changed.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('sku', $exception->errors());
            }
        }
        $sku = $this->service()->updateSku($actor, $sku, ['barcode' => '9550000000001']);
        $this->assertSame('9550000000001', $sku->barcode);
        $this->assertFalse($this->service()->deactivateSku($actor, $sku)->is_active);
        $this->assertTrue($this->service()->activateSku($actor, $sku)->is_active);
        $this->assertSame('DEMO-B001', $batch->batch_number);
        $this->assertDatabaseHas('audit_logs', ['event' => 'inventory_sku.created', 'subject_id' => $sku->id]);
        $this->assertDatabaseHas('audit_logs', ['event' => 'inventory_sku.updated', 'subject_id' => $sku->id]);
        $this->assertDatabaseHas('audit_logs', ['event' => 'inventory_sku.deactivated', 'subject_id' => $sku->id]);
        $this->assertDatabaseHas('audit_logs', ['event' => 'inventory_sku.activated', 'subject_id' => $sku->id]);

        try {
            $this->service()->updateItem($actor, $item, ['code' => 'LOCKED-ITEM']);
            $this->fail('An Item code with an existing SKU was changed.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('code', $exception->errors());
        }
    }

    public function test_location_governance_supports_hq_and_branch_and_rejects_foreign_branch(): void
    {
        $actor = $this->actor('director');
        $hq = $this->service()->createLocation($actor, null, null, ['code' => 'syn-hq', 'name' => 'Synthetic HQ', 'type' => InventoryLocation::TYPE_MEDICAL_STOCK]);
        $dispensary = $this->service()->createLocation($actor, $this->branch, $hq, ['code' => 'syn-cheras-disp', 'name' => 'Synthetic Cheras Dispensary', 'type' => InventoryLocation::TYPE_DISPENSARY]);
        $this->assertNull($hq->branch_id);
        $this->assertSame($this->branch->id, $dispensary->branch_id);
        $this->assertSame($hq->id, $dispensary->parent_id);

        $dispensary = $this->service()->updateLocation($actor, $dispensary, ['name' => 'Synthetic Cheras Main Dispensary']);
        $this->assertSame('Synthetic Cheras Main Dispensary', $dispensary->name);
        $this->assertFalse($this->service()->deactivateLocation($actor, $dispensary)->is_active);
        $this->assertTrue($this->service()->activateLocation($actor, $dispensary)->is_active);

        [, $foreignBranch] = $this->foreignOrganisation();
        $this->expectException(ModelNotFoundException::class);
        $this->service()->createLocation($actor, $foreignBranch, null, ['code' => 'CROSS', 'name' => 'Cross tenant', 'type' => InventoryLocation::TYPE_DISPENSARY]);
    }

    public function test_batch_governance_normalizes_validates_duplicates_and_never_stores_quantity(): void
    {
        $actor = $this->actor('director');
        [, $sku] = $this->itemAndSku($actor);
        $batch = $this->service()->createBatch($actor, $sku, $this->batchAttributes(['batch_number' => ' demo-b001 ']));
        $this->assertSame('DEMO-B001', $batch->batch_number);
        $this->assertSame('2028-12-31', $batch->expiry_date->toDateString());
        $this->assertFalse(array_key_exists('quantity', $batch->getAttributes()));

        try {
            $this->service()->createBatch($actor, $sku, $this->batchAttributes(['batch_number' => 'Demo-B001']));
            $this->fail('A case-insensitive duplicate batch identity was accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('batch_number', $exception->errors());
        }

        try {
            $this->service()->updateBatch($actor, $batch, ['received_at' => '2029-01-01']);
            $this->fail('A received date after expiry was accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('received_at', $exception->errors());
        }

        $batch = $this->service()->updateBatch($actor, $batch, ['status' => InventoryBatch::STATUS_QUARANTINED]);
        $this->assertSame(InventoryBatch::STATUS_QUARANTINED, $batch->status);
        $this->assertDatabaseHas('audit_logs', ['event' => 'inventory_batch.created', 'subject_id' => $batch->id]);
        $this->assertDatabaseHas('audit_logs', ['event' => 'inventory_batch.updated', 'subject_id' => $batch->id]);
    }

    public function test_mapping_is_tenant_safe_and_allows_only_one_active_sku_per_medicine(): void
    {
        $actor = $this->actor('director');
        $medicine = $this->medicine($actor, 'MAP-MED');
        [$item, $firstSku] = $this->itemAndSku($actor, 'MAP-ITEM', 'MAP-SKU-1');
        $secondSku = $this->service()->createSku($actor, $item, $this->skuAttributes(['sku_code' => 'MAP-SKU-2']));
        $first = $this->service()->createMapping($actor, $medicine, $firstSku);
        $this->assertTrue($first->is_active);
        try {
            $this->service()->deactivateSku($actor, $firstSku);
            $this->fail('A SKU with an active Medicine mapping was deactivated.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('sku', $exception->errors());
        }

        try {
            $this->service()->createMapping($actor, $medicine, $secondSku);
            $this->fail('A second active Medicine mapping was accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('mapping', $exception->errors());
        }

        $alternate = $this->service()->createMapping($actor, $medicine, $secondSku, ['is_active' => false]);
        $this->assertFalse($alternate->is_active);
        $this->service()->deactivateMapping($actor, $first);
        $alternate = $this->service()->activateMapping($actor, $alternate);
        $this->assertTrue($alternate->is_active);
        try {
            $this->service()->activateMapping($actor, $first);
            $this->fail('Reactivation created a second active mapping.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('mapping', $exception->errors());
        }

        $events = AuditLog::query()->where('subject_type', $alternate->getMorphClass())->pluck('event')->all();
        $this->assertContains('medicine_inventory_mapping.created', $events);
        $this->assertContains('medicine_inventory_mapping.activated', $events);
        $this->assertSame($actor->id, $alternate->approved_by_user_id);
        $audit = AuditLog::query()->where('event', 'medicine_inventory_mapping.activated')->sole();
        $this->assertEqualsCanonicalizing(['medicine_public_id', 'inventory_sku_public_id', 'changed_fields'], array_keys($audit->metadata));

        try {
            $alternate->delete();
            $this->fail('Medicine Inventory mapping hard delete was allowed.');
        } catch (LogicException) {
            $this->assertDatabaseHas('medicine_catalogue_inventory_skus', ['id' => $alternate->id]);
        }
    }

    public function test_cross_tenant_item_sku_batch_and_mapping_mutations_fail_neutrally(): void
    {
        $actor = $this->actor('director');
        [, $foreignBranch] = $this->foreignOrganisation();
        $foreignActor = $this->actor('director', $foreignBranch);
        [$foreignItem, $foreignSku] = $this->itemAndSku($foreignActor, 'FOREIGN-ITEM', 'FOREIGN-SKU');
        $foreignBatch = $this->service()->createBatch($foreignActor, $foreignSku, $this->batchAttributes(['batch_number' => 'FOREIGN-BATCH']));
        $foreignMedicine = $this->medicine($foreignActor, 'FOREIGN-MED');
        [$localItem, $localSku] = $this->itemAndSku($actor, 'FOREIGN-ITEM', 'FOREIGN-SKU');
        $this->assertSame($actor->organisation_id, $localItem->organisation_id);
        $this->assertSame($actor->organisation_id, $localSku->organisation_id);

        foreach (
            [
                fn () => $this->service()->updateItem($actor, $foreignItem, ['generic_name' => 'Cross tenant']),
                fn () => $this->service()->updateSku($actor, $foreignSku, ['barcode' => '1']),
                fn () => $this->service()->updateBatch($actor, $foreignBatch, ['status' => InventoryBatch::STATUS_DAMAGED]),
                fn () => $this->service()->createMapping($actor, $foreignMedicine, $foreignSku),
            ] as $operation
        ) {
            try {
                $operation();
                $this->fail('A cross-tenant Inventory reference mutation succeeded.');
            } catch (ModelNotFoundException) {
                $this->assertTrue(true);
            }
        }

        $localMedicine = $this->medicine($actor, 'LOCAL-MED');
        $this->expectException(ModelNotFoundException::class);
        $this->service()->createMapping($actor, $localMedicine, $foreignSku);
    }

    public function test_governed_references_support_opening_balance_without_direct_balance_api(): void
    {
        $actor = $this->actor('ca_supervisor');
        $this->selectBranch($actor);
        [, $sku] = $this->itemAndSku($actor);
        $location = $this->service()->createLocation($actor, $this->branch, null, ['code' => 'FRIDAY-DISP', 'name' => 'Synthetic Friday Dispensary', 'type' => InventoryLocation::TYPE_DISPENSARY]);
        $batch = $this->service()->createBatch($actor, $sku, $this->batchAttributes());

        app(InventoryMovementService::class)->openingBalance($actor, [
            'expected_branch_id' => $this->branch->id,
            'location_public_id' => $location->public_id,
            'sku_public_id' => $sku->public_id,
            'batch_public_id' => $batch->public_id,
            'quantity' => '1000.000',
        ]);

        $this->assertDatabaseHas('inventory_stock_balances', ['inventory_location_id' => $location->id, 'inventory_sku_id' => $sku->id, 'inventory_batch_id' => $batch->id, 'quantity' => 1000]);
        $this->assertDatabaseHas('stock_movements', ['movement_type' => 'opening_balance', 'quantity' => 1000]);
        $this->assertFalse(method_exists($this->service(), 'setQuantity'));
        $this->assertFalse(method_exists($this->service(), 'adjustStock'));
    }

    private function service(): InventoryReferenceAdministrationService
    {
        return app(InventoryReferenceAdministrationService::class);
    }

    /** @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function itemAttributes(array $overrides = []): array
    {
        return ['code' => 'SYN-PARACETAMOL-'.Str::upper(Str::random(5)), 'generic_name' => 'Synthetic Paracetamol', 'brand_name' => null, 'strength' => '500 mg', 'dosage_form' => 'tablet', 'route' => 'oral', 'manufacturer' => null, 'mal_number' => null, ...$overrides];
    }

    /** @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function skuAttributes(array $overrides = []): array
    {
        return ['sku_code' => 'SYN-PARA500-TAB', 'barcode' => null, 'pack_size' => '1', 'purchase_unit' => 'tablet', 'stock_unit' => 'tablet', 'dispensing_unit' => 'tablet', 'unit_conversion' => '1', 'storage_type' => 'ambient', 'cold_chain_required' => false, 'do_not_freeze' => false, 'protect_from_light' => false, 'batch_tracking_required' => true, 'expiry_tracking_required' => true, ...$overrides];
    }

    /** @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function batchAttributes(array $overrides = []): array
    {
        return ['batch_number' => 'DEMO-B001', 'expiry_date' => '2028-12-31', 'received_at' => '2026-09-09', 'status' => InventoryBatch::STATUS_AVAILABLE, ...$overrides];
    }

    /** @return array{InventoryItem, InventorySku} */
    private function itemAndSku(User $actor, ?string $itemCode = null, ?string $skuCode = null): array
    {
        $item = $this->service()->createItem($actor, $this->itemAttributes(['code' => $itemCode ?? 'ITEM-'.Str::upper(Str::random(6))]));
        $sku = $this->service()->createSku($actor, $item, $this->skuAttributes(['sku_code' => $skuCode ?? 'SKU-'.Str::upper(Str::random(6))]));

        return [$item, $sku];
    }

    private function medicine(User $actor, string $code): MedicineCatalogueItem
    {
        return app(MedicineAdministrationService::class)->create($actor, ['code' => $code, 'display_name' => 'Synthetic mapped medicine', 'strength_text' => '500 mg', 'dosage_form' => 'tablet', 'order_unit' => 'tablet']);
    }

    /** @return array{Organisation, Branch} */
    private function foreignOrganisation(): array
    {
        $organisation = new Organisation;
        $organisation->forceFill(['code' => 'FOREIGN-'.Str::upper(Str::random(6)), 'name' => 'Synthetic Foreign Organisation', 'is_active' => true])->save();
        $branch = new Branch;
        $branch->forceFill(['organisation_id' => $organisation->id, 'code' => 'FOREIGN', 'name' => 'Synthetic Foreign Branch', 'timezone' => 'Asia/Kuala_Lumpur', 'is_active' => true])->save();

        return [$organisation, $branch];
    }
}
