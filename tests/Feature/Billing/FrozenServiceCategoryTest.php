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
use App\Domain\Visit\Billing\Models\IssuedBillingDocumentPrint;
use App\Domain\Visit\Billing\Models\PriceEntry;
use App\Domain\Visit\Billing\Services\BillingBuilderService;
use App\Domain\Visit\Models\Visit;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * BIL-11 (owner decision 2026-11-07): each service line keeps its clinical service's category as it was when the
 * invoice was priced, so a later rename never moves money between the printed category rows or revises the copy.
 * Invoices finalised before BIL-11 (no frozen category) keep reading the live category.
 */
class FrozenServiceCategoryTest extends BillingTestCase
{
    public function test_a_rename_after_finalisation_moves_nothing_and_the_reprint_stays_a_copy(): void
    {
        [$ca, $visit, $invoice, $service] = $this->serviceInvoice('Procedure(s)');
        $this->assertSame(['Procedure(s)', null], [InvoiceLine::query()->where('line_type', 'service')->sole()->service_category_snapshot,
            InvoiceLine::query()->where('line_type', 'consultation')->sole()->service_category_snapshot]);
        $this->selectBranch($ca);
        $this->post(route('billing.issue', [$visit, $invoice]), $this->input($visit))->assertRedirect();
        $this->assertSame(152, $this->categoryTotal($visit, IssuedBillingDocumentPrint::query()->latest('id')->firstOrFail(), 'Procedure'));

        $service->forceFill(['category' => 'Other service'])->save();
        $this->post(route('billing.issue', [$visit, $invoice]), $this->input($visit))->assertRedirect();
        $reprint = IssuedBillingDocumentPrint::query()->latest('id')->firstOrFail();
        $this->assertSame('copy', $reprint->label, 'A rename after finalisation never revises the invoice.');
        $this->assertSame([152, 0], [$this->categoryTotal($visit, $reprint, 'Procedure'), $this->categoryTotal($visit, $reprint, 'Service')]);
        // The frozen category is part of the finalised line: PostgreSQL refuses to change it.
        try {
            DB::transaction(function () use ($invoice): void {
                DB::table('invoice_lines')->where('invoice_id', $invoice->id)->where('line_type', 'service')->update(['service_category_snapshot' => 'Other service']);
                DB::statement('SET CONSTRAINTS invoice_lines_retained IMMEDIATE'); // The guard runs at commit; check it now.
            });
            $this->fail('A finalised line\'s frozen category was changed.');
        } catch (QueryException $e) {
            $this->assertSame('23514', $e->errorInfo[0] ?? null, $e->getMessage());
        }
    }

    public function test_an_invoice_without_a_frozen_category_keeps_reading_the_live_one(): void
    {
        // As an invoice finalised before BIL-11: its service line has no frozen category.
        [$ca, $visit, $invoice, $service] = $this->serviceInvoice('Procedure(s)', freeze: false);
        $this->assertNull(InvoiceLine::query()->where('line_type', 'service')->sole()->service_category_snapshot);
        $this->selectBranch($ca);
        $service->forceFill(['category' => 'Investigation(s)'])->save();
        $this->post(route('billing.issue', [$visit, $invoice]), $this->input($visit))->assertRedirect();
        $this->assertSame(152, $this->categoryTotal($visit, IssuedBillingDocumentPrint::query()->latest('id')->firstOrFail(), 'Investigation'));
    }

    public function test_a_service_with_no_category_freezes_as_service_and_a_rename_before_finalisation_makes_the_draft_stale(): void
    {
        [$ca, $visit, $draft, $service] = $this->serviceInvoice(null, finalize: false);
        $this->assertSame('', InvoiceLine::query()->where('line_type', 'service')->sole()->service_category_snapshot);
        $service->forceFill(['category' => 'Procedure(s)'])->save();
        $this->selectBranch($ca);
        try {
            app(BillingBuilderService::class)->finalize($ca, $visit, $draft, ['expected_branch_id' => $visit->branch_id, 'lock_version' => $draft->lock_version]);
            $this->fail('A draft priced before a category rename was finalised.');
        } catch (ValidationException) {
            $this->assertSame('draft', $draft->refresh()->status);
        }
        $rebuilt = app(BillingBuilderService::class)->build($ca, $visit, ['expected_branch_id' => $visit->branch_id, 'lock_version' => $draft->lock_version]);
        app(BillingBuilderService::class)->finalize($ca, $visit, $rebuilt, ['expected_branch_id' => $visit->branch_id, 'lock_version' => $rebuilt->lock_version]);
        $this->assertSame('Procedure(s)', InvoiceLine::query()->where('line_type', 'service')->sole()->service_category_snapshot);
    }

