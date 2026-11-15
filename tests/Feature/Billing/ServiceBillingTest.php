<?php

namespace Tests\Feature\Billing;

use App\Domain\Clinical\Models\ClinicalServiceCatalogueItem;
use App\Domain\Clinical\Models\TreatmentPlan;
use App\Domain\Clinical\Models\TreatmentPlanServiceOrder;
use App\Domain\Clinical\Services\CompleteConsultationService;
use App\Domain\Clinical\Services\ReopenConsultationCheckoutService;
use App\Domain\Visit\Billing\Models\ChargeDefinition;
use App\Domain\Visit\Billing\Models\Invoice;
use App\Domain\Visit\Billing\Models\InvoiceLine;
use App\Domain\Visit\Billing\Models\PriceEntry;
use App\Domain\Visit\Billing\Services\BillingBuilderService;
use Illuminate\Support\Str;

class ServiceBillingTest extends BillingTestCase
{
    public function test_performed_service_quantity_is_billed_with_exact_rounding(): void
    {
        $invoice = $this->serviceInvoice('performed');
        $this->assertSame(4152, $invoice->total_sen);
        $line = InvoiceLine::query()->where('line_type', 'service')->sole();
        $this->assertSame('1.500', $line->quantity);
        $this->assertSame(152, $line->line_total_sen);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_not_performed_service_does_not_require_price_or_create_line(): void
    {
        $invoice = $this->serviceInvoice('not_performed');
        $this->assertSame(4000, $invoice->total_sen);
        $this->assertDatabaseCount('invoice_lines', 1);
        $this->assertDatabaseCount('dispensary_cases', 0);
    }

    private function serviceInvoice(string $disposition): Invoice
    {
        [$doctor, $ca, $visit, $queue, , $book] = $this->billingFixture();
        $this->selectBranch($doctor, $visit->branch);
        app(ReopenConsultationCheckoutService::class)->reopen($doctor, $visit, ['expected_branch_id' => $visit->branch_id, 'visit_lock_version' => $visit->lock_version, 'checkout_lock_version' => 1]);
        $encounter = $visit->clinicalEncounter;
        $plan = TreatmentPlan::factory()->create(['organisation_id' => $visit->organisation_id, 'branch_id' => $visit->branch_id, 'clinical_encounter_id' => $encounter->id, 'created_by_user_id' => $doctor->id, 'updated_by_user_id' => $doctor->id]);
        $catalogue = ClinicalServiceCatalogueItem::factory()->create(['organisation_id' => $visit->organisation_id]);
        $order = TreatmentPlanServiceOrder::factory()->create(['clinical_service_catalogue_item_id' => $catalogue->id, 'organisation_id' => $visit->organisation_id, 'branch_id' => $visit->branch_id, 'treatment_plan_id' => $plan->id, 'quantity_ordered' => '2.000', 'recorded_by_user_id' => $doctor->id, 'updated_by_user_id' => $doctor->id]);
        if ($disposition === 'performed') {
            $charge = new ChargeDefinition;
            $charge->forceFill(['public_id' => (string) Str::uuid(), 'organisation_id' => $visit->organisation_id, 'code' => 'UAT-SERVICE', 'type' => 'service', 'display_name' => 'Synthetic service', 'unit' => $order->unit_snapshot, 'source_key' => 'service:'.$catalogue->id, 'clinical_service_catalogue_item_id' => $catalogue->id])->save();
            $price = new PriceEntry;
            $price->forceFill(['organisation_id' => $visit->organisation_id, 'price_book_id' => $book->id, 'charge_definition_id' => $charge->id, 'version' => 1, 'unit_price_sen' => 101, 'effective_at' => now()->subMinute(), 'published_by_user_id' => $doctor->id])->save();
        }
        app(CompleteConsultationService::class)->complete($doctor, $visit, ['expected_branch_id' => $visit->branch_id, 'visit_lock_version' => $visit->lock_version, 'queue_lock_version' => $queue->refresh()->lock_version, 'encounter_lock_version' => $encounter->lock_version, 'lock_version' => $plan->lock_version, 'service_deliveries' => [['order_public_id' => $order->public_id, 'disposition' => $disposition, 'quantity_performed' => $disposition === 'performed' ? '1.5' : '0']]]);
        $this->selectBranch($ca, $visit->branch);
        $invoice = app(BillingBuilderService::class)->build($ca, $visit, ['expected_branch_id' => $visit->branch_id, 'lock_version' => null]);

        return app(BillingBuilderService::class)->finalize($ca, $visit, $invoice, ['expected_branch_id' => $visit->branch_id, 'lock_version' => $invoice->lock_version]);
    }
}
