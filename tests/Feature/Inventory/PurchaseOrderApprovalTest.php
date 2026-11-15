<?php

namespace Tests\Feature\Inventory;

use App\Domain\Access\BranchAccessService;
use App\Domain\Access\PermissionCatalogue;
use App\Domain\Organisation\Inventory\Models\PurchaseOrder;
use App\Domain\Organisation\Inventory\Services\ProcurementService;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Tests\Feature\Visit\VisitTestCase;
use Tests\Support\VerifiedReceivingFixture;

/** STK-19: a purchase order is approved by a Finance Officer or Director who neither raised nor submitted it. */
final class PurchaseOrderApprovalTest extends VisitTestCase
{
    private function as(User $actor): User
    {
        app(BranchAccessService::class)->select($actor, $this->branch);

        return $actor;
    }

    private function staff(string $role): User
    {
        return (new VerifiedReceivingFixture($this->branch))->actor($role);
    }

    private function cmd(PurchaseOrder $order, array $extra = []): array
    {
        return ['expected_branch_id' => $this->branch->id, 'lock_version' => $order->refresh()->lock_version, ...$extra];
    }

    /** A submitted order raised by $creator and submitted by $submitter (default: the creator). */
    private function submitted(array $f, User $creator, ?User $submitter = null): PurchaseOrder
    {
        $service = app(ProcurementService::class);
        $order = $service->create($this->as($creator), ['expected_branch_id' => $this->branch->id, 'supplier_public_id' => $f['supplier']->public_id,
            'destination_location_public_id' => $f['location']->public_id, 'lines' => [['sku_public_id' => $f['sku']->public_id, 'quantity' => '5.000', 'unit_cost_sen' => 100]]]);

        return $service->submit($this->as($submitter ?? $creator), $order, $this->cmd($order));
    }

    public function test_the_finance_officer_and_director_hold_approval(): void
    {
        $holders = collect(PermissionCatalogue::roles())->filter(fn (array $p) => in_array(ProcurementService::APPROVE_PERMISSION, $p, true))->keys()->sort()->values()->all();
        $this->assertSame(['director', 'finance_officer'], $holders);
        $this->assertTrue(Role::findByName('finance_officer')->hasPermissionTo(ProcurementService::APPROVE_PERMISSION));
        $this->assertFalse(Role::findByName('ca_supervisor')->hasPermissionTo(ProcurementService::APPROVE_PERMISSION));
    }

    public function test_a_finance_officer_approves_another_persons_order_but_never_one_they_raised_or_submitted(): void
    {
        $f = (new VerifiedReceivingFixture($this->branch))->build('10.000');
        $service = app(ProcurementService::class);
        $finance = $this->staff('finance_officer');
        $supervisor = $this->staff('ca_supervisor');

        // A CA Supervisor's order: the Finance Officer approves it.
        $order = $this->submitted($f, $supervisor);
        $approved = $service->approve($this->as($finance), $order, $this->cmd($order));
        $this->assertSame([PurchaseOrder::STATUS_APPROVED, $finance->id], [$approved->status, $approved->approved_by_user_id]);

        // Their own order: refused, and nothing changes.
        $own = $this->submitted($f, $finance);
        try {
            $service->approve($this->as($finance), $own, $this->cmd($own));
            $this->fail('A Finance Officer approved their own order.');
        } catch (AuthorizationException $e) {
            $this->assertStringContainsString('different Finance Officer or Director', $e->getMessage());
        }
        $this->assertSame(PurchaseOrder::STATUS_SUBMITTED, $own->refresh()->status);

        // An order they only submitted for someone else: refused too.
        $other = $this->staff('finance_officer');
        $forwarded = $this->submitted($f, $supervisor, $other);
        $this->assertThrows(fn () => $service->approve($this->as($other), $forwarded, $this->cmd($forwarded)), AuthorizationException::class);
        // The Director approves it.
        $this->assertSame(PurchaseOrder::STATUS_APPROVED, $service->approve($this->as($f['director']), $forwarded, $this->cmd($forwarded))->status);
    }

    public function test_an_approved_order_is_never_cancelled_by_whoever_raised_it(): void
    {
        $f = (new VerifiedReceivingFixture($this->branch))->build('10.000');
        $service = app(ProcurementService::class);
        $finance = $this->staff('finance_officer');
        $other = $this->staff('finance_officer');
        $order = $this->submitted($f, $finance);
        $service->approve($this->as($other), $order, $this->cmd($order));

        $this->assertThrows(fn () => $service->cancel($this->as($finance), $order, $this->cmd($order, ['reason' => 'Synthetic cancel'])), AuthorizationException::class);
        $this->assertSame(PurchaseOrder::STATUS_APPROVED, $order->refresh()->status);
        // Someone without approval authority never cancels an approved order either.
        $supervisor = $this->staff('ca_supervisor');
        $this->assertThrows(fn () => $service->cancel($this->as($supervisor), $order, $this->cmd($order, ['reason' => 'Synthetic cancel'])), AuthorizationException::class);
        $this->assertSame(PurchaseOrder::STATUS_CANCELLED, $service->cancel($this->as($other), $order, $this->cmd($order, ['reason' => 'Synthetic cancel']))->status);
    }

    public function test_postgresql_refuses_an_approval_or_cancellation_by_the_person_who_raised_the_order(): void
    {
        $f = (new VerifiedReceivingFixture($this->branch))->build('10.000');
        $finance = $this->staff('finance_officer');
        $order = $this->submitted($f, $finance);
        $attempt = function (array $values): void {
            DB::beginTransaction();
            try {
                DB::table('inventory_purchase_orders')->where('id', $values['id'])->update($values['set']);
                $this->fail('PostgreSQL accepted '.json_encode($values['set']));
            } catch (QueryException $e) {
                $this->assertStringContainsString('did not raise or submit it', $e->getMessage());
            } finally {
                DB::rollBack();
            }
        };
        $attempt(['id' => $order->id, 'set' => ['status' => 'approved', 'approved_by_user_id' => $finance->id, 'approved_at' => now()->utc(), 'lock_version' => $order->lock_version + 1]]);
        $attempt(['id' => $f['order']->id, 'set' => ['status' => 'cancelled', 'cancelled_by_user_id' => $f['order']->created_by_user_id, 'cancelled_at' => now()->utc(), 'cancellation_reason' => 'x', 'lock_version' => $f['order']->lock_version + 1]]);
    }

    public function test_the_panel_hides_approve_from_whoever_raised_the_order(): void
    {
        $f = (new VerifiedReceivingFixture($this->branch))->build('10.000');
        $finance = $this->staff('finance_officer');
        $own = $this->submitted($f, $finance);
        $orders = collect($this->actingAs($this->as($finance))->get(route('inventory.index'))->assertOk()->viewData('page')['props']['operations']['purchaseOrders']);
        $this->assertTrue($orders->firstWhere('publicId', $own->public_id)['raisedByYou']);
        $this->assertFalse($orders->firstWhere('publicId', $f['order']->public_id)['raisedByYou']);
        $this->assertArrayNotHasKey('created_by_user_id', $orders->first());
        $this->assertArrayNotHasKey('submitted_by_user_id', $orders->first());
    }
}
