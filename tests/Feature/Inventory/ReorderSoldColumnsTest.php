<?php

namespace Tests\Feature\Inventory;

use App\Domain\Clinical\Dispensary\Services\DispensaryService;
use App\Domain\Organisation\Inventory\Services\StockUnitQuantity;
use App\Domain\Organisation\Inventory\Services\TreatmentUsageService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Visit\VisitTestCase;
use Tests\Support\OtcDispensaryFixtures;
use Tests\Support\SupplierReturnFixture;

/**
 * STK-43 (owner decision 26, 20-21 Nov 2026): Reorder suggestions show what was sold (dispensed) from each location over the
 * last 7, 14 and 30 branch-local days. Shown only: the suggested quantity is unchanged.
 */
final class ReorderSoldColumnsTest extends VisitTestCase
{
    use OtcDispensaryFixtures;

    /** @return array<string, mixed> */
    private function page(mixed $actor): array
    {
        return $this->actingAs($actor)->get(route('inventory.reorder-suggestions', ['need' => 'all']))->assertOk()->viewData('page')['props']['suggestions'];
    }

    /** A real completed OTC dispensing at the given branch-local moment, from the fixture's location and batch. */
    private function dispenseAt(array $f, CarbonImmutable $at, string $quantity): void
    {
        $this->travelTo($at);
        $visit = $this->register($f['ca'], $this->patient());
        $g = [...$f, 'visit' => $visit];
        $case = $this->open($g);
        $this->confirmAllergy($g, $case);
        app(DispensaryService::class)->addItem($g['ca'], $case->refresh(), $this->addPayload($g, $case, $quantity));
        $this->complete($g, $case);
    }

    public function test_sold_counts_dispensing_from_the_location_over_7_14_and_30_branch_local_days(): void
    {
        $timezone = $this->branch->timezone;
        $now = CarbonImmutable::now($timezone)->setTime(10, 0);
        $today = $now->startOfDay();
        $this->travelTo($now->subDays(32));
        $f = $this->fixture();
        // Keep the batch usable for the whole test.
        DB::table('inventory_batches')->where('id', $f['batch']->id)->update(['expiry_date' => $now->addMonths(6)->toDateString()]);

        $this->dispenseAt($f, $today->subDays(30)->setTime(23, 0), '1.000');   // before the 30-day window: not counted
        $this->dispenseAt($f, $today->subDays(29)->setTime(0, 1), '1.000');    // first minute of the 30-day window
        $this->dispenseAt($f, $today->subDays(25)->setTime(10, 0), '2.000');
        $this->dispenseAt($f, $today->subDays(10)->setTime(10, 0), '3.000');
        $this->dispenseAt($f, $today->subDays(7)->setTime(23, 59), '1.000');   // last minute before the 7-day window
        $this->dispenseAt($f, $today->subDays(6)->setTime(0, 1), '1.000');     // first minute of the 7-day window
        $this->dispenseAt($f, $today->subDays(3)->setTime(10, 0), '1.000');

        $this->travelTo($now);
        $finance = (new SupplierReturnFixture($this->branch))->actor('finance_officer');
        DB::table('inventory_reorder_levels')->insert(['organisation_id' => $this->branch->organisation_id, 'inventory_location_id' => $f['location']->id,
            'inventory_sku_id' => $f['sku']->id, 'reorder_level' => '20.000', 'updated_by_user_id' => $finance->id, 'created_at' => now(), 'updated_at' => now()]);

        $row = collect($this->page($finance)['rows'])->firstWhere('skuCode', $f['sku']->sku_code);
        // 7 d: 1 + 1; 14 d: + 1 + 3; 30 d: + 2 + 1 (the dispense 30 days ago at 23:00 is outside).
        $this->assertSame(['2.000', '6.000', '9.000'], [$row['sold7'], $row['sold14'], $row['sold30']]);
        // The suggestion still uses the same 30-day window: 9 ÷ 30 = 0.300 a day, and sold never exceeds that usage.
        $this->assertSame('0.300', $row['averageDailyUse']);
        $this->assertLessThanOrEqual(StockUnitQuantity::scaled($row['averageDailyUse'], true) * 30, StockUnitQuantity::scaled($row['sold30'], true));
    }

    public function test_a_location_with_no_dispensing_shows_nothing_sold_and_treatment_use_is_not_a_sale(): void
    {
        $this->travelTo(CarbonImmutable::now($this->branch->timezone)->setTime(10, 0));
        $f = $this->fixture();
        $finance = (new SupplierReturnFixture($this->branch))->actor('finance_officer');
        DB::table('inventory_reorder_levels')->insert(['organisation_id' => $this->branch->organisation_id, 'inventory_location_id' => $f['location']->id,
            'inventory_sku_id' => $f['sku']->id, 'reorder_level' => '20.000', 'updated_by_user_id' => $finance->id, 'created_at' => now(), 'updated_at' => now()]);

        // 3 used in a treatment room is use (it feeds the average) but not a sale.
        app(TreatmentUsageService::class)->record($f['ca'], ['expected_branch_id' => $this->branch->id, 'retry_key' => (string) Str::uuid(),
            'location_id' => $f['location']->id, 'batch_id' => $f['batch']->id, 'unit' => $f['sku']->stock_unit, 'quantity' => '3.000', 'kind' => 'usage', 'reason' => 'wound_care']);

        $row = collect($this->page($finance)['rows'])->firstWhere('skuCode', $f['sku']->sku_code);
        $this->assertSame([null, null, null], [$row['sold7'], $row['sold14'], $row['sold30']]);
        $this->assertSame('0.100', $row['averageDailyUse']);
    }
}
