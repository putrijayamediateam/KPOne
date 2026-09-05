<?php

namespace Tests\Feature\Billing;

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
use App\Domain\Organisation\Inventory\Models\InventoryStockBalance;
use App\Domain\Organisation\Inventory\Models\MedicineCatalogueInventorySku;
use App\Domain\Organisation\Inventory\Services\InventoryMovementService;
use App\Domain\Patient\Models\Patient;
use App\Domain\Patient\Services\PatientNumberGenerator;
use App\Domain\Visit\Billing\Models\ChargeDefinition;
use App\Domain\Visit\Billing\Models\InvoiceLine;
use App\Domain\Visit\Billing\Models\PaymentMethod;
use App\Domain\Visit\Billing\Models\PriceBook;
use App\Domain\Visit\Billing\Models\PriceEntry;
use App\Domain\Visit\Billing\Services\BillingBuilderService;
use App\Domain\Visit\Billing\Services\CompleteVisitationService;
use App\Domain\Visit\Billing\Services\PaymentService;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class LegacyPatientWorkflowTest extends BillingTestCase
{
    public function test_legacy_patient_completes_medicine_fulfilment_without_backfill_or_duplicate_stock_deduction(): void
    {
        // servingFixture registers the pre-existing synthetic patient from our override.
        [$doctor, $ca, $visit, $queue] = $this->servingFixture();
        $patient = $visit->patient;
        $this->assertNull($patient->mobile_phone);
        $this->assertSame(0, $patient->identifiers()->count());
        $this->startEncounter($doctor, $visit, $queue);
        $allergies = app(PatientAllergyService::class);
        $profile = $allergies->declareNoKnown($doctor, $visit, ['expected_branch_id' => $visit->branch_id, 'profile_lock_version' => null]);
        $allergies->review($doctor, $visit, ['expected_branch_id' => $visit->branch_id, 'profile_lock_version' => $profile->lock_version]);
        $medicine = new MedicineCatalogueItem;
        $medicine->forceFill(['public_id' => (string) Str::uuid(), 'organisation_id' => $doctor->organisation_id, 'code' => 'SYN-LEGACY-MED', 'display_name' => 'Synthetic legacy medicine', 'strength_text' => 'Synthetic strength', 'dosage_form' => 'Synthetic form', 'order_unit' => 'unit', 'authorisation_class' => MedicineCatalogueItem::AUTHORISATION_DOCTOR_REQUIRED, 'is_active' => true, 'created_by_user_id' => $doctor->id, 'updated_by_user_id' => $doctor->id])->save();
        $plan = app(TreatmentPlanService::class)->save($doctor, $visit, ['expected_branch_id' => $visit->branch_id, 'lock_version' => null, 'medicines' => [['public_id' => null, 'catalogue_public_id' => $medicine->public_id, 'quantity_ordered' => 1, 'dosage' => 'Synthetic dosage', 'frequency' => 'Synthetic frequency', 'duration' => null, 'route' => null, 'administration_instruction' => null, 'indication' => null, 'precaution' => null]], 'services' => []]);
        $case = app(DispensaryHandoffService::class)->send($doctor, $visit, ['expected_branch_id' => $visit->branch_id, 'lock_version' => $plan->lock_version, 'service_deliveries' => []]);
        $this->assertDatabaseCount('stock_movements', 0);

        $inventoryItem = new InventoryItem;
        $inventoryItem->forceFill(['public_id' => (string) Str::uuid(), 'organisation_id' => $ca->organisation_id, 'code' => 'SYN-LEGACY-ITEM', 'generic_name' => 'Synthetic stock item', 'is_active' => true])->save();
        $sku = new InventorySku;
        $sku->forceFill(['public_id' => (string) Str::uuid(), 'organisation_id' => $ca->organisation_id, 'inventory_item_id' => $inventoryItem->id, 'sku_code' => 'SYN-LEGACY-SKU', 'pack_size' => 1, 'purchase_unit' => 'unit', 'stock_unit' => 'unit', 'dispensing_unit' => 'unit', 'unit_conversion' => 1, 'storage_type' => 'ambient', 'cold_chain_required' => false, 'do_not_freeze' => false, 'protect_from_light' => false, 'batch_tracking_required' => true, 'expiry_tracking_required' => true, 'is_active' => true])->save();
        $mapping = new MedicineCatalogueInventorySku;
        $mapping->forceFill(['organisation_id' => $ca->organisation_id, 'medicine_catalogue_item_id' => $medicine->id, 'inventory_sku_id' => $sku->id, 'is_active' => true, 'approved_by_user_id' => $doctor->id, 'approved_at' => now()->utc()])->save();
        $location = new InventoryLocation;
        $location->forceFill(['public_id' => (string) Str::uuid(), 'organisation_id' => $ca->organisation_id, 'branch_id' => $visit->branch_id, 'code' => 'SYN-LEGACY-DISP', 'name' => 'Synthetic Dispensary', 'type' => InventoryLocation::TYPE_DISPENSARY, 'is_active' => true])->save();
        $batch = new InventoryBatch;
        $batch->forceFill(['public_id' => (string) Str::uuid(), 'organisation_id' => $ca->organisation_id, 'inventory_sku_id' => $sku->id, 'batch_number' => 'SYN-LEGACY-BATCH', 'expiry_date' => now()->setTimezone($visit->branch->timezone)->addMonth()->toDateString(), 'received_at' => now()->subDay()->toDateString(), 'status' => InventoryBatch::STATUS_AVAILABLE])->save();
        $supervisor = $this->actor('ca_supervisor');
        $this->selectBranch($supervisor, $visit->branch);
        app(InventoryMovementService::class)->openingBalance($supervisor, ['expected_branch_id' => $visit->branch_id, 'location_public_id' => $location->public_id, 'sku_public_id' => $sku->public_id, 'batch_public_id' => $batch->public_id, 'quantity' => '10.000']);
        $balance = InventoryStockBalance::query()->where('inventory_location_id', $location->id)->sole();
        $this->selectBranch($ca, $visit->branch);
        $dispensary = app(DispensaryService::class);
        $case = $dispensary->start($ca, $case, ['expected_branch_id' => $visit->branch_id, 'case_lock_version' => $case->lock_version]);
        $item = $case->handoffs()->where('status', DispensaryHandoff::STATUS_OPEN)->sole()->items()->sole();
        $dispensary->updateItem($ca, $case, $item, ['expected_branch_id' => $visit->branch_id, 'case_lock_version' => $case->lock_version, 'item_lock_version' => $item->lock_version, 'status' => 'dispensed', 'quantity_dispensed' => '1.000', 'allocations' => [['location_public_id' => $location->public_id, 'sku_public_id' => $sku->public_id, 'batch_public_id' => $batch->public_id, 'quantity' => '1.000']]]);
        $this->assertSame('10.000', $balance->refresh()->quantity);
        $this->assertSame(0, DB::table('stock_movements')->where('movement_type', 'dispense')->count());
        $dispensary->complete($ca, $case->refresh(), ['expected_branch_id' => $visit->branch_id, 'case_lock_version' => $case->lock_version]);
        $this->assertSame('9.000', $balance->refresh()->quantity);
        $this->assertSame(1, DB::table('stock_movements')->where('movement_type', 'dispense')->count());
        $movementCount = DB::table('stock_movements')->count();

        $book = new PriceBook;
        $book->forceFill(['public_id' => (string) Str::uuid(), 'organisation_id' => $ca->organisation_id, 'scope_key' => 'organisation', 'name' => 'Synthetic legacy prices', 'currency' => 'MYR'])->save();
        foreach (['consultation', 'medicine'] as $type) {
            $charge = new ChargeDefinition;
            $charge->forceFill(['public_id' => (string) Str::uuid(), 'organisation_id' => $ca->organisation_id, 'type' => $type, 'code' => 'SYN-LEGACY-'.$type, 'display_name' => 'Synthetic '.$type, 'source_key' => $type === 'consultation' ? 'consultation' : 'medicine:'.$medicine->id, 'medicine_catalogue_item_id' => $type === 'medicine' ? $medicine->id : null, 'unit' => $type === 'consultation' ? 'consultation' : 'unit'])->save();
            $price = new PriceEntry;
            $price->forceFill(['organisation_id' => $ca->organisation_id, 'price_book_id' => $book->id, 'charge_definition_id' => $charge->id, 'version' => 1, 'unit_price_sen' => $type === 'consultation' ? 4000 : 100, 'effective_at' => now()->subMinute(), 'published_by_user_id' => $ca->id])->save();
        }
        $builder = app(BillingBuilderService::class);
        $invoice = $builder->build($ca, $visit->refresh(), ['expected_branch_id' => $visit->branch_id, 'lock_version' => null]);
        $invoice = $builder->finalize($ca, $visit, $invoice, ['expected_branch_id' => $visit->branch_id, 'lock_version' => $invoice->lock_version]);
        $this->assertSame('1.000', InvoiceLine::query()->where('invoice_id', $invoice->id)->where('line_type', 'medicine')->sole()->quantity);
        $this->assertSame(4100, $invoice->total_sen);
        $this->assertSame($movementCount, DB::table('stock_movements')->count());
        $this->assertSame('9.000', $balance->refresh()->quantity);
        $method = new PaymentMethod;
        $method->forceFill(['organisation_id' => $visit->organisation_id, 'code' => 'cash', 'name' => 'Cash'])->save();
        app(PaymentService::class)->add($ca, $visit, $invoice, ['expected_branch_id' => $visit->branch_id, 'lock_version' => $invoice->lock_version, 'amount_sen' => '4100', 'method' => 'cash', 'idempotency_key' => (string) Str::uuid()]);
        app(CompleteVisitationService::class)->complete($ca, $visit->refresh(), ['expected_branch_id' => $visit->branch_id, 'visit_lock_version' => $visit->lock_version, 'lock_version' => $invoice->refresh()->lock_version]);
        $this->assertSame('completed', $visit->refresh()->status);
        $this->assertNull($patient->refresh()->mobile_phone);
        $this->assertSame(0, $patient->identifiers()->count());
        $this->assertDatabaseCount('payments', 1);
        $this->assertSame($movementCount, DB::table('stock_movements')->count());
        $this->assertSame('9.000', $balance->refresh()->quantity);
    }

    protected function patient(?User $actor = null, array $overrides = []): Patient
    {
        $actor ??= $this->actor('director');
        // A synthetic pre-R1 record: intentionally no phone or identifiers.
        $patient = new Patient;
        $patient->forceFill(['organisation_id' => $actor->organisation_id, 'patient_number' => app(PatientNumberGenerator::class)->next($actor->organisation), 'full_name' => 'Synthetic Legacy Patient', 'search_name' => 'synthetic legacy patient', 'sex' => 'unknown', 'lock_version' => 1, 'created_by_user_id' => $actor->id, 'updated_by_user_id' => $actor->id, ...$overrides])->save();

        return $patient;
    }

    public function test_legacy_patient_registers_consults_checks_out_pays_and_completes_without_contact_backfill(): void
    {
        [, $ca, $visit, $invoice] = $this->finalizedFixture();
        $method = new PaymentMethod;
        $method->forceFill(['organisation_id' => $visit->organisation_id, 'code' => 'cash', 'name' => 'Cash'])->save();
        app(PaymentService::class)->add($ca, $visit, $invoice, ['expected_branch_id' => $visit->branch_id, 'lock_version' => $invoice->lock_version, 'amount_sen' => '4000', 'method' => 'cash', 'idempotency_key' => (string) Str::uuid()]);
        app(CompleteVisitationService::class)->complete($ca, $visit, ['expected_branch_id' => $visit->branch_id, 'visit_lock_version' => $visit->lock_version, 'lock_version' => $invoice->refresh()->lock_version]);
        $this->assertSame('completed', $visit->refresh()->status);
        $this->assertNull($visit->patient->mobile_phone);
        $this->assertSame(0, $visit->patient->identifiers()->count());
        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseCount('stock_movements', 0);
    }
}
