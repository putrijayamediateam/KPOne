<?php

namespace Tests\Feature\Inventory;

use App\Domain\Access\BranchAccessService;
use App\Domain\Clinical\Dispensary\Services\DispensaryService;
use App\Domain\Organisation\Inventory\Models\InventoryLocation;
use App\Domain\Organisation\Inventory\Services\ProcurementService;
use App\Domain\Organisation\Inventory\Services\StockRequestService;
use App\Domain\Organisation\Inventory\Services\StockUnitQuantity;
use App\Domain\Organisation\Inventory\Services\SupplierTermsService;
use App\Domain\Organisation\Inventory\Services\TreatmentUsageService;
use App\Domain\Organisation\Models\Branch;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Visit\VisitTestCase;
use Tests\Support\OtcDispensaryFixtures;
use Tests\Support\SupplierReturnFixture;
use Tests\Support\TransferShortageFixture;
use Tests\Support\VerifiedReceivingFixture;

/** STK-16: read-only reorder suggestions (approved by B.E.R.N.I.S.S for the owner, 2026-11-11). */
final class ReorderSuggestionTest extends VisitTestCase
{
    use OtcDispensaryFixtures;

    /** @return array<string, mixed> */
    private function setUpBranch(): array
    {
        // A fixed branch-local morning keeps the 30-day window away from Kuala Lumpur midnight.
        $this->travelTo(now($this->branch->timezone)->setTime(10, 0));
        $f = (new VerifiedReceivingFixture($this->branch))->build('10.000');
        $actors = new SupplierReturnFixture($this->branch);
        $access = app(BranchAccessService::class);

        // 4 of the 10 ordered arrive, so 6 stay on order.
        $access->select($f['receiver'], $this->branch);
        $payload = $f['payload'];
        $payload['lines'][0]['quantity'] = '4.000';
        $receipt = app(ProcurementService::class)->receive($f['receiver'], $f['order'], $payload);
        $batchId = (int) DB::table('inventory_receipt_lots as lot')->join('inventory_receipt_line_evidence as line', 'line.id', '=', 'lot.line_evidence_id')
            ->where('line.goods_receipt_id', $receipt->id)->value('lot.inventory_batch_id');

        // 3 used in a treatment room count as use; a reversed entry does not.
        $ca = $actors->actor('ca');
        $usage = app(TreatmentUsageService::class);
        $entry = fn (string $quantity) => ['expected_branch_id' => $this->branch->id, 'retry_key' => (string) Str::uuid(),
            'location_id' => $f['location']->id, 'batch_id' => $batchId, 'unit' => $f['sku']->stock_unit, 'quantity' => $quantity, 'kind' => 'usage', 'reason' => 'wound_care'];
        $usage->record($ca, $entry('3.000'));
        $reversed = $usage->record($ca, $entry('1.000'));
        $usage->reverse($actors->actor('ca_supervisor'), $reversed, ['expected_branch_id' => $this->branch->id, 'retry_key' => (string) Str::uuid(), 'reason' => 'recorded_in_error']);

        // Preferred supplier: packs of 10, at least 3 packs, RM 5.00 a pack, 10 days' lead time.
        app(SupplierTermsService::class)->create($actors->actor('finance_officer'), ['expected_branch_id' => $this->branch->id, 'idempotency_key' => (string) Str::uuid(),
            'supplier_public_id' => $f['supplier']->public_id, 'sku_public_id' => $f['sku']->public_id, 'pack_description' => 'Box of 10', 'pack_quantity' => '10',
            'pack_price_sen' => 500, 'lead_time_days' => 10, 'minimum_order_packs' => 3, 'is_preferred' => true]);

        $onHand = StockUnitQuantity::scaled((string) DB::table('inventory_stock_balances')->where('inventory_location_id', $f['location']->id)->where('inventory_sku_id', $f['sku']->id)->sum('quantity'), true);

        return [...$f, 'onHand' => $onHand, 'actors' => $actors];
    }

    private function setLevel(array $f, int $levelMilli, ?int $maxMilli = null): void
    {
        DB::table('inventory_reorder_levels')->updateOrInsert(
            ['organisation_id' => $this->branch->organisation_id, 'inventory_location_id' => $f['location']->id, 'inventory_sku_id' => $f['sku']->id],
            ['reorder_level' => StockUnitQuantity::format($levelMilli), 'max_level' => $maxMilli === null ? null : StockUnitQuantity::format($maxMilli), 'updated_by_user_id' => $f['finance']->id, 'created_at' => now(), 'updated_at' => now()],
        );
    }

