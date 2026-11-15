<?php

namespace Tests\Feature\Inventory;

use App\Domain\Access\BranchAccessService;
use App\Domain\Organisation\Inventory\Models\InventoryItem;
use App\Domain\Organisation\Inventory\Models\InventoryLocation;
use App\Domain\Organisation\Inventory\Models\InventorySku;
use App\Domain\Organisation\Inventory\Services\ProcurementService;
use App\Domain\Organisation\Inventory\Services\SupplierTermsService;
use App\Domain\Organisation\Models\Branch;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Visit\VisitTestCase;
use Tests\Support\VerifiedReceivingFixture;

/** STK-20: a printable purchase order (approved by B.E.R.N.I.S.S for the owner, 2026-11-11). */
final class PurchaseOrderPrintTest extends VisitTestCase
{
    private function staff(string $role, ?Branch $branch = null): User
    {
        $user = (new VerifiedReceivingFixture($branch ?? $this->branch))->actor($role);
        app(BranchAccessService::class)->select($user, $branch ?? $this->branch);

        return $user;
    }

    /** @return array<string, mixed> */
    private function page(User $actor, string $publicId): array
    {
        return $this->actingAs($actor)->get(route('inventory.purchase-orders.print', $publicId))->assertOk()->viewData('page')['props'];
    }

    public function test_an_approved_order_prints_with_prices_and_packs_for_a_cost_holder(): void
    {
        $f = (new VerifiedReceivingFixture($this->branch))->build('10.000');
        $finance = $this->staff('finance_officer');
        app(SupplierTermsService::class)->create($finance, ['expected_branch_id' => $this->branch->id, 'idempotency_key' => (string) Str::uuid(),
            'supplier_public_id' => $f['supplier']->public_id, 'sku_public_id' => $f['sku']->public_id, 'pack_description' => 'Box of 5', 'pack_quantity' => '5',
            'pack_price_sen' => 600, 'lead_time_days' => 3, 'minimum_order_packs' => null, 'is_preferred' => true]);

        $props = $this->page($finance, $f['order']->public_id);
        $order = $props['order'];
        $this->assertSame([true, 'approved', true, $f['supplier']->name], [$order['isOrder'], $order['status'], $order['showPrices'], $order['supplier']['name']]);
        $line = $order['lines'][0];
        // 10 units at RM 1.25 each (the fixture's estimate) in two boxes of 5.
        $this->assertSame(['10.000', ['count' => 2, 'description' => 'Box of 5'], 125, 1250], [$line['quantity'], $line['packs'], $line['unitCostSen'], $line['lineSen']]);
        $this->assertSame(1250, $order['totalSen']);
        $this->assertSame($f['director']->name, $order['approvedBy']);
        $this->assertArrayHasKey('details', $props['letterhead']);
        $audit = DB::table('audit_logs')->where('event', 'inventory.purchase_order.printed')->sole();
        $this->assertSame(['prices', 'purchase_order_id', 'status'], collect(json_decode($audit->metadata, true))->keys()->sort()->values()->all());
    }

    public function test_without_the_cost_permission_no_price_reaches_the_page_and_a_draft_is_not_an_order(): void
    {
        $f = (new VerifiedReceivingFixture($this->branch))->build('10.000');
        $supervisor = $this->staff('ca_supervisor');
        $order = $this->page($supervisor, $f['order']->public_id)['order'];
        $this->assertFalse($order['showPrices']);
        $this->assertNull($order['totalSen']);
        $this->assertArrayNotHasKey('unitCostSen', $order['lines'][0]);
        $this->assertArrayNotHasKey('lineSen', $order['lines'][0]);

        $draft = app(ProcurementService::class)->create($supervisor, ['expected_branch_id' => $this->branch->id, 'supplier_public_id' => $f['supplier']->public_id,
            'destination_location_public_id' => $f['location']->public_id, 'lines' => [['sku_public_id' => $f['sku']->public_id, 'quantity' => '3.000', 'unit_cost_sen' => 100]]]);
        $printed = $this->page($supervisor, $draft->public_id)['order'];
        $this->assertSame([false, 'draft', null], [$printed['isOrder'], $printed['status'], $printed['approvedBy']]);
    }

    public function test_only_order_staff_at_the_orders_branch_may_print(): void
    {
        $f = (new VerifiedReceivingFixture($this->branch))->build('10.000');
        $this->actingAs($this->staff('ca'))->get(route('inventory.purchase-orders.print', $f['order']->public_id))->assertForbidden();
        $other = Branch::query()->where('organisation_id', $this->branch->organisation_id)->whereKeyNot($this->branch->id)->firstOrFail();
        $elsewhere = $this->staff('finance_officer', $other);
        $this->actingAs($elsewhere)->get(route('inventory.purchase-orders.print', $f['order']->public_id))->assertNotFound();
        $this->assertSame(0, DB::table('audit_logs')->where('event', 'inventory.purchase_order.printed')->count());
    }

