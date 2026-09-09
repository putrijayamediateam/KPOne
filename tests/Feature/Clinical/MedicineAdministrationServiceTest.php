<?php

namespace Tests\Feature\Clinical;

use App\Domain\Access\PermissionCatalogue;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Clinical\Models\MedicineCatalogueItem;
use App\Domain\Clinical\Services\MedicineAdministrationService;
use App\Domain\Clinical\Services\PatientAllergyService;
use App\Domain\Clinical\Services\TreatmentPlanDirectoryService;
use App\Domain\Clinical\Services\TreatmentPlanService;
use App\Domain\Organisation\Inventory\Models\InventoryItem;
use App\Domain\Organisation\Inventory\Models\InventorySku;
use App\Domain\Organisation\Inventory\Models\MedicineCatalogueInventorySku;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Organisation\Models\Organisation;
use App\Domain\Visit\Billing\Models\ChargeDefinition;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LogicException;

class MedicineAdministrationServiceTest extends ClinicalTestCase
{
    public function test_permission_mapping_is_limited_to_director_and_ca_supervisor(): void
    {
        $roles = PermissionCatalogue::roles();
        $this->assertContains(MedicineAdministrationService::PERMISSION, PermissionCatalogue::all());
        foreach (['director', 'ca_supervisor'] as $role) {
            $this->assertContains(MedicineAdministrationService::PERMISSION, $roles[$role]);
        }
        foreach (['resident_doctor', 'ca', 'panel_officer', 'finance_officer', 'business_development', 'marketing', 'hr_manager', 'technical_admin'] as $role) {
            $this->assertNotContains(MedicineAdministrationService::PERMISSION, $roles[$role]);
        }
    }

    public function test_create_normalizes_values_attributes_actor_and_audits_safe_fields(): void
    {
        $director = $this->actor('director');
        $medicine = $this->service()->create($director, [
            'code' => '  para500  ',
            'display_name' => '  Synthetic   Paracetamol  ',
            'strength_text' => ' 500 mg ',
            'dosage_form' => ' tablet ',
            'order_unit' => ' tablet ',
        ]);

        $this->assertSame('PARA500', $medicine->code);
        $this->assertSame('Synthetic Paracetamol', $medicine->display_name);
        $this->assertSame('500 mg', $medicine->strength_text);
        $this->assertSame('tablet', $medicine->dosage_form);
        $this->assertSame('tablet', $medicine->order_unit);
        $this->assertSame(MedicineCatalogueItem::AUTHORISATION_DOCTOR_REQUIRED, $medicine->authorisation_class);
        $this->assertTrue($medicine->is_active);
        $this->assertSame($director->id, $medicine->created_by_user_id);
        $this->assertSame($director->id, $medicine->updated_by_user_id);

        $audit = AuditLog::query()->where('event', 'medicine_catalogue.created')->sole();
        $this->assertSame($director->id, $audit->actor_user_id);
        $this->assertSame($director->organisation_id, $audit->organisation_id);
        $this->assertSame($medicine->id, $audit->subject_id);
        $this->assertEqualsCanonicalizing(
            ['code', 'display_name', 'strength_text', 'dosage_form', 'order_unit', 'authorisation_class', 'is_active'],
            $audit->metadata['changed_fields'],
        );
    }