    /** @return array<string, mixed> */
    private function page(mixed $actor, array $query = []): array
    {
        return $this->actingAs($actor)->get(route('inventory.reorder-suggestions', $query))->assertOk()->viewData('page')['props']['suggestions'];
    }

    public function test_the_suggestion_follows_the_documented_method_in_whole_packs(): void
    {
        $f = $this->setUpBranch();
        $sku = $f['sku']->sku_code;
        $this->setLevel($f, $f['onHand'] + 20000);
        $finance = $f['actors']->actor('finance_officer');

        $rows = array_values(array_filter($this->page($finance)['rows'], fn (array $row) => $row['skuCode'] === $sku));
        $this->assertCount(1, $rows);
        $row = $rows[0];
        // Need = (on hand + 20) − on hand − 6 on order − 0 incoming + ceil(3 used × 10 days ÷ 30) = 15: 2 packs of 10,
        // raised to the supplier's minimum of 3 packs.
        $this->assertSame(['6.000', '0.000', '0.100', 3, '30.000', false, 1500], [$row['onOrder'], $row['incoming'], $row['averageDailyUse'], $row['suggestedPacks'], $row['suggestedQuantity'], $row['coveredByOrders'], $row['estimateSen']]);
        $this->assertSame(10, $row['supplier']['leadTimeDays']);

        // Someone without the cost permission never receives the estimate.
        $supervisorRow = collect($this->page($f['actors']->actor('ca_supervisor'))['rows'])->firstWhere('skuCode', $sku);
        $this->assertArrayNotHasKey('estimateSen', $supervisorRow);

        // The search narrows the list.
        $this->assertNotNull(collect($this->page($finance, ['q' => $sku])['rows'])->firstWhere('skuCode', $sku));
        $this->assertSame([], $this->page($finance, ['q' => 'NO-SUCH-SKU'])['rows']);

        // Use older than 30 days no longer counts.
        $this->travel(31)->days();
        $later = collect($this->page($finance)['rows'])->firstWhere('skuCode', $sku);
        $this->assertSame('0.000', $later['averageDailyUse']);

        // The page writes nothing.
        $this->assertSame(0, DB::table('audit_logs')->where('event', 'like', 'inventory.reorder%')->count());
    }

    public function test_stock_already_covered_by_orders_is_listed_only_when_asked(): void
    {
        $f = $this->setUpBranch();
        $sku = $f['sku']->sku_code;
        // Need = 5 − 6 on order + 1 = 0.
        $this->setLevel($f, $f['onHand'] + 5000);
        $finance = $f['actors']->actor('finance_officer');

        $this->assertNull(collect($this->page($finance)['rows'])->firstWhere('skuCode', $sku));
        $row = collect($this->page($finance, ['need' => 'all'])['rows'])->firstWhere('skuCode', $sku);
        $this->assertSame([true, '0.000', null], [$row['coveredByOrders'], $row['suggestedQuantity'], $row['suggestedPacks']]);
    }

    public function test_without_a_preferred_supplier_the_suggestion_is_whole_units_and_the_page_needs_inventory_view(): void
    {
        $f = $this->setUpBranch();
        DB::table('inventory_supplier_sku_terms')->update(['is_preferred' => false, 'lock_version' => DB::raw('lock_version + 1')]);
        $sku = $f['sku']->sku_code;
        $this->setLevel($f, $f['onHand'] + 7500);

        $row = collect($this->page($f['actors']->actor('finance_officer'))['rows'])->firstWhere('skuCode', $sku);
        // No lead time without a supplier: 7.5 − 6 = 1.5, rounded up to 2 whole units.
        $this->assertSame([null, null, '2.000'], [$row['supplier'], $row['suggestedPacks'], $row['suggestedQuantity']]);

        $this->actingAs($f['actors']->actor('marketing'))->get(route('inventory.reorder-suggestions'))->assertForbidden();
    }

