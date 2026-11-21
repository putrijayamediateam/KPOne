<?php

namespace Tests\Feature\Billing;

use App\Domain\Clinical\Models\ClinicalEncounter;
use App\Domain\Clinical\Models\MedicineCatalogueItem;
use App\Domain\Clinical\Models\TreatmentPlan;
use App\Domain\Clinical\Models\TreatmentPlanMedicineOrder;
use App\Domain\Visit\Billing\Models\ChargeDefinition;
use App\Domain\Visit\Billing\Models\PriceBook;
use App\Domain\Visit\Billing\Models\PriceEntry;
use App\Domain\Visit\Billing\Services\PanelVisitLimit;
use App\Domain\Visit\Models\Panel;
use App\Domain\Visit\Models\Visit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * PNL-26b and PNL-26c: the Panel's limit per visit warns only. Billing compares the invoice total with it; the
 * doctor sees one line with the Panel total so far and the limit, never an item price.
 */
class PanelVisitLimitTest extends BillingTestCase
{
    use ApprovedPanelAllocations;

    /** @return array{0: Panel, 1: Visit, 2: ClinicalEncounter} */
    private function panelVisit(?int $limitSen): array
    {
        $panel = Panel::factory()->create(['organisation_id' => $this->organisation->id, 'visit_limit_sen' => $limitSen]);
        [$allocation, $invoice] = $this->approvedAllocation($panel);
        $visit = Visit::query()->findOrFail($invoice->visit_id);
        DB::table('visits')->where('id', $visit->id)->update(['coverage_type' => 'panel', 'panel_id' => $panel->id, 'coverage_panel_name_snapshot' => $panel->name]);
        $encounter = ClinicalEncounter::query()->where('visit_id', $visit->id)->firstOrFail();

        return [$panel, $visit->refresh(), $encounter];
    }

    private function panelPrice(Panel $panel, string $sourceKey, int $sen): void
    {
        $charge = ChargeDefinition::query()->where('source_key', $sourceKey)->firstOrFail();
        $book = PriceBook::query()->where('price_tier', 'panel')->whereNull('panel_id')->first() ?? new PriceBook;
        if (! $book->exists) {
            $book->forceFill(['public_id' => (string) Str::uuid(), 'organisation_id' => $this->organisation->id, 'scope_key' => 'panel:default', 'name' => 'Synthetic Panel prices', 'currency' => 'MYR', 'price_tier' => 'panel', 'panel_id' => null])->save();
        }
        $entry = new PriceEntry;
        $entry->forceFill(['organisation_id' => $this->organisation->id, 'price_book_id' => $book->id, 'charge_definition_id' => $charge->id, 'unit_price_sen' => $sen, 'version' => 1, 'effective_at' => now()->subMinute(), 'published_by_user_id' => $this->actor('finance_officer')->id])->save();
    }

    public function test_the_limit_is_only_known_for_a_panel_visit_with_a_limit(): void
    {
        [, $visit] = $this->panelVisit(null);
        $service = app(PanelVisitLimit::class);
        $this->assertNull($service->limitSen($visit));
        $this->assertNull($service->forInvoiceTotal($visit, 9999));

        DB::table('panels')->where('id', $visit->panel_id)->update(['visit_limit_sen' => 5000]);
        $this->assertSame(5000, $service->limitSen($visit->refresh()));
        $this->assertSame(['limitSen' => 5000, 'totalSen' => 5000, 'over' => false], $service->forInvoiceTotal($visit, 5000));
        $this->assertSame(['limitSen' => 5000, 'totalSen' => 5001, 'over' => true], $service->forInvoiceTotal($visit, 5001));

        DB::table('visits')->where('id', $visit->id)->update(['coverage_type' => 'self_pay', 'panel_id' => null, 'coverage_panel_name_snapshot' => null]);
        $this->assertNull($service->limitSen($visit->refresh()));
    }