    public function test_rounding_packs_names_cancelled_and_warehouse_orders(): void
    {
        $f = (new VerifiedReceivingFixture($this->branch))->build('10.000');
        $finance = $this->staff('finance_officer');
        $service = app(ProcurementService::class);
        $draft = fn (string $quantity, int $sen, ?string $location = null) => $service->create($finance, ['expected_branch_id' => $this->branch->id, 'supplier_public_id' => $f['supplier']->public_id,
            'destination_location_public_id' => $location ?? $f['location']->public_id, 'lines' => [['sku_public_id' => $f['sku']->public_id, 'quantity' => $quantity, 'unit_cost_sen' => $sen]]]);

        // Half a unit at RM 1.25 is 62.5 sen, which rounds half up to 63 sen, as other KPOne money does.
        $half = $this->page($finance, $draft('0.500', 125)->public_id)['order'];
        $this->assertSame([63, 63], [$half['lines'][0]['lineSen'], $half['totalSen']]);
        $this->assertSame($finance->name, $half['preparedBy']);

        // A pack that does not divide the quantity, or an inactive term, shows no pack.
        $term = app(SupplierTermsService::class)->create($finance, ['expected_branch_id' => $this->branch->id, 'idempotency_key' => (string) Str::uuid(),
            'supplier_public_id' => $f['supplier']->public_id, 'sku_public_id' => $f['sku']->public_id, 'pack_description' => 'Box of 3', 'pack_quantity' => '3',
            'pack_price_sen' => null, 'lead_time_days' => 3, 'minimum_order_packs' => null, 'is_preferred' => true]);
        $this->assertNull($this->page($finance, $f['order']->public_id)['order']['lines'][0]['packs']);
        DB::table('inventory_supplier_sku_terms')->where('id', $term->id)->update(['pack_quantity' => '5.000', 'is_active' => false, 'is_preferred' => false, 'lock_version' => DB::raw('lock_version + 1')]);
        $this->assertNull($this->page($finance, $f['order']->public_id)['order']['lines'][0]['packs']);

        // A cancelled order is not an order.
        $cancelled = $draft('1.000', 100);
        $service->cancel($finance, $cancelled, ['expected_branch_id' => $this->branch->id, 'lock_version' => $cancelled->lock_version, 'reason' => 'Synthetic']);
        $this->assertSame([false, 'cancelled'], [$this->page($finance, $cancelled->public_id)['order']['isOrder'], 'cancelled']);

        // A warehouse order prints for a holder of the warehouse permission and says so.
        $warehouse = new InventoryLocation;
        $warehouse->forceFill(['public_id' => (string) Str::uuid(), 'organisation_id' => $this->branch->organisation_id, 'branch_id' => null, 'code' => 'WH-'.Str::upper(Str::random(6)), 'name' => 'Synthetic warehouse', 'type' => 'branch_store', 'is_active' => true])->save();
        $finance->givePermissionTo(ProcurementService::WAREHOUSE_PERMISSION);
        $finance = $finance->fresh();
        $order = $draft('2.000', 100, $warehouse->public_id);
        $printed = $this->page($finance, $order->public_id)['order'];
        $this->assertSame(['branch' => 'Organisation warehouse', 'location' => 'Synthetic warehouse'], $printed['deliverTo']);
        $this->actingAs($this->staff('finance_officer'))->get(route('inventory.purchase-orders.print', $order->public_id))->assertNotFound();

        // An unknown logo is never served.
        $this->actingAs($finance)->get(route('inventory.purchase-orders.letterhead-logo', (string) Str::uuid()))->assertNotFound();
    }

    public function test_a_two_page_draft_prints_every_line_and_stays_not_an_order(): void
    {
        $f = (new VerifiedReceivingFixture($this->branch))->build('10.000');
        $supervisor = $this->staff('ca_supervisor');
        // 22 distinct synthetic SKUs (a test fixture), enough to run onto a second A4 page.
        $lines = [['sku_public_id' => $f['sku']->public_id, 'quantity' => '10.000', 'unit_cost_sen' => 25]];
        for ($i = 2; $i <= 22; $i++) {
            $item = InventoryItem::query()->findOrFail($f['sku']->inventory_item_id)->replicate();
            $item->forceFill(['public_id' => (string) Str::uuid(), 'code' => 'PV20T-'.$i])->save();
            $sku = InventorySku::query()->findOrFail($f['sku']->id)->replicate();
            $sku->forceFill(['public_id' => (string) Str::uuid(), 'inventory_item_id' => $item->id, 'sku_code' => 'PV20T-'.$i, 'barcode' => null])->save();
            $lines[] = ['sku_public_id' => $sku->public_id, 'quantity' => (10 * $i).'.000', 'unit_cost_sen' => 25 + 5 * ($i - 1)];
        }
        $draft = app(ProcurementService::class)->create($supervisor, ['expected_branch_id' => $this->branch->id, 'supplier_public_id' => $f['supplier']->public_id,
            'destination_location_public_id' => $f['location']->public_id, 'lines' => $lines]);

        $order = $this->page($this->staff('finance_officer'), $draft->public_id)['order'];
        $this->assertSame([false, 'draft', null], [$order['isOrder'], $order['status'], $order['approvedBy']]);
        $this->assertCount(22, $order['lines']);
        $this->assertSame(range(1, 22), array_column($order['lines'], 'number'));
        // RM 2,403.50 in whole sen; the page adds the thousands separator.
        $this->assertSame(240350, $order['totalSen']);
    }
}