    public function test_incoming_transfers_count_and_other_branches_never_appear(): void
    {
        $this->travelTo(now($this->branch->timezone)->setTime(10, 0));
        $transfers = new TransferShortageFixture($this->branch);
        // 4 dispatched to an empty dispensary, then 3 more approved but not yet sent.
        $f = $transfers->dispatched('4.000');
        $service = app(StockRequestService::class);
        $requester = $transfers->actor('ca');
        $request = $service->create($requester, ['expected_branch_id' => $this->branch->id, 'source_location_public_id' => $f['location']->public_id,
            'destination_location_public_id' => $f['destination']->public_id, 'lines' => [['sku_public_id' => $f['sku']->public_id, 'quantity' => '3.000']]]);
        app(BranchAccessService::class)->select($f['supervisor'], $this->branch);
        $service->approve($f['supervisor'], $request, ['expected_branch_id' => $this->branch->id, 'lock_version' => $request->lock_version]);
        $finance = $transfers->actor('finance_officer');
        $levels = fn (int $locationId, string $level) => DB::table('inventory_reorder_levels')->insert(['organisation_id' => $this->branch->organisation_id,
            'inventory_location_id' => $locationId, 'inventory_sku_id' => $f['sku']->id, 'reorder_level' => $level, 'updated_by_user_id' => $finance->id, 'created_at' => now(), 'updated_at' => now()]);
        $levels($f['destination']->id, '10.000');

        // A low-stock row at another branch of the same organisation.
        $other = Branch::query()->where('organisation_id', $this->branch->organisation_id)->whereKeyNot($this->branch->id)->firstOrFail();
        $elsewhere = new InventoryLocation;
        $elsewhere->forceFill(['public_id' => (string) Str::uuid(), 'organisation_id' => $this->branch->organisation_id, 'branch_id' => $other->id,
            'code' => 'ELSE-'.Str::upper(Str::random(8)), 'name' => 'Synthetic other branch dispensary', 'type' => 'dispensary', 'is_active' => true])->save();
        $levels($elsewhere->id, '50.000');

        $rows = collect($this->page($finance, ['need' => 'all'])['rows'])->where('skuCode', $f['sku']->sku_code)->values();
        $this->assertSame(['Synthetic receiving dispensary'], $rows->pluck('location')->all());
        // Need = 10 − 0 on hand − 0 on order − (4 dispatched + 3 approved) = 3 whole units; not "covered".
        $this->assertSame(['7.000', '3.000', false], [$rows[0]['incoming'], $rows[0]['suggestedQuantity'], $rows[0]['coveredByOrders']]);
    }

    public function test_stock_exactly_at_its_level_with_nothing_on_order_is_not_called_covered(): void
    {
        $this->travelTo(now($this->branch->timezone)->setTime(10, 0));
        $f = (new TransferShortageFixture($this->branch))->dispatched('4.000');
        $finance = (new SupplierReturnFixture($this->branch))->actor('finance_officer');
        $onHand = (string) DB::table('inventory_stock_balances')->where('inventory_location_id', $f['location']->id)->where('inventory_sku_id', $f['sku']->id)->sum('quantity');
        $this->setLevel(['location' => $f['location'], 'sku' => $f['sku'], 'finance' => $finance], StockUnitQuantity::scaled($onHand, true));

        $row = collect($this->page($finance, ['need' => 'all'])['rows'])->firstWhere('location', $f['location']->name);
        $this->assertSame(['0.000', false], [$row['suggestedQuantity'], $row['coveredByOrders']]);
    }

    public function test_dispensed_medicine_counts_as_use(): void
    {
        $this->travelTo(now($this->branch->timezone)->setTime(10, 0));
        // A real completed OTC dispensing of 6 writes a `dispense` movement out of the dispensary.
        $f = $this->fixture();
        $case = $this->open($f);
        $this->confirmAllergy($f, $case);
        app(DispensaryService::class)->addItem($f['ca'], $case->refresh(), $this->addPayload($f, $case, '6.000'));
        $this->complete($f, $case);
        $this->assertSame('6.000', (string) DB::table('stock_movements')->where('movement_type', 'dispense')->where('source_location_id', $f['location']->id)->sum('quantity'));

        $finance = (new SupplierReturnFixture($f['visit']->branch))->actor('finance_officer');
        $this->setLevel(['location' => $f['location'], 'sku' => $f['sku'], 'finance' => $finance], 5000);
        $row = collect($this->page($finance, ['need' => 'all'])['rows'])->firstWhere('skuCode', $f['sku']->sku_code);
        // 6 dispensed over 30 days is 0.2 a day; 10 opening − 6 leaves 4 on hand, so 1 whole unit to reach 5.
        $this->assertSame(['4.000', '0.200', '1.000'], [$row['onHand'], $row['averageDailyUse'], $row['suggestedQuantity']]);
    }