    public function test_the_doctors_estimate_adds_panel_prices_and_says_when_an_item_has_no_panel_price(): void
    {
        [$panel, $visit, $encounter] = $this->panelVisit(3000);
        $service = app(PanelVisitLimit::class);

        // No Panel price yet for the consultation: the total is too low and the estimate says so.
        $this->assertSame(['limitSen' => 3000, 'totalSen' => 0, 'over' => false, 'partial' => true], $service->estimateForEncounter($visit, $encounter->id));

        $this->panelPrice($panel, 'consultation', 2500);
        $this->assertSame(['limitSen' => 3000, 'totalSen' => 2500, 'over' => false, 'partial' => false], $service->estimateForEncounter($visit, $encounter->id));

        // An ordered medicine with a Panel price pushes the total over the limit; a withdrawn order never counts.
        $medicine = MedicineCatalogueItem::factory()->create(['organisation_id' => $this->organisation->id]);
        $charge = new ChargeDefinition;
        $charge->forceFill(['public_id' => (string) Str::uuid(), 'organisation_id' => $this->organisation->id, 'code' => 'UAT-MED', 'type' => 'medicine', 'display_name' => 'Synthetic medicine', 'unit' => 'tablet', 'source_key' => 'medicine:'.$medicine->id, 'medicine_catalogue_item_id' => $medicine->id])->save();
        $this->panelPrice($panel, 'medicine:'.$medicine->id, 300);
        $plan = TreatmentPlan::query()->where('clinical_encounter_id', $encounter->id)->first() ?? TreatmentPlan::factory()->create(['clinical_encounter_id' => $encounter->id]);
        $order = TreatmentPlanMedicineOrder::factory()->create(['treatment_plan_id' => $plan->id, 'medicine_catalogue_item_id' => $medicine->id, 'quantity_ordered' => '2.000', 'unit_snapshot' => 'tablet']);
        $this->assertSame(['limitSen' => 3000, 'totalSen' => 3100, 'over' => true, 'partial' => false], $service->estimateForEncounter($visit, $encounter->id));

        DB::table('treatment_plan_medicine_orders')->where('id', $order->id)->update(['status' => 'withdrawn', 'withdrawn_at' => now(), 'withdrawn_by_user_id' => $this->actor('resident_doctor')->id]);
        $this->assertSame(2500, $service->estimateForEncounter($visit, $encounter->id)['totalSen']);

        // Self-pay and a Panel with no limit have no estimate at all.
        DB::table('panels')->where('id', $panel->id)->update(['visit_limit_sen' => null]);
        $this->assertNull($service->estimateForEncounter($visit->refresh(), $encounter->id));
    }

    public function test_the_billing_page_warns_above_the_limit_and_never_blocks(): void
    {
        [$panel, $visit] = $this->panelVisit(1000);
        $ca = $this->actor('ca');
        $this->selectBranch($ca, $this->branch);

        $this->actingAs($ca)->get(route('billing.show', $visit))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('billing.panelVisitLimit.limitSen', 1000)->where('billing.panelVisitLimit.over', true)
            ->where('billing.panelVisitLimit.totalSen', fn ($total) => $total > 1000));

        DB::table('panels')->where('id', $panel->id)->update(['visit_limit_sen' => 99999999]);
        $this->actingAs($ca)->get(route('billing.show', $visit))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('billing.panelVisitLimit.over', false));

        DB::table('panels')->where('id', $panel->id)->update(['visit_limit_sen' => null]);
        $this->actingAs($ca)->get(route('billing.show', $visit))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('billing.panelVisitLimit', null));
    }

    public function test_the_consultation_page_carries_one_estimate_and_no_item_price(): void
    {
        [$panel, $visit] = $this->panelVisit(3000);
        $this->panelPrice($panel, 'consultation', 2500);
        $doctor = $visit->assignedDoctor;
        $this->actingAs($doctor)->withSession(['active_branch_id' => $this->branch->id])->get(route('encounters.show', $visit))->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('clinical.visit.panelEstimate.limitSen', 3000)->where('clinical.visit.panelEstimate.totalSen', 2500)
                ->where('clinical.visit.panelEstimate.over', false)->where('clinical.visit.panelEstimate.partial', false)
                ->has('clinical.visit.panelEstimate', 4));
    }
}
