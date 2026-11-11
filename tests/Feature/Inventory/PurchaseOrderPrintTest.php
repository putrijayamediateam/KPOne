<?php

namespace Tests\Feature\Inventory;

use App\Domain\Access\BranchAccessService;
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
}