    public function test_with_a_maximum_the_suggestion_fills_up_to_it(): void
    {
        $f = $this->setUpBranch();
        $sku = $f['sku']->sku_code;
        $finance = $f['actors']->actor('finance_officer');
        $this->setLevel($f, $f['onHand'] + 20000, $f['onHand'] + 40000);

        $row = collect($this->page($finance)['rows'])->firstWhere('skuCode', $sku);
        // STK-30: Need = (on hand + 40) − on hand − 6 on order − 0 incoming + 1 = 35: 4 packs of 10.
        $this->assertSame([StockUnitQuantity::format($f['onHand'] + 40000), 4, '40.000'], [$row['maxLevel'], $row['suggestedPacks'], $row['suggestedQuantity']]);

        // Above the reorder level the row is not listed, even when below the maximum.
        $this->setLevel($f, max(0, $f['onHand'] - 1000), $f['onHand'] + 40000);
        $this->assertNull(collect($this->page($finance, ['need' => 'all'])['rows'])->firstWhere('skuCode', $sku));

        // Without a maximum the row says so.
        $this->setLevel($f, $f['onHand'] + 20000);
        $this->assertNull(collect($this->page($finance)['rows'])->firstWhere('skuCode', $sku)['maxLevel']);
    }

    public function test_the_maximum_is_saved_validated_audited_and_guarded(): void
    {
        $f = $this->setUpBranch();
        $supervisor = $f['actors']->actor('ca_supervisor');
        app(BranchAccessService::class)->select($supervisor, $this->branch);
        $payload = fn (string $level, ?string $max) => ['expected_branch_id' => $this->branch->id, 'location_public_id' => $f['location']->public_id,
            'sku_public_id' => $f['sku']->public_id, 'reorder_level' => $level, 'max_level' => $max];
        $stored = fn () => DB::table('inventory_reorder_levels')->where('inventory_location_id', $f['location']->id)->where('inventory_sku_id', $f['sku']->id)->first();

        $this->actingAs($supervisor)->put(route('inventory.reorder-levels.update'), $payload('5', '12.5'))->assertRedirect(route('inventory.index'))->assertSessionHasNoErrors();
        $this->assertSame(['5.000', '12.500'], [(string) $stored()->reorder_level, (string) $stored()->max_level]);
        $audit = DB::table('audit_logs')->where('event', 'inventory.reorder_level.updated')->latest('id')->first();
        $this->assertSame(['reorder_level' => '5.000', 'max_level' => '12.500'], array_intersect_key(json_decode((string) $audit->metadata, true), ['reorder_level' => 1, 'max_level' => 1]));

        // A maximum below the reorder level is refused and nothing changes.
        $this->actingAs($supervisor)->put(route('inventory.reorder-levels.update'), $payload('5', '4.999'))->assertRedirect(route('inventory.index'))->assertSessionHasErrors('max_level');
        $this->assertSame('12.500', (string) $stored()->max_level);

        // Equal is allowed; blank clears it.
        $this->actingAs($supervisor)->put(route('inventory.reorder-levels.update'), $payload('5', '5'))->assertSessionHasNoErrors();
        $this->assertSame('5.000', (string) $stored()->max_level);
        $this->actingAs($supervisor)->put(route('inventory.reorder-levels.update'), $payload('5', ''))->assertSessionHasNoErrors();
        $this->assertNull($stored()->max_level);

        // Staff without the reorder permission cannot set it.
        $this->actingAs($f['actors']->actor('ca'))->put(route('inventory.reorder-levels.update'), $payload('5', '9'))->assertForbidden();

        // PostgreSQL refuses a maximum below the level whatever the caller.
        $this->expectException(QueryException::class);
        DB::table('inventory_reorder_levels')->where('id', $stored()->id)->update(['max_level' => '1.000']);
    }
}
