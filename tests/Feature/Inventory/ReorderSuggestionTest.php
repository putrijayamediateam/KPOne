<?php

namespace Tests\Feature\Inventory;

use App\Domain\Access\BranchAccessService;
use App\Domain\Organisation\Inventory\Services\ProcurementService;
use App\Domain\Organisation\Inventory\Services\StockUnitQuantity;
use App\Domain\Organisation\Inventory\Services\SupplierTermsService;
use App\Domain\Organisation\Inventory\Services\TreatmentUsageService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Visit\VisitTestCase;
use Tests\Support\SupplierReturnFixture;
use Tests\Support\VerifiedReceivingFixture;

/** STK-16: read-only reorder suggestions (approved by B.E.R.N.I.S.S for the owner, 2026-11-11). */
final class ReorderSuggestionTest extends VisitTestCase
{
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

        // Preferred supplier: packs of 10, at least 2 packs, RM 5.00 a pack, 10 days' lead time.
        app(SupplierTermsService::class)->create($actors->actor('finance_officer'), ['expected_branch_id' => $this->branch->id, 'idempotency_key' => (string) Str::uuid(),
            'supplier_public_id' => $f['supplier']->public_id, 'sku_public_id' => $f['sku']->public_id, 'pack_description' => 'Box of 10', 'pack_quantity' => '10',
            'pack_price_sen' => 500, 'lead_time_days' => 10, 'minimum_order_packs' => 2, 'is_preferred' => true]);

        $onHand = StockUnitQuantity::scaled((string) DB::table('inventory_stock_balances')->where('inventory_location_id', $f['location']->id)->where('inventory_sku_id', $f['sku']->id)->sum('quantity'), true);

        return [...$f, 'onHand' => $onHand, 'actors' => $actors];
    }

    private function setLevel(array $f, int $levelMilli): void
    {
        DB::table('inventory_reorder_levels')->updateOrInsert(
            ['organisation_id' => $this->branch->organisation_id, 'inventory_location_id' => $f['location']->id, 'inventory_sku_id' => $f['sku']->id],
            ['reorder_level' => StockUnitQuantity::format($levelMilli), 'updated_by_user_id' => $f['finance']->id, 'created_at' => now(), 'updated_at' => now()],
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
        // Need = (on hand + 20) − on hand − 6 on order − 0 incoming + ceil(3 used × 10 days ÷ 30) = 15, so 2 packs of 10.
        $this->assertSame(['6.000', '0.000', '0.100', 2, '20.000', false, 1000], [$row['onOrder'], $row['incoming'], $row['averageDailyUse'], $row['suggestedPacks'], $row['suggestedQuantity'], $row['coveredByOrders'], $row['estimateSen']]);
        $this->assertSame(10, $row['supplier']['leadTimeDays']);

        // Someone without the cost permission never receives the estimate.
        $supervisorRow = collect($this->page($f['actors']->actor('ca_supervisor'))['rows'])->firstWhere('skuCode', $sku);
        $this->assertArrayNotHasKey('estimateSen', $supervisorRow);

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
}