    public function test_case_insensitive_duplicates_are_rejected_per_organisation_and_foreign_mutation_is_neutral(): void
    {
        $director = $this->actor('director');
        $this->service()->create($director, $this->attributes(['code' => 'PARA500']));

        try {
            $this->service()->create($director, $this->attributes(['code' => '  para500 ']));
            $this->fail('Case-insensitive duplicate code was accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('code', $exception->errors());
        }

        [$foreignDirector, $foreignMedicine] = $this->foreignMedicine('para500');
        $this->assertSame('PARA500', $foreignMedicine->code);

        $this->expectException(ModelNotFoundException::class);
        $this->service()->update($director, $foreignMedicine, ['display_name' => 'Cross tenant mutation']);
    }

    public function test_unauthorised_and_inactive_users_cannot_manage_medicines(): void
    {
        foreach (['resident_doctor', 'ca', 'panel_officer', 'finance_officer', 'technical_admin'] as $role) {
            try {
                $this->service()->create($this->actor($role), $this->attributes(['code' => 'DENY-'.$role]));
                $this->fail("{$role} managed the Medicine Catalogue.");
            } catch (AuthorizationException) {
                $this->assertDatabaseMissing('medicine_catalogue_items', ['code' => Str::upper('DENY-'.$role)]);
            }
        }

        $director = $this->actor('director');
        $director->forceFill(['is_active' => false])->save();
        $this->expectException(AuthorizationException::class);
        $this->service()->create($director->refresh(), $this->attributes(['code' => 'INACTIVE']));
    }

    public function test_unused_identity_can_change_but_pricing_dependency_locks_code_and_order_unit(): void
    {
        $director = $this->actor('director');
        $medicine = $this->service()->create($director, $this->attributes(['code' => 'OLD', 'order_unit' => 'tablet']));
        $medicine = $this->service()->update($director, $medicine, ['code' => ' corrected ', 'order_unit' => 'capsule']);
        $this->assertSame('CORRECTED', $medicine->code);
        $this->assertSame('capsule', $medicine->order_unit);

        // Existing catalogues pre-date this governance service and may retain non-canonical casing.
        $medicine->forceFill(['code' => 'corrected'])->save();

        $charge = new ChargeDefinition;
        $charge->forceFill([
            'public_id' => (string) Str::uuid(),
            'organisation_id' => $medicine->organisation_id,
            'code' => 'MED-CORRECTED',
            'type' => 'medicine',
            'display_name' => $medicine->display_name,
            'unit' => $medicine->order_unit,
            'medicine_catalogue_item_id' => $medicine->id,
            'clinical_service_catalogue_item_id' => null,
            'source_key' => 'medicine:'.$medicine->id,
            'is_active' => true,
        ])->save();

        $medicine = $this->service()->update($director, $medicine, [
            'code' => ' Corrected ',
            'display_name' => 'Synthetic governed medicine updated',
        ]);
        $this->assertSame('corrected', $medicine->code);
        $this->assertSame('Synthetic governed medicine updated', $medicine->display_name);

        foreach (['code' => 'NEW-CODE', 'order_unit' => 'box'] as $field => $value) {
            try {
                $this->service()->update($director, $medicine, [$field => $value]);
                $this->fail("Established {$field} was changed.");
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey($field, $exception->errors());
            }
        }
        $this->assertSame('corrected', $medicine->refresh()->code);
        $this->assertSame('capsule', $medicine->order_unit);
        $this->assertSame('capsule', $charge->refresh()->unit);
    }

    public function test_inventory_mapping_locks_identity_without_making_stock_a_catalogue_requirement(): void
    {
        $supervisor = $this->actor('ca_supervisor');
        $medicine = $this->service()->create($supervisor, $this->attributes(['code' => 'NO-STOCK']));
        $this->assertTrue($medicine->is_active);
        $this->assertDatabaseMissing('medicine_catalogue_inventory_skus', ['medicine_catalogue_item_id' => $medicine->id]);

        $inventoryItem = new InventoryItem;
        $inventoryItem->forceFill([
            'public_id' => (string) Str::uuid(), 'organisation_id' => $medicine->organisation_id,
            'code' => 'ITEM-MAP', 'generic_name' => 'Synthetic mapped medicine', 'is_active' => true,
        ])->save();
        $sku = new InventorySku;
        $sku->forceFill([
            'public_id' => (string) Str::uuid(), 'organisation_id' => $medicine->organisation_id,
            'inventory_item_id' => $inventoryItem->id, 'sku_code' => 'SKU-MAP', 'pack_size' => 1,
            'purchase_unit' => 'box', 'stock_unit' => 'tablet', 'dispensing_unit' => 'tablet',
            'unit_conversion' => 1, 'storage_type' => 'ambient', 'cold_chain_required' => false,
            'do_not_freeze' => false, 'protect_from_light' => false, 'batch_tracking_required' => true,
            'expiry_tracking_required' => true, 'is_active' => true,
        ])->save();
        $mapping = new MedicineCatalogueInventorySku;
        $mapping->forceFill([
            'organisation_id' => $medicine->organisation_id,
            'medicine_catalogue_item_id' => $medicine->id,
            'inventory_sku_id' => $sku->id,
            'is_active' => true,
            'approved_by_user_id' => $supervisor->id,
            'approved_at' => now()->utc(),
        ])->save();

        $this->expectException(ValidationException::class);
        $this->service()->update($supervisor, $medicine, ['order_unit' => 'box']);
    }

    public function test_descriptive_updates_preserve_treatment_snapshot_and_lifecycle_controls_search(): void
    {
        $director = $this->actor('director');
        $doctor = $this->doctor();
        $medicine = $this->service()->create($director, $this->attributes([
            'code' => 'FUTURE-MED',
            'display_name' => 'Synthetic Original Medicine',
            'strength_text' => '10 mg',
            'dosage_form' => 'tablet',
            'order_unit' => 'tablet',
        ]));

        [, , $visit, $queue] = $this->servingFixture($doctor);
        $this->startEncounter($doctor, $visit, $queue);
        $profile = app(PatientAllergyService::class)->declareNoKnown($doctor, $visit, [
            'expected_branch_id' => $visit->branch_id,
            'profile_lock_version' => null,
        ]);
        app(PatientAllergyService::class)->review($doctor, $visit, [
            'expected_branch_id' => $visit->branch_id,
            'profile_lock_version' => $profile->lock_version,
        ]);
        $plan = app(TreatmentPlanService::class)->save($doctor, $visit, [
            'expected_branch_id' => $visit->branch_id,
            'lock_version' => null,
            'medicines' => [$this->medicineOrder($medicine)],
            'services' => [],
        ]);
        $order = $plan->medicineOrders()->sole();

        $medicine = $this->service()->update($director, $medicine, [
            'display_name' => 'Synthetic Updated Medicine',
            'strength_text' => '20 mg',
            'dosage_form' => 'capsule',
        ]);
        $this->assertSame('Synthetic Original Medicine', $order->refresh()->medicine_name_snapshot);
        $this->assertSame('10 mg', $order->strength_snapshot);
        $this->assertSame('tablet', $order->dosage_form_snapshot);
        $this->assertSame('tablet', $order->unit_snapshot);

        $results = app(TreatmentPlanDirectoryService::class)->searchMedicines($doctor, $visit, [
            'expected_branch_id' => $visit->branch_id,
            'query' => 'Updated',
        ]);
        $this->assertSame('Synthetic Updated Medicine', $results[0]['displayName']);

        $this->service()->deactivate($director, $medicine);
        $this->assertSame([], app(TreatmentPlanDirectoryService::class)->searchMedicines($doctor, $visit, [
            'expected_branch_id' => $visit->branch_id,
            'query' => 'Updated',
        ]));
        $this->assertSame('Synthetic Original Medicine', $order->refresh()->medicine_name_snapshot);

        $this->service()->activate($director, $medicine);
        $this->assertCount(1, app(TreatmentPlanDirectoryService::class)->searchMedicines($doctor, $visit, [
            'expected_branch_id' => $visit->branch_id,
            'query' => 'Updated',
        ]));
        $this->assertSame($director->id, $medicine->refresh()->updated_by_user_id);
        $this->assertDatabaseHas('audit_logs', ['event' => 'medicine_catalogue.updated', 'subject_id' => $medicine->id]);
        $this->assertDatabaseHas('audit_logs', ['event' => 'medicine_catalogue.deactivated', 'subject_id' => $medicine->id]);
        $this->assertDatabaseHas('audit_logs', ['event' => 'medicine_catalogue.activated', 'subject_id' => $medicine->id]);

        try {
            $medicine->delete();
            $this->fail('Medicine Catalogue hard delete was allowed.');
        } catch (LogicException) {
            $this->assertDatabaseHas('medicine_catalogue_items', ['id' => $medicine->id]);
        }

        try {
            $this->service()->update($director, $medicine, ['authorisation_class' => 'other']);
            $this->fail('Authorisation class was changed.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('authorisation_class', $exception->errors());
        }
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function attributes(array $overrides = []): array
    {
        return [
            'code' => 'MED-'.Str::upper(Str::random(8)),
            'display_name' => 'Synthetic governed medicine',
            'strength_text' => null,
            'dosage_form' => null,
            'order_unit' => 'unit',
            ...$overrides,
        ];
    }

    /** @return array<string, mixed> */
    private function medicineOrder(MedicineCatalogueItem $medicine): array
    {
        return [
            'public_id' => null,
            'catalogue_public_id' => $medicine->public_id,
            'quantity_ordered' => '1.000',
            'dosage' => 'One unit',
            'frequency' => 'Once daily',
            'duration' => null,
            'route' => null,
            'administration_instruction' => null,
            'indication' => null,
            'precaution' => null,
        ];
    }

    /** @return array{User, MedicineCatalogueItem} */
    private function foreignMedicine(string $code): array
    {
        $organisation = new Organisation;
        $organisation->forceFill([
            'code' => 'FOREIGN-'.Str::upper(Str::random(6)),
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
        $director = $this->actor('director', $branch);

        return [$director, $this->service()->create($director, $this->attributes(['code' => $code]))];
    }

    private function service(): MedicineAdministrationService
    {
        return app(MedicineAdministrationService::class);
    }
}
