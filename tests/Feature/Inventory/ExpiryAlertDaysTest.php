<?php

namespace Tests\Feature\Inventory;

use App\Domain\Organisation\Inventory\Models\InventoryBatch;
use App\Domain\Organisation\Inventory\Models\InventoryItem;
use App\Domain\Organisation\Inventory\Models\InventoryLocation;
use App\Domain\Organisation\Inventory\Models\InventorySku;
use App\Domain\Organisation\Inventory\Services\InventoryBatchHoldService;
use App\Domain\Organisation\Inventory\Services\InventoryMovementService;
use App\Domain\Organisation\Inventory\Services\InventoryReferenceAdministrationService;
use App\Domain\Organisation\Inventory\Services\InventoryStockReviewService as Reviews;
use App\Domain\Organisation\Inventory\Services\StockValuationReportService;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Feature\Visit\VisitTestCase;

/** STK-42: expiry alert days per SKU and the read-only "At risk in 6 months" section of Stock review. */
class ExpiryAlertDaysTest extends VisitTestCase
{
    private User $supervisor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-11-21T03:00:00Z'));
        $this->supervisor = $this->actor('ca_supervisor');
        $this->selectBranch($this->supervisor);
    }

    private function today(): CarbonImmutable
    {
        return now()->setTimezone($this->branch->timezone)->toImmutable()->startOfDay();
    }

    /** @return array{sku: InventorySku, batch: InventoryBatch, location: InventoryLocation} */
    private function stock(int $days, string $quantity = '10.000', ?int $alertDays = null, ?int $unitCostSen = null, ?InventorySku $sku = null, string $code = ''): array
    {
        $code = $code !== '' ? $code : 'SYN-'.Str::upper(Str::random(8));
        if ($sku === null) {
            $item = new InventoryItem;
            $item->forceFill(['public_id' => (string) Str::uuid(), 'organisation_id' => $this->organisation->id, 'code' => $code, 'generic_name' => 'Synthetic alert item', 'is_active' => true])->save();
            $sku = new InventorySku;
            $sku->forceFill(['public_id' => (string) Str::uuid(), 'organisation_id' => $this->organisation->id, 'inventory_item_id' => $item->id, 'sku_code' => $code, 'pack_size' => '1.000', 'purchase_unit' => 'unit', 'stock_unit' => 'unit', 'dispensing_unit' => 'unit', 'unit_conversion' => '1.000', 'expiry_alert_days' => $alertDays, 'is_active' => true])->save();
        }
        $location = new InventoryLocation;
        $location->forceFill(['public_id' => (string) Str::uuid(), 'organisation_id' => $this->organisation->id, 'branch_id' => $this->branch->id, 'code' => 'L-'.Str::upper(Str::random(8)), 'name' => 'Synthetic alert store', 'type' => 'dispensary', 'is_active' => true])->save();
        $batch = new InventoryBatch;
        $batch->forceFill(['public_id' => (string) Str::uuid(), 'organisation_id' => $this->organisation->id, 'inventory_sku_id' => $sku->id, 'batch_number' => 'B-'.Str::upper(Str::random(8)), 'expiry_date' => $this->today()->addDays($days)->toDateString(), 'received_at' => now()->subYear(), 'status' => 'available'])->save();
        app(InventoryMovementService::class)->openingBalance($this->supervisor, array_filter(['expected_branch_id' => $this->branch->id, 'sku_public_id' => $sku->public_id, 'location_public_id' => $location->public_id, 'batch_public_id' => $batch->public_id, 'quantity' => $quantity, 'unit_cost_sen' => $unitCostSen], fn ($v) => $v !== null));

        return compact('sku', 'batch', 'location');
    }

    /** @return list<string> */
    private function expiring(?User $actor = null): array
    {
        return array_column(app(Reviews::class)->page($actor ?? $this->supervisor, [])['lists']['expiring']['rows'], 'batch');
    }

    /** @return array<string, mixed> */
    private function atRisk(User $actor, array $query = []): array
    {
        return app(Reviews::class)->atRisk($actor, $query);
    }

    private function save(InventorySku $sku, mixed $days, ?User $actor = null): InventorySku
    {
        return app(InventoryReferenceAdministrationService::class)->updateSku($actor ?? $this->actor('director'), $sku->refresh(), ['expiry_alert_days' => $days, 'expected_lock_version' => $sku->lock_version]);
    }

    public function test_the_alert_days_field_accepts_7_to_365_and_empty_means_90(): void
    {
        $f = $this->stock(60);
        foreach ([6, 366, '0', 'abc', '30.5', -10] as $bad) {
            try {
                $this->save($f['sku'], $bad);
                $this->fail('Accepted '.var_export($bad, true));
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('expiry_alert_days', $e->errors());
            }
        }
        $this->assertSame(7, $this->save($f['sku'], '7')->expiry_alert_days);
        $this->assertSame(365, $this->save($f['sku'], 365)->expiry_alert_days);
        $this->assertNull($this->save($f['sku'], '')->expiry_alert_days);
        $this->assertContains($f['batch']->batch_number, $this->expiring(), 'empty behaves as 90 days');

        $audit = DB::table('audit_logs')->where('event', 'inventory_sku.updated')->where('subject_id', $f['sku']->id)->latest('id')->first();
        $this->assertNotNull($audit);
        $this->assertStringContainsString('expiry_alert_days', (string) $audit->metadata);
        $this->assertStringNotContainsString('365', (string) $audit->metadata);

        $this->expectException(QueryException::class);
        DB::transaction(fn () => DB::table('inventory_skus')->where('id', $f['sku']->id)->update(['expiry_alert_days' => 6]));
    }

    public function test_postgres_refuses_366_and_a_stale_version_and_no_permission_are_refused(): void
    {
        $f = $this->stock(60);
        try {
            DB::transaction(fn () => DB::table('inventory_skus')->where('id', $f['sku']->id)->update(['expiry_alert_days' => 366]));
            $this->fail('PostgreSQL accepted 366');
        } catch (QueryException) {
        }
        $stale = $f['sku']->refresh()->lock_version;
        $this->save($f['sku'], 30);
        try {
            app(InventoryReferenceAdministrationService::class)->updateSku($this->actor('director'), $f['sku']->refresh(), ['expiry_alert_days' => 40, 'expected_lock_version' => $stale]);
            $this->fail('A stale version was accepted');
        } catch (ValidationException) {
        }
        $this->assertSame(30, $f['sku']->refresh()->expiry_alert_days);
        $this->expectException(AuthorizationException::class);
        $this->save($f['sku'], 40, $this->actor('ca'));
    }

    public function test_the_value_is_ignored_when_the_sku_does_not_track_expiry(): void
    {
        $f = $this->stock(60);
        $f['sku']->forceFill(['expiry_tracking_required' => false])->save();
        $this->assertNull($this->save($f['sku'], 30)->expiry_alert_days);
    }

    public function test_the_expiring_list_uses_each_skus_own_window(): void
    {
        $thirty = $this->stock(20, alertDays: 30);
        $thirtyOut = $this->stock(60, sku: $thirty['sku']);
        $long = $this->stock(150, alertDays: 180);
        $default = $this->stock(60);
        $defaultOut = $this->stock(150, sku: $default['sku']);

        $listed = $this->expiring();
        foreach ([$thirty, $long, $default] as $in) {
            $this->assertContains($in['batch']->batch_number, $listed);
        }
        foreach ([$thirtyOut, $defaultOut] as $out) {
            $this->assertNotContains($out['batch']->batch_number, $listed);
        }
        $this->assertSame(90, app(Reviews::class)->page($this->supervisor, [])['windowDays']);
    }

    public function test_the_alert_and_at_risk_boundaries_use_the_kuala_lumpur_date(): void
    {
        // 15:59 UTC is 23:59 in Kuala Lumpur: "today" is still 21 Nov there.
        $this->travelTo(CarbonImmutable::parse('2026-11-21T15:59:00Z'));
        $onDay = $this->stock(30, alertDays: 30);
        $dayAfter = $this->stock(31, sku: $onDay['sku']);
        $risk183 = $this->stock(183);
        $risk184 = $this->stock(184);
        $this->assertContains($onDay['batch']->batch_number, $this->expiring());
        $this->assertNotContains($dayAfter['batch']->batch_number, $this->expiring());
        $batches = array_column($this->atRisk($this->supervisor)['rows'], 'batch');
        $this->assertContains($risk183['batch']->batch_number, $batches);
        $this->assertNotContains($risk184['batch']->batch_number, $batches);

        // One minute later it is 22 Nov in Kuala Lumpur, so day 31 and day 184 come in.
        $this->travelTo(CarbonImmutable::parse('2026-11-21T16:00:00Z'));
        $this->assertContains($dayAfter['batch']->batch_number, $this->expiring());
        $this->assertContains($risk184['batch']->batch_number, array_column($this->atRisk($this->supervisor)['rows'], 'batch'));
    }

    public function test_at_risk_lists_usable_stock_only_and_searches(): void
    {
        $usable = $this->stock(100, code: 'SYN-USABLE');
        $held = $this->stock(100);
        $recalled = $this->stock(100);
        $inactive = $this->stock(100);
        $expired = $this->stock(10);
        $expired['batch']->forceFill(['expiry_date' => $this->today()->subDay()->toDateString()])->save();
        foreach ([[$held, 'damaged'], [$recalled, 'recall']] as [$f, $reason]) {
            app(InventoryBatchHoldService::class)->act($this->supervisor, $f['batch']->public_id, 'place', ['expected_branch_id' => $this->branch->id, 'lock_version' => 0, 'retry_key' => (string) Str::uuid(), 'reason' => $reason]);
        }
        $inactive['location']->forceFill(['is_active' => false])->save();

        $batches = array_column($this->atRisk($this->supervisor)['rows'], 'batch');
        $this->assertSame([$usable['batch']->batch_number], $batches);
        $this->assertSame(100, $this->atRisk($this->supervisor)['rows'][0]['daysLeft']);
        $this->assertSame([], $this->atRisk($this->supervisor, ['q' => 'nothing-like-this'])['rows']);
        $this->assertCount(1, $this->atRisk($this->supervisor, ['q' => 'usable'])['rows']);
    }

    public function test_cost_viewers_get_rows_sorted_by_value_and_others_by_expiry_with_no_cost_keys(): void
    {
        $cheap = $this->stock(150, '10.000', unitCostSen: 100);    // RM 10.00
        $dear = $this->stock(170, '5.000', unitCostSen: 1000);     // RM 50.00
        $none = $this->stock(20, '10.000');                        // no cost at all
        $partSku = $this->stock(160, '4.000');                     // entered first, with no cost
        $partCosted = $this->stock(165, '4.000', unitCostSen: 500, sku: $partSku['sku']);

        foreach (['director', 'finance_officer'] as $role) {
            $viewer = $this->actor($role);
            $this->selectBranch($viewer);
            $section = $this->atRisk($viewer);
            $this->assertSame('value', $section['sortedBy']);
            $this->assertSame(StockValuationReportService::BANNER, $section['banner']);
            $order = array_column($section['rows'], 'batch');
            // Dear RM 50, cheap RM 10, then the two part-costed batches (RM 40 x 4/8 = RM 20... sorted by value), no cost last.
            $this->assertSame($none['batch']->batch_number, end($order));
            $this->assertSame($dear['batch']->batch_number, $order[0]);
            $rows = collect($section['rows'])->keyBy('batch');
            $this->assertSame('50.00', $rows[$dear['batch']->batch_number]['value']);
            $this->assertSame('10.00', $rows[$cheap['batch']->batch_number]['value']);
            $this->assertNull($rows[$none['batch']->batch_number]['value']);
            // The SKU's costed part is 4 units at RM 5.00 = RM 20.00, spread over its 8 units: RM 10.00 for each batch.
            $this->assertSame('10.00', $rows[$partSku['batch']->batch_number]['value']);
            $this->assertTrue($rows[$partSku['batch']->batch_number]['part']);
            $this->assertTrue($rows[$partCosted['batch']->batch_number]['part']);
            $this->assertFalse($rows[$dear['batch']->batch_number]['part']);
            $this->assertFalse($rows[$dear['batch']->batch_number]['estimate']);
            // Equal values break the tie by expiry date, then SKU code and batch: stable across pages.
            $this->assertSame([$cheap['batch']->batch_number, $partSku['batch']->batch_number, $partCosted['batch']->batch_number], array_slice($order, 1, 3));
        }

        $section = $this->atRisk($this->supervisor);
        $this->assertSame('expiry', $section['sortedBy']);
        $this->assertArrayNotHasKey('banner', $section);
        $this->assertSame([$none['batch']->batch_number, $cheap['batch']->batch_number, $partSku['batch']->batch_number, $partCosted['batch']->batch_number, $dear['batch']->batch_number], array_column($section['rows'], 'batch'));
        foreach ($section['rows'] as $row) {
            foreach (['value', 'valueSen', 'estimate', 'part'] as $key) {
                $this->assertArrayNotHasKey($key, $row);
            }
        }
    }

    public function test_value_sorting_pages_stably_and_writes_nothing(): void
    {
        $director = $this->actor('director');
        $this->selectBranch($director);
        for ($i = 0; $i < 27; $i++) {
            $this->stock(100 + $i, '1.000', unitCostSen: 100 * ($i + 1));
        }
        $before = DB::table('audit_logs')->count();
        $first = $this->atRisk($director);
        $second = $this->atRisk($director, ['at_risk_page' => 2]);
        $this->assertSame([27, 1, 2, 25, 2], [$first['total'], $first['page'], $first['lastPage'], count($first['rows']), count($second['rows'])]);
        $values = array_column([...$first['rows'], ...$second['rows']], 'valueSen');
        $sorted = $values;
        rsort($sorted);
        $this->assertSame($sorted, $values);
        $this->assertSame($before, DB::table('audit_logs')->count());
    }

    public function test_at_risk_rows_cannot_be_acknowledged(): void
    {
        $this->stock(100);
        $item = $this->atRisk($this->supervisor)['rows'][0]['item_id'];
        $before = DB::table('inventory_stock_reviews')->count();
        try {
            app(Reviews::class)->acknowledge($this->supervisor, 'at_risk', $item, []);
            $this->fail('An at-risk row was acknowledged');
        } catch (HttpException $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
        $this->actingAs($this->supervisor)->post('/inventory/stock-review/at_risk/'.$item, ['expected_branch_id' => $this->branch->id])->assertNotFound();
        $this->assertSame($before, DB::table('inventory_stock_reviews')->count());
    }

    public function test_page_used_by_the_pharmacy_space_carries_no_cost_and_keeps_window_days(): void
    {
        $this->stock(100, unitCostSen: 500);
        $director = $this->actor('director');
        $this->selectBranch($director);
        $page = app(Reviews::class)->page($director, []);
        $this->assertSame(90, $page['windowDays']);
        $this->assertArrayNotHasKey('atRisk', $page);
        $this->assertStringNotContainsString('value', json_encode($page['lists']) ?: '');
        $this->get(route('inventory.stock-review'))->assertOk()->assertInertia(fn ($page) => $page->where('atRisk.sortedBy', 'value')->where('review.windowDays', 90));
    }

    public function test_the_migration_drops_and_restores_the_column(): void
    {
        $m = require database_path('migrations/2026_11_07_003100_add_expiry_alert_days_to_inventory_skus.php');
        $m->down();
        $this->assertFalse(Schema::hasColumn('inventory_skus', 'expiry_alert_days'));
        $m->up();
        $this->assertTrue(Schema::hasColumn('inventory_skus', 'expiry_alert_days'));
    }
}
