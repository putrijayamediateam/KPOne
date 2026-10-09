<?php

namespace Tests\Support;

use App\Domain\Clinical\Dispensary\Models\DispensaryCase;
use App\Domain\Clinical\Dispensary\Services\DispensaryService;
use App\Domain\Clinical\Dispensary\Services\OtcDispensaryService;
use App\Domain\Clinical\Models\MedicineCatalogueItem;
use App\Domain\Organisation\Inventory\Models\InventoryBatch;
use App\Domain\Organisation\Inventory\Models\InventoryItem;
use App\Domain\Organisation\Inventory\Models\InventoryLocation;
use App\Domain\Organisation\Inventory\Models\InventorySku;
use App\Domain\Organisation\Inventory\Models\MedicineCatalogueInventorySku;
use App\Domain\Organisation\Inventory\Services\InventoryMovementService;
use App\Domain\Visit\Models\Visit;
use App\Models\User;
use Illuminate\Support\Str;

/** Synthetic OTC visit with a stocked medicine, for tests extending Tests\Feature\Visit\VisitTestCase. */
trait OtcDispensaryFixtures
{
    /** @return array{ca: User, visit: Visit, medicine: MedicineCatalogueItem, location: InventoryLocation, sku: InventorySku, batch: InventoryBatch} */
    protected function fixture(string $visitType = 'otc'): array
    {
        $ca = $this->actor('ca');
        $supervisor = $this->actor('ca_supervisor');
        $visit = $this->register($ca, $this->patient(), $visitType === 'otc' ? [] : ['visit_type' => 'consultation', 'assigned_doctor_user_id' => $this->actor('resident_doctor')->id, 'visit_reason' => 'Synthetic reason']);
        $organisation = $ca->organisation_id;

        $medicine = new MedicineCatalogueItem;
        $medicine->forceFill([
            'public_id' => (string) Str::uuid(), 'organisation_id' => $organisation, 'code' => 'SYN-MED-'.Str::upper(Str::random(8)),
            'display_name' => 'Synthetic OTC medicine', 'strength_text' => 'Synthetic strength', 'dosage_form' => 'Synthetic form', 'order_unit' => 'unit',
            'authorisation_class' => MedicineCatalogueItem::AUTHORISATION_DOCTOR_REQUIRED, 'is_active' => true,
            'created_by_user_id' => $supervisor->id, 'updated_by_user_id' => $supervisor->id,
        ])->save();
        $inventoryItem = new InventoryItem;
        $inventoryItem->forceFill(['public_id' => (string) Str::uuid(), 'organisation_id' => $organisation, 'code' => 'SYN-ITEM-'.Str::upper(Str::random(6)), 'generic_name' => 'Synthetic stock item', 'is_active' => true])->save();
        $sku = new InventorySku;
        $sku->forceFill(['public_id' => (string) Str::uuid(), 'organisation_id' => $organisation, 'inventory_item_id' => $inventoryItem->id, 'sku_code' => 'SYN-SKU-'.Str::upper(Str::random(6)), 'pack_size' => 1, 'purchase_unit' => 'unit', 'stock_unit' => 'unit', 'dispensing_unit' => 'unit', 'unit_conversion' => 1, 'storage_type' => 'ambient', 'cold_chain_required' => false, 'do_not_freeze' => false, 'protect_from_light' => false, 'batch_tracking_required' => true, 'expiry_tracking_required' => true, 'is_active' => true])->save();
        (new MedicineCatalogueInventorySku)->forceFill(['organisation_id' => $organisation, 'medicine_catalogue_item_id' => $medicine->id, 'inventory_sku_id' => $sku->id, 'is_active' => true, 'approved_by_user_id' => $supervisor->id, 'approved_at' => now()->utc()])->save();
        $location = new InventoryLocation;
        $location->forceFill(['public_id' => (string) Str::uuid(), 'organisation_id' => $organisation, 'branch_id' => $visit->branch_id, 'code' => 'SYN-DISP-'.Str::upper(Str::random(5)), 'name' => 'Synthetic Dispensary', 'type' => InventoryLocation::TYPE_DISPENSARY, 'is_active' => true])->save();
        $batch = new InventoryBatch;
        $batch->forceFill(['public_id' => (string) Str::uuid(), 'organisation_id' => $organisation, 'inventory_sku_id' => $sku->id, 'batch_number' => 'SYN-BATCH-'.Str::upper(Str::random(5)), 'expiry_date' => now()->setTimezone($visit->branch->timezone)->addMonth()->toDateString(), 'received_at' => now()->subDay()->toDateString(), 'status' => InventoryBatch::STATUS_AVAILABLE])->save();
        $this->selectBranch($supervisor, $visit->branch);
        app(InventoryMovementService::class)->openingBalance($supervisor, ['expected_branch_id' => $visit->branch_id, 'location_public_id' => $location->public_id, 'sku_public_id' => $sku->public_id, 'batch_public_id' => $batch->public_id, 'quantity' => '10.000']);
        $this->selectBranch($ca, $visit->branch);

        return compact('ca', 'visit', 'medicine', 'location', 'sku', 'batch');
    }

    /** @param array<string, mixed> $f */
    protected function open(array $f): DispensaryCase
    {
        return app(OtcDispensaryService::class)->open($f['ca'], $f['visit'], ['expected_branch_id' => $f['visit']->branch_id]);
    }

    /** @param array<string, mixed> $f @return array<string, mixed> */
    protected function addPayload(array $f, DispensaryCase $case, string $quantity = '2.000', array $override = []): array
    {
        return [
            'expected_branch_id' => $f['visit']->branch_id, 'case_lock_version' => $case->refresh()->lock_version,
            'medicine_public_id' => $f['medicine']->public_id, 'quantity_dispensed' => $quantity,
            'dosage' => 'One tablet', 'frequency' => 'Twice daily', 'duration' => '3 days', 'route' => 'Oral',
            'administration_instruction' => 'After food', 'precaution' => null,
            'allocations' => [['location_public_id' => $f['location']->public_id, 'sku_public_id' => $f['sku']->public_id, 'batch_public_id' => $f['batch']->public_id, 'quantity' => $quantity]],
            ...$override,
        ];
    }

    /** @param array<string, mixed> $f */
    protected function confirmAllergy(array $f, DispensaryCase $case, string $statement = 'none'): void
    {
        app(DispensaryService::class)->confirmOtcAllergy($f['ca'], $case->refresh(), ['expected_branch_id' => $f['visit']->branch_id, 'case_lock_version' => $case->lock_version, 'allergy_statement' => $statement]);
    }

    /** @param array<string, mixed> $f */
    protected function complete(array $f, DispensaryCase $case): DispensaryCase
    {
        return app(DispensaryService::class)->complete($f['ca'], $case->refresh(), ['expected_branch_id' => $f['visit']->branch_id, 'case_lock_version' => $case->lock_version]);
    }
}