    /** @return array{0: User, 1: Visit, 2: Invoice, 3: ClinicalServiceCatalogueItem} */
    private function serviceInvoice(?string $category, bool $freeze = true, bool $finalize = true): array
    {
        [$doctor, $ca, $visit, $queue, , $book] = $this->billingFixture();
        $this->selectBranch($doctor, $visit->branch);
        app(ReopenConsultationCheckoutService::class)->reopen($doctor, $visit, ['expected_branch_id' => $visit->branch_id, 'visit_lock_version' => $visit->lock_version, 'checkout_lock_version' => 1]);
        $encounter = $visit->clinicalEncounter;
        $plan = TreatmentPlan::factory()->create(['organisation_id' => $visit->organisation_id, 'branch_id' => $visit->branch_id, 'clinical_encounter_id' => $encounter->id, 'created_by_user_id' => $doctor->id, 'updated_by_user_id' => $doctor->id]);
        $service = ClinicalServiceCatalogueItem::factory()->create(['organisation_id' => $visit->organisation_id, 'category' => $category]);
        $order = TreatmentPlanServiceOrder::factory()->create(['clinical_service_catalogue_item_id' => $service->id, 'organisation_id' => $visit->organisation_id, 'branch_id' => $visit->branch_id, 'treatment_plan_id' => $plan->id, 'quantity_ordered' => '2.000', 'recorded_by_user_id' => $doctor->id, 'updated_by_user_id' => $doctor->id]);
        $charge = new ChargeDefinition;
        $charge->forceFill(['public_id' => (string) Str::uuid(), 'organisation_id' => $visit->organisation_id, 'code' => 'BIL11-SERVICE', 'type' => 'service', 'display_name' => 'Synthetic service', 'unit' => $order->unit_snapshot, 'source_key' => 'service:'.$service->id, 'clinical_service_catalogue_item_id' => $service->id])->save();
        $price = new PriceEntry;
        $price->forceFill(['organisation_id' => $visit->organisation_id, 'price_book_id' => $book->id, 'charge_definition_id' => $charge->id, 'version' => 1, 'unit_price_sen' => 101, 'effective_at' => now()->subMinute(), 'published_by_user_id' => $doctor->id])->save();
        app(CompleteConsultationService::class)->complete($doctor, $visit, ['expected_branch_id' => $visit->branch_id, 'visit_lock_version' => $visit->lock_version, 'queue_lock_version' => $queue->refresh()->lock_version, 'encounter_lock_version' => $encounter->lock_version, 'lock_version' => $plan->lock_version, 'service_deliveries' => [['order_public_id' => $order->public_id, 'disposition' => 'performed', 'quantity_performed' => '1.5']]]);
        $this->selectBranch($ca, $visit->branch);
        $invoice = app(BillingBuilderService::class)->build($ca, $visit, ['expected_branch_id' => $visit->branch_id, 'lock_version' => null]);
        if ($finalize) {
            $invoice = app(BillingBuilderService::class)->finalize($ca, $visit, $invoice, ['expected_branch_id' => $visit->branch_id, 'lock_version' => $invoice->lock_version]);
        }
        if (! $freeze) {
            // Only this test fixture stands in for an invoice finalised before BIL-11 (finalised lines are otherwise immutable).
            DB::transaction(function () use ($invoice): void {
                DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
                DB::statement('ALTER TABLE invoice_lines DISABLE TRIGGER USER');
                DB::table('invoice_lines')->where('invoice_id', $invoice->id)->update(['service_category_snapshot' => null]);
                DB::statement('ALTER TABLE invoice_lines ENABLE TRIGGER USER');
            });
        }

        return [$ca, $visit, $invoice->refresh(), $service];
    }

    private function categoryTotal(Visit $visit, IssuedBillingDocumentPrint $print, string $name): int
    {
        $total = null;
        $this->get(route('billing.issued.show', [$visit, $print->public_id]))->assertOk()->assertInertia(function (Assert $page) use ($name, &$total): void {
            $total = collect($page->toArray()['props']['billing']['invoice']['categories'])->firstWhere('name', $name)['totalSen'];
        });

        return (int) $total;
    }

    /** @return array<string, mixed> */
    private function input(Visit $visit): array
    {
        return ['expected_branch_id' => $visit->branch_id, 'idempotency_key' => (string) Str::uuid(), 'return_to' => 'billing'];
    }
}
