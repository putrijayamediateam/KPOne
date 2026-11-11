<?php

namespace Tests\Feature\Inventory;

use App\Domain\Access\BranchAccessService;
use App\Domain\Organisation\Inventory\Models\PurchaseOrder;
use App\Domain\Organisation\Inventory\Services\ProcurementService;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Visit\VisitTestCase;
use Tests\Support\VerifiedReceivingFixture;

/** STK-21 (approved by B.E.R.N.I.S.S for the owner, 2026-11-11): "Close the rest" of a partly received purchase order. */
final class PurchaseOrderShortCloseTest extends VisitTestCase
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

    /** The fixture's order (10 ordered, raised and submitted by its Finance Officer) with 2 received. */
    private function partlyReceived(): array
    {
        $f = (new VerifiedReceivingFixture($this->branch))->build('10.000');
        app(ProcurementService::class)->receive($this->as($f['receiver']), $f['order'], $f['payload']);
        $this->assertSame(PurchaseOrder::STATUS_PARTIALLY_RECEIVED, $f['order']->refresh()->status);

        return $f;
    }

    public function test_a_different_finance_officer_closes_the_rest_with_no_stock_or_money_change(): void
    {
        $f = $this->partlyReceived();
        $finance = $this->staff('finance_officer');
        $movements = DB::table('stock_movements')->count();
        $balances = DB::table('inventory_stock_balances')->orderBy('id')->get()->toArray();

        $closed = app(ProcurementService::class)->shortClose($this->as($finance), $f['order'], $this->cmd($f['order'], ['reason' => 'supplier_cannot_supply', 'note' => '  Out of stock until next year  ']));

        $this->assertSame([PurchaseOrder::STATUS_CLOSED, 'short', 'supplier_cannot_supply', 'Out of stock until next year', $finance->id],
            [$closed->status, $closed->close_kind, $closed->short_close_reason, $closed->short_close_note, (int) $closed->closed_by_user_id]);
        $this->assertSame('8.000', DB::table('inventory_purchase_order_lines')->where('purchase_order_id', $closed->id)->value('not_delivered_quantity'));
        $this->assertSame($movements, DB::table('stock_movements')->count());
        $this->assertEquals($balances, DB::table('inventory_stock_balances')->orderBy('id')->get()->toArray());
        $audit = DB::table('audit_logs')->where('event', 'inventory.purchase_order.short_closed')->sole();
        $metadata = json_decode($audit->metadata, true);
        $this->assertSame(['from', 'lines_not_delivered', 'reason', 'to'], collect($metadata)->keys()->sort()->values()->all());
        $this->assertSame(1, $metadata['lines_not_delivered']);
        $this->assertStringNotContainsString('Out of stock', (string) $audit->metadata);

        // A closed order takes no more receipts.
        $retry = [...$f['payload'], 'lock_version' => $closed->lock_version, 'idempotency_key' => (string) Str::uuid(), 'delivery_reference' => 'SYN-LATE-'.Str::random(6)];
        $this->assertThrows(fn () => app(ProcurementService::class)->receive($this->as($f['receiver']), $closed, $retry), ValidationException::class);
    }

    public function test_never_by_whoever_raised_it_and_only_with_approval_authority(): void
    {
        $f = $this->partlyReceived();
        $service = app(ProcurementService::class);
        $order = $f['order'];
        $this->assertThrows(fn () => $service->shortClose($this->as($f['finance']), $order, $this->cmd($order, ['reason' => 'discontinued'])), AuthorizationException::class);
        $this->assertThrows(fn () => $service->shortClose($this->as($this->staff('ca_supervisor')), $order, $this->cmd($order, ['reason' => 'discontinued'])), AuthorizationException::class);
        $this->assertSame(PurchaseOrder::STATUS_PARTIALLY_RECEIVED, $order->refresh()->status);
        $this->assertSame('short', $service->shortClose($this->as($f['director']), $order, $this->cmd($order, ['reason' => 'discontinued']))->close_kind);
    }

    public function test_reason_note_and_status_rules(): void
    {
        $f = (new VerifiedReceivingFixture($this->branch))->build('10.000');
        $service = app(ProcurementService::class);
        $director = $this->as($f['director']);
        $order = $f['order'];
        // An approved order with nothing received is cancelled, not closed short.
        $this->assertThrows(fn () => $service->shortClose($director, $order, $this->cmd($order, ['reason' => 'discontinued'])), ValidationException::class);
        $service->receive($this->as($f['receiver']), $order, $f['payload']);
        foreach ([[], ['reason' => 'lost'], ['reason' => 'other'], ['reason' => 'other', 'note' => '   '], ['reason' => 'discontinued', 'note' => str_repeat('x', 201)]] as $bad) {
            $this->assertThrows(fn () => $service->shortClose($this->as($f['director']), $order, $this->cmd($order, $bad)), ValidationException::class);
        }
        // A stale page is refused.
        $this->assertThrows(fn () => $service->shortClose($this->as($f['director']), $order, ['expected_branch_id' => $this->branch->id, 'lock_version' => 1, 'reason' => 'discontinued']), ValidationException::class);
        $closed = $service->shortClose($this->as($f['director']), $order, $this->cmd($order, ['reason' => 'other', 'note' => 'Supplier merged']));
        $this->assertSame(['other', 'Supplier merged'], [$closed->short_close_reason, $closed->short_close_note]);
        // A second attempt finds the order already closed.
        $this->assertThrows(fn () => $service->shortClose($this->as($f['director']), $order, $this->cmd($order, ['reason' => 'discontinued'])), ValidationException::class);
    }

    public function test_a_waiting_receiving_review_blocks_it_in_the_service_and_postgresql(): void
    {
        $f = $this->partlyReceived();
        $order = $f['order']->refresh();
        DB::table('inventory_receiving_reviews')->insert(['public_id' => (string) Str::uuid(), 'organisation_id' => $order->organisation_id, 'branch_id' => $this->branch->id, 'purchase_order_id' => $order->id,
            'receiver_user_id' => $f['receiver']->id, 'kind' => 'receipt', 'payload' => '{}', 'reason_codes' => '[]', 'payload_hash' => str_repeat('a', 64), 'order_lock_version' => $order->lock_version,
            'retry_key' => (string) Str::uuid(), 'lock_version' => 1, 'created_at' => now()->utc()]);
        $this->assertThrows(fn () => app(ProcurementService::class)->shortClose($this->as($f['director']), $order, $this->cmd($order, ['reason' => 'discontinued'])), ValidationException::class);

        DB::beginTransaction();
        try {
            DB::table('inventory_purchase_order_lines')->where('purchase_order_id', $order->id)->update(['not_delivered_quantity' => '8.000']);
            DB::table('inventory_purchase_orders')->where('id', $order->id)->update(['status' => 'closed', 'close_kind' => 'short', 'short_close_reason' => 'discontinued', 'closed_by_user_id' => $f['director']->id, 'closed_at' => now()->utc(), 'lock_version' => $order->lock_version + 1]);
            $this->fail('PostgreSQL accepted a short close with a waiting review.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('receiving review still waiting', $e->getMessage());
        } finally {
            DB::rollBack();
        }
    }

    public function test_postgresql_repeats_the_short_close_rules(): void
    {
        $f = $this->partlyReceived();
        $order = $f['order']->refresh();
        $close = ['status' => 'closed', 'close_kind' => 'short', 'short_close_reason' => 'discontinued', 'closed_by_user_id' => $f['director']->id, 'closed_at' => now()->utc(), 'lock_version' => $order->lock_version + 1];
        $attempt = function (array $set, string $message, bool $lines = true) use ($order): void {
            DB::beginTransaction();
            try {
                if ($lines) {
                    DB::table('inventory_purchase_order_lines')->where('purchase_order_id', $order->id)->update(['not_delivered_quantity' => '8.000']);
                }
                DB::table('inventory_purchase_orders')->where('id', $order->id)->update($set);
                $this->fail('PostgreSQL accepted '.json_encode($set));
            } catch (QueryException $e) {
                $this->assertStringContainsString($message, $e->getMessage());
            } finally {
                DB::rollBack();
            }
        };
        $attempt([...$close, 'closed_by_user_id' => $order->created_by_user_id], 'did not raise or submit it');
        $attempt($close, 'records what was not delivered', false);
        $attempt([...$close, 'close_kind' => 'complete', 'short_close_reason' => null], 'closed short only when it is partly received');
        $attempt([...$close, 'short_close_reason' => 'other'], 'purchase_orders_close_shape');
        $attempt([...$close, 'short_close_reason' => 'whatever'], 'purchase_orders_close_shape');

        // A frozen quantity must equal ordered less received.
        DB::beginTransaction();
        try {
            DB::table('inventory_purchase_order_lines')->where('purchase_order_id', $order->id)->update(['not_delivered_quantity' => '5.000']);
            $this->fail('PostgreSQL accepted a wrong not-delivered quantity.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('purchase_order_lines_not_delivered_shape', $e->getMessage());
        } finally {
            DB::rollBack();
        }
    }

    public function test_a_complete_close_is_recorded_as_complete(): void
    {
        $f = (new VerifiedReceivingFixture($this->branch))->build('2.000');
        $service = app(ProcurementService::class);
        $service->receive($this->as($f['receiver']), $f['order'], $f['payload']);
        $this->assertSame(PurchaseOrder::STATUS_FULLY_RECEIVED, $f['order']->refresh()->status);
        $closed = $service->close($this->as($f['director']), $f['order'], $this->cmd($f['order']));
        $this->assertSame(['closed', 'complete', null], [$closed->status, $closed->close_kind, $closed->short_close_reason]);
    }

    public function test_the_panel_shows_what_is_outstanding_and_what_was_not_delivered(): void
    {
        $f = $this->partlyReceived();
        $director = $this->as($f['director']);
        $orders = fn () => collect($this->actingAs($director)->get(route('inventory.index'))->assertOk()->viewData('page')['props']['operations']['purchaseOrders']);
        $line = $orders()->firstWhere('publicId', $f['order']->public_id)['lines'][0];
        $this->assertSame(['10.000', '2.000', '8.000', null], [$line['orderedQuantity'], $line['receivedQuantity'], $line['outstandingQuantity'], $line['notDeliveredQuantity']]);

        $this->actingAs($director)->post(route('inventory.purchase-orders.short-close', $f['order']->public_id), $this->cmd($f['order'], ['reason' => 'ordered_elsewhere']))->assertRedirect(route('inventory.index'));
        $row = $orders()->firstWhere('publicId', $f['order']->public_id);
        $this->assertSame(['closed', 'short', 'ordered_elsewhere', null, '8.000'], [$row['status'], $row['closeKind'], $row['shortCloseReason'], $row['shortCloseNote'], $row['lines'][0]['notDeliveredQuantity']]);
        // The route is only for holders of the approval permission.
        $this->actingAs($this->as($f['receiver']))->post(route('inventory.purchase-orders.short-close', $f['order']->public_id), $this->cmd($f['order'], ['reason' => 'discontinued']))->assertForbidden();
    }

    public function test_a_review_left_behind_by_a_later_receipt_does_not_block_it(): void
    {
        $f = $this->partlyReceived();
        $order = $f['order']->refresh();
        // A review pinned to an older version of the order can never be posted (the review service refuses it).
        DB::table('inventory_receiving_reviews')->insert(['public_id' => (string) Str::uuid(), 'organisation_id' => $order->organisation_id, 'branch_id' => $this->branch->id, 'purchase_order_id' => $order->id,
            'receiver_user_id' => $f['receiver']->id, 'kind' => 'receipt', 'payload' => '{}', 'reason_codes' => '[]', 'payload_hash' => str_repeat('b', 64), 'order_lock_version' => $order->lock_version - 1,
            'retry_key' => (string) Str::uuid(), 'lock_version' => 1, 'created_at' => now()->utc()]);
        $closed = app(ProcurementService::class)->shortClose($this->as($f['director']), $order, $this->cmd($order, ['reason' => 'discontinued']));
        $this->assertSame('short', $closed->close_kind);
    }

    public function test_the_raiser_is_refused_on_the_inventory_page_with_the_reason(): void
    {
        $f = $this->partlyReceived();
        $this->actingAs($this->as($f['finance']))->from(route('inventory.index'))
            ->post(route('inventory.purchase-orders.short-close', $f['order']->public_id), $this->cmd($f['order'], ['reason' => 'discontinued']), ['X-Inertia-Error-Bag' => 'inventoryOperations'])
            ->assertRedirect(route('inventory.index'))->assertSessionHasErrors('purchase_order');
        $this->assertSame(PurchaseOrder::STATUS_PARTIALLY_RECEIVED, $f['order']->refresh()->status);
        $this->assertSame(0, DB::table('audit_logs')->where('event', 'inventory.purchase_order.short_closed')->count());
    }
}
