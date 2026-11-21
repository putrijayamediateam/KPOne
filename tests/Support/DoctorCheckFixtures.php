<?php

namespace Tests\Support;

use App\Domain\Clinical\Dispensary\Models\DispensaryHandoff;
use App\Domain\Clinical\Dispensary\Services\DispensaryHandoffService;
use App\Domain\Clinical\Dispensary\Services\DispensaryService;
use App\Domain\Clinical\Models\MedicineCatalogueItem;
use App\Domain\Clinical\Services\PatientAllergyService;
use App\Domain\Clinical\Services\TreatmentPlanService;
use App\Domain\Organisation\Inventory\Models\InventoryBatch;
use App\Domain\Organisation\Inventory\Models\InventoryItem;
use App\Domain\Organisation\Inventory\Models\InventoryLocation;
use App\Domain\Organisation\Inventory\Models\InventorySku;
use App\Domain\Organisation\Inventory\Models\MedicineCatalogueInventorySku;
use App\Domain\Organisation\Inventory\Services\InventoryMovementService;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Organisation\Models\BranchDispensarySetting;
use Illuminate\Support\Str;

/**
 * DSP-23: a consultation Dispensary case the CA has prepared (one medicine line, dispensed in full from one batch),
 * ready for Complete or Send to doctor. Used by the feature tests and the PostgreSQL race tests. Synthetic data only.
 * The using class provides servingFixture(), startEncounter(), actor() and selectBranch() (ClinicalTestCase).
 */
trait DoctorCheckFixtures
{
    /**
     * A consultation case the CA has prepared (one line, dispensed in full, one batch). Pass `reuse` to prepare a
     * second case from the same medicine, location and batch (the race tests).
     *
     * @param  array{medicine?: MedicineCatalogueItem, sku?: InventorySku, location?: InventoryLocation, batch?: InventoryBatch, opening?: string}  $reuse
     * @return array<string, mixed>
     */
    protected function prepared(array $reuse = []): array
    {
        [$doctor, $ca, $visit, $queue] = $this->servingFixture();
        $this->startEncounter($doctor, $visit, $queue);
        $allergies = app(PatientAllergyService::class);
        $profile = $allergies->declareNoKnown($doctor, $visit, ['expected_branch_id' => $visit->branch_id, 'profile_lock_version' => null]);
        $allergies->review($doctor, $visit, ['expected_branch_id' => $visit->branch_id, 'profile_lock_version' => $profile->lock_version]);
        $medicine = $reuse['medicine'] ?? new MedicineCatalogueItem;
        $medicine->exists || $medicine->forceFill([
            'public_id' => (string) Str::uuid(), 'organisation_id' => $doctor->organisation_id,
            'code' => 'SYN-MED-'.Str::upper(Str::random(8)), 'display_name' => 'Synthetic medicine', 'strength_text' => 'Synthetic strength',
            'dosage_form' => 'Synthetic form', 'order_unit' => 'unit', 'authorisation_class' => MedicineCatalogueItem::AUTHORISATION_DOCTOR_REQUIRED,
            'is_active' => true, 'created_by_user_id' => $doctor->id, 'updated_by_user_id' => $doctor->id,
        ])->save();
        $plan = app(TreatmentPlanService::class)->save($doctor, $visit, ['expected_branch_id' => $visit->branch_id, 'lock_version' => null, 'services' => [], 'medicines' => [[
            'public_id' => null, 'catalogue_public_id' => $medicine->public_id, 'quantity_ordered' => 2, 'dosage' => 'Synthetic dosage', 'frequency' => 'Synthetic frequency',
            'duration' => null, 'route' => null, 'administration_instruction' => null, 'indication' => null, 'precaution' => null,
        ]]]);
        $case = app(DispensaryHandoffService::class)->send($doctor, $visit, ['expected_branch_id' => $visit->branch_id, 'lock_version' => $plan->lock_version, 'service_deliveries' => []]);

        if (isset($reuse['sku'], $reuse['location'], $reuse['batch'])) {
            [$sku, $location, $batch] = [$reuse['sku'], $reuse['location'], $reuse['batch']];
            $supervisor = $this->actor('ca_supervisor');
        } else {
            $stockItem = new InventoryItem;
            $stockItem->forceFill(['public_id' => (string) Str::uuid(), 'organisation_id' => $ca->organisation_id, 'code' => 'SYN-ITEM-'.Str::upper(Str::random(6)), 'generic_name' => 'Synthetic stock item', 'is_active' => true])->save();
            $sku = new InventorySku;
            $sku->forceFill(['public_id' => (string) Str::uuid(), 'organisation_id' => $ca->organisation_id, 'inventory_item_id' => $stockItem->id, 'sku_code' => 'SYN-SKU-'.Str::upper(Str::random(6)), 'pack_size' => 1, 'purchase_unit' => 'unit', 'stock_unit' => 'unit', 'dispensing_unit' => 'unit', 'unit_conversion' => 1, 'storage_type' => 'ambient', 'cold_chain_required' => false, 'do_not_freeze' => false, 'protect_from_light' => false, 'batch_tracking_required' => true, 'expiry_tracking_required' => true, 'is_active' => true])->save();
            (new MedicineCatalogueInventorySku)->forceFill(['organisation_id' => $ca->organisation_id, 'medicine_catalogue_item_id' => $medicine->id, 'inventory_sku_id' => $sku->id, 'is_active' => true, 'approved_by_user_id' => $doctor->id, 'approved_at' => now()->utc()])->save();
            $location = new InventoryLocation;
            $location->forceFill(['public_id' => (string) Str::uuid(), 'organisation_id' => $ca->organisation_id, 'branch_id' => $visit->branch_id, 'code' => 'SYN-DISP-'.Str::upper(Str::random(5)), 'name' => 'Synthetic Dispensary', 'type' => InventoryLocation::TYPE_DISPENSARY, 'is_active' => true])->save();
            $batch = new InventoryBatch;
            $batch->forceFill(['public_id' => (string) Str::uuid(), 'organisation_id' => $ca->organisation_id, 'inventory_sku_id' => $sku->id, 'batch_number' => 'SYN-BATCH-'.Str::upper(Str::random(5)), 'expiry_date' => now()->setTimezone($visit->branch->timezone)->addMonth()->toDateString(), 'received_at' => now()->subDay()->toDateString(), 'status' => InventoryBatch::STATUS_AVAILABLE])->save();
            $supervisor = $this->actor('ca_supervisor');
            $this->selectBranch($supervisor, $visit->branch);
            app(InventoryMovementService::class)->openingBalance($supervisor, ['expected_branch_id' => $visit->branch_id, 'location_public_id' => $location->public_id, 'sku_public_id' => $sku->public_id, 'batch_public_id' => $batch->public_id, 'quantity' => $reuse['opening'] ?? '10.000']);
        }

        $this->selectBranch($ca, $visit->branch);
        $case = app(DispensaryService::class)->start($ca, $case, ['expected_branch_id' => $visit->branch_id, 'case_lock_version' => $case->lock_version]);
        $item = $case->handoffs()->where('status', DispensaryHandoff::STATUS_OPEN)->sole()->items()->sole();
        app(DispensaryService::class)->updateItem($ca, $case, $item, [
            'expected_branch_id' => $visit->branch_id, 'case_lock_version' => $case->lock_version, 'item_lock_version' => $item->lock_version,
            'status' => 'dispensed', 'quantity_dispensed' => '2.000', 'reason' => null,
            'allocations' => [['location_public_id' => $location->public_id, 'sku_public_id' => $sku->public_id, 'batch_public_id' => $batch->public_id, 'quantity' => '2.000']],
        ]);

        return ['doctor' => $doctor, 'ca' => $ca, 'supervisor' => $supervisor, 'visit' => $visit->refresh(), 'case' => $case->refresh(), 'batch' => $batch, 'location' => $location, 'sku' => $sku, 'medicine' => $medicine];
    }

    protected function switchOn(Branch $branch, bool $on = true): void
    {
        $row = BranchDispensarySetting::query()->where('branch_id', $branch->id)->first() ?? new BranchDispensarySetting;
        $row->forceFill(['organisation_id' => $branch->organisation_id, 'branch_id' => $branch->id, 'doctor_check_required' => $on, 'lock_version' => $row->exists ? $row->lock_version + 1 : 1])->save();
    }
}
