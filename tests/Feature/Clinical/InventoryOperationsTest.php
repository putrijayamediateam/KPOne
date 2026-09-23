<?php

namespace Tests\Feature\Clinical;

use App\Domain\Access\PermissionCatalogue;
use App\Domain\Identity\Models\StaffBranchAssignment;
use App\Domain\Organisation\Inventory\Models\GoodsReceipt;
use App\Domain\Organisation\Inventory\Models\InventoryBatch;
use App\Domain\Organisation\Inventory\Models\InventoryLocation;
use App\Domain\Organisation\Inventory\Models\InventoryStocktake;
use App\Domain\Organisation\Inventory\Models\InventorySupplier;
use App\Domain\Organisation\Inventory\Models\PurchaseOrder;
use App\Domain\Organisation\Inventory\Models\StockMovement;
use App\Domain\Organisation\Inventory\Models\StockRequest;
use App\Domain\Organisation\Inventory\Services\InventoryAuthorityService;
use App\Domain\Organisation\Inventory\Services\InventoryControlService;
use App\Domain\Organisation\Inventory\Services\InventoryMovementService;
use App\Domain\Organisation\Inventory\Services\InventoryReferenceAdministrationService;
use App\Domain\Organisation\Inventory\Services\ProcurementService;
use App\Domain\Organisation\Inventory\Services\StockRequestService;
use App\Domain\Organisation\Inventory\Services\SupplierAdministrationService;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Organisation\Models\Organisation;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LogicException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class InventoryOperationsTest extends ClinicalTestCase
{
    public function test_supplier_governance_is_tenant_scoped_unique_and_retains_purchase_history(): void
    {
        $finance = $this->actor('finance_officer');
        $this->selectBranch($finance);
        $service = app(SupplierAdministrationService::class);
        $supplier = $service->create($finance, $this->supplierAttributes());

        $this->expectValidation(fn () => $service->create($finance, $this->supplierAttributes()), 'code');
        $this->assertDatabaseHas('inventory_suppliers', ['id' => $supplier->id, 'organisation_id' => $finance->organisation_id, 'is_active' => true]);
        $this->assertDatabaseHas('audit_logs', ['event' => 'inventory.supplier.created', 'subject_id' => $supplier->id]);
        $supplier = $service->update($finance, $supplier, ['name' => 'Synthetic Updated Supplier']);
        $this->assertSame('Synthetic Updated Supplier', $supplier->name);
        $updatedAuditCount = DB::table('audit_logs')->where('event', 'inventory.supplier.updated')->count();
        $this->expectValidation(fn () => $service->update($finance, $supplier, ['is_active' => false]), 'is_active');
        $this->assertTrue($supplier->refresh()->is_active);
        $this->assertSame($updatedAuditCount, DB::table('audit_logs')->where('event', 'inventory.supplier.updated')->count());
        $supplier = $service->setActive($finance, $supplier, false);
        $this->assertFalse($supplier->is_active);
        $this->assertDatabaseHas('audit_logs', ['event' => 'inventory.supplier.deactivated', 'subject_id' => $supplier->id]);
        $supplier = $service->setActive($finance, $supplier, true);
        $this->assertTrue($supplier->is_active);
        $this->assertDatabaseHas('audit_logs', ['event' => 'inventory.supplier.activated', 'subject_id' => $supplier->id]);

        $fixture = $this->inventoryFixture($finance, 'SUP-HISTORY');
        $order = app(ProcurementService::class)->create($finance, $this->purchaseOrderAttributes($supplier, $fixture));
        $service->setActive($finance, $supplier, false);

        $this->assertDatabaseHas('inventory_purchase_orders', ['id' => $order->id, 'supplier_id' => $supplier->id]);
        $this->assertFalse($supplier->refresh()->is_active);
        $this->expectValidation(fn () => app(ProcurementService::class)->submit($finance, $order, $this->transition($order)), 'purchase_order');
        $this->assertSame(PurchaseOrder::STATUS_DRAFT, $order->refresh()->status);
        $this->assertSame(0, DB::table('stock_movements')->count());
    }

    public function test_supplier_mutations_require_an_active_actor_with_a_current_assignment(): void
    {
        foreach (['inactive', 'ended', 'future'] as $state) {
            $finance = $this->actor('finance_officer');
            $this->selectBranch($finance);
            $service = app(SupplierAdministrationService::class);
            $suffix = Str::upper($state);
            $active = $service->create($finance, $this->supplierAttributes('AUTH-'.$suffix.'-ACTIVE'));
            $inactive = $service->create($finance, $this->supplierAttributes('AUTH-'.$suffix.'-INACTIVE'));
            $inactive = $service->setActive($finance, $inactive, false);

            if ($state === 'inactive') {
                $finance->forceFill(['is_active' => false])->save();
            } elseif ($state === 'ended') {
                StaffBranchAssignment::query()->where('staff_profile_id', $finance->staffProfile->id)
                    ->update(['valid_until' => now()->setTimezone($this->branch->timezone)->subDay()->toDateString()]);
            } else {
                StaffBranchAssignment::query()->where('staff_profile_id', $finance->staffProfile->id)
                    ->update(['valid_from' => now()->setTimezone($this->branch->timezone)->addDay()->toDateString(), 'valid_until' => null]);
            }
            if ($state !== 'inactive') {
                $effectiveDate = now()->setTimezone($this->branch->timezone)->toDateString();
                $this->assertSame(1, StaffBranchAssignment::query()->where('staff_profile_id', $finance->staffProfile->id)->count(), $state);
                if ($state === 'future') {
                    $assignment = StaffBranchAssignment::query()->where('staff_profile_id', $finance->staffProfile->id)->sole();
                    $validFrom = $assignment->valid_from->toDateString();
                    $this->assertGreaterThan($effectiveDate, $validFrom, $validFrom.' versus '.$effectiveDate);
                }
            }
            $this->expectAuthorization(fn () => app(InventoryAuthorityService::class)->lockForOrganisation($finance, SupplierAdministrationService::PERMISSION), $state.' authority');

            $supplierCount = InventorySupplier::query()->count();
            $auditCount = DB::table('audit_logs')->count();
            foreach ([
                'create' => fn () => $service->create($finance, $this->supplierAttributes('DENIED-'.$suffix)),
                'update' => fn () => $service->update($finance, $active, ['name' => 'Denied supplier update']),
                'deactivate' => fn () => $service->setActive($finance, $active, false),
                'activate' => fn () => $service->setActive($finance, $inactive, true),
            ] as $operation => $mutation) {
                $this->expectAuthorization($mutation, $state.' '.$operation);
            }

            $this->assertSame($supplierCount, InventorySupplier::query()->count());
            $this->assertSame($auditCount, DB::table('audit_logs')->count());
            $this->assertSame('Synthetic AUTH-'.$suffix.'-ACTIVE Supplier', $active->refresh()->name);
            $this->assertTrue($active->is_active);
            $this->assertFalse($inactive->refresh()->is_active);
        }
    }

    public function test_purchase_order_lifecycle_partial_and_final_receiving_are_atomic_and_idempotent(): void
    {
        $finance = $this->actor('finance_officer');
        $director = $this->actor('director');
        $receiver = $this->actor('ca_supervisor');
        $this->selectBranch($finance);
        $supplier = app(SupplierAdministrationService::class)->create($finance, $this->supplierAttributes('P0'));
        $fixture = $this->inventoryFixture($finance, 'P0');
        $service = app(ProcurementService::class);
        $order = $service->create($finance, $this->purchaseOrderAttributes($supplier, $fixture, '10.000'));
        $this->assertSame(PurchaseOrder::STATUS_DRAFT, $order->status);
        $this->assertSame(0, DB::table('stock_movements')->count());
        $order = $service->submit($finance, $order, $this->transition($order));
        $this->selectBranch($director);
        $order = $service->approve($director, $order, $this->transition($order));
        $this->assertSame(PurchaseOrder::STATUS_APPROVED, $order->status);
        $this->assertSame($director->id, $order->approved_by_user_id);
        $this->assertNotNull($order->approved_at);
        $this->assertSame(0, DB::table('stock_movements')->count());

        $line = $order->lines()->sole();
        $this->selectBranch($receiver);
        $firstKey = (string) Str::uuid();
        $firstInput = $this->receiptAttributes($order, $line->public_id, '4.000', $firstKey);
        $first = $service->receive($receiver, $order, $firstInput);
        $this->assertSame(PurchaseOrder::STATUS_PARTIALLY_RECEIVED, $order->refresh()->status);
        $this->assertSame('4.000', $order->lines()->sole()->received_quantity);
        $this->assertSame(1, DB::table('stock_movements')->where('movement_type', StockMovement::TYPE_PURCHASE_RECEIPT)->count());
        $again = $service->receive($receiver, $order, $firstInput);
        $this->assertSame($first->id, $again->id);
        $this->assertSame(1, GoodsReceipt::query()->count());
        $this->assertSame(1, DB::table('stock_movements')->count());

        $second = $service->receive($receiver, $order->refresh(), $this->receiptAttributes($order->refresh(), $line->public_id, '6.000', (string) Str::uuid()));
        $this->assertNotSame($first->id, $second->id);
        $this->assertSame(PurchaseOrder::STATUS_FULLY_RECEIVED, $order->refresh()->status);
        $this->assertSame('10.000', $order->lines()->sole()->received_quantity);
        $this->assertDatabaseHas('inventory_stock_balances', ['inventory_location_id' => $fixture['location']->id, 'inventory_sku_id' => $fixture['sku']->id, 'quantity' => 10]);
        $this->assertSame(2, DB::table('stock_movements')->where('movement_type', StockMovement::TYPE_PURCHASE_RECEIPT)->count());
        $this->assertDatabaseHas('audit_logs', ['event' => 'inventory.goods_receipt.posted', 'subject_id' => $second->id]);
    }

    public function test_goods_receipt_service_rejects_noncanonical_quantity_representations_without_mutation(): void
    {
        $finance = $this->actor('finance_officer');
        $director = $this->actor('director');
        $receiver = $this->actor('ca_supervisor');
        $this->selectBranch($finance);
        $supplier = app(SupplierAdministrationService::class)->create($finance, $this->supplierAttributes('RECEIPT-QUANTITY'));
        $fixture = $this->inventoryFixture($finance, 'RECEIPT-QUANTITY');
        $service = app(ProcurementService::class);
        $order = $service->create($finance, $this->purchaseOrderAttributes($supplier, $fixture, '10.000'));
        $order = $service->submit($finance, $order, $this->transition($order));
        $this->selectBranch($director);
        $order = $service->approve($director, $order, $this->transition($order));
        $this->selectBranch($receiver);
        $line = $order->lines()->sole();
        $baseline = [
            'version' => $order->lock_version,
            'receipts' => GoodsReceipt::query()->count(),
            'batches' => DB::table('inventory_batches')->count(),
            'balances' => DB::table('inventory_stock_balances')->count(),
            'movements' => StockMovement::query()->count(),
            'audits' => DB::table('audit_logs')->count(),
        ];
        $invalid = [
            ['', 'lines.0.quantity'],
            [' ', 'lines.0.quantity'],
            [' 1', 'lines.0.quantity'],
            ['1 ', 'lines.0.quantity'],
            ['+1', 'lines.0.quantity'],
            ['-1', 'lines.0.quantity'],
            ['.5', 'lines.0.quantity'],
            ['1.', 'lines.0.quantity'],
            ['1e3', 'lines.0.quantity'],
            ['0x10', 'lines.0.quantity'],
            ['1,5', 'lines.0.quantity'],
            ['NaN', 'lines.0.quantity'],
            ['Infinity', 'lines.0.quantity'],
            ['1.2345', 'lines.0.quantity'],
            ['0.0004', 'lines.0.quantity'],
            ['1000000000000', 'lines.0.quantity'],
            ['0', 'lines'],
            ['0.000', 'lines'],
        ];

        foreach ($invalid as [$quantity, $field]) {
            $this->expectValidation(
                fn () => $service->receive(
                    $receiver,
                    $order,
                    $this->receiptAttributes($order, $line->public_id, $quantity, (string) Str::uuid()),
                ),
                $field,
            );
        }

        $this->assertSame(PurchaseOrder::STATUS_APPROVED, $order->refresh()->status);
        $this->assertSame($baseline['version'], $order->lock_version);
        $this->assertSame('0.000', $line->refresh()->received_quantity);
        $this->assertSame($baseline['receipts'], GoodsReceipt::query()->count());
        $this->assertSame($baseline['batches'], DB::table('inventory_batches')->count());
        $this->assertSame($baseline['balances'], DB::table('inventory_stock_balances')->count());
        $this->assertSame($baseline['movements'], StockMovement::query()->count());
        $this->assertSame($baseline['audits'], DB::table('audit_logs')->count());
    }

    public function test_goods_receipt_http_boundary_rejects_missing_malformed_and_foreign_session_nonces_before_mutation(): void
    {
        $finance = $this->actor('finance_officer');
        $director = $this->actor('director');
        $receiverA = $this->actor('ca_supervisor');
        $receiverB = $this->actor('ca_supervisor');
        $this->selectBranch($finance);
        $supplier = app(SupplierAdministrationService::class)->create($finance, $this->supplierAttributes('RECEIPT-SESSION'));
        $fixture = $this->inventoryFixture($finance, 'RECEIPT-SESSION');
        $service = app(ProcurementService::class);
        $order = $service->create($finance, $this->purchaseOrderAttributes($supplier, $fixture, '5.000'));
        $order = $service->submit($finance, $order, $this->transition($order));
        $this->selectBranch($director);
        $order = $service->approve($director, $order, $this->transition($order));
        $line = $order->lines()->sole();

        $this->selectBranch($receiverA);
        $nonceA = $this->get(route('inventory.index'))->assertOk()->inertiaProps('receiptMemoryContext.sessionNonce');
        $input = $this->receiptAttributes($order, $line->public_id, '2.000', (string) Str::uuid());
        $baseline = [
            'order_version' => $order->lock_version,
            'received' => $line->received_quantity,
            'receipts' => GoodsReceipt::query()->count(),
            'balances' => DB::table('inventory_stock_balances')->count(),
            'movements' => StockMovement::query()->count(),
            'audits' => DB::table('audit_logs')->count(),
        ];

        foreach ([
            'missing' => $input,
            'malformed' => [...$input, 'receipt_session_nonce' => 'not-a-uuid'],
        ] as $case => $invalid) {
            $this->post(route('inventory.purchase-orders.receipts.store', $order), $invalid, ['X-Inertia-Error-Bag' => 'inventoryOperations'])
                ->assertSessionHasErrors('receipt_session_nonce');
            $this->assertSame($baseline['receipts'], GoodsReceipt::query()->count(), $case);
        }

        $this->selectBranch($receiverB);
        $this->post(route('inventory.purchase-orders.receipts.store', $order), [
            ...$input,
            'receipt_session_nonce' => $nonceA,
        ], ['X-Inertia-Error-Bag' => 'inventoryOperations'])->assertSessionHasErrors('receipt_session_nonce');

        $this->assertSame($baseline['order_version'], $order->refresh()->lock_version);
        $this->assertSame($baseline['received'], $line->refresh()->received_quantity);
        $this->assertSame($baseline['receipts'], GoodsReceipt::query()->count());
        $this->assertSame($baseline['balances'], DB::table('inventory_stock_balances')->count());
        $this->assertSame($baseline['movements'], StockMovement::query()->count());
        $this->assertSame($baseline['audits'], DB::table('audit_logs')->count());

        $nonceB = $this->get(route('inventory.index'))->assertOk()->inertiaProps('receiptMemoryContext.sessionNonce');
        $valid = [...$input, 'receipt_session_nonce' => $nonceB];
        $this->post(route('inventory.purchase-orders.receipts.store', $order), $valid)
            ->assertSessionHasNoErrors();
        $this->post(route('inventory.purchase-orders.receipts.store', $order), $valid)
            ->assertSessionHasNoErrors();

        $this->assertSame('2.000', $line->refresh()->received_quantity);
        $this->assertSame(1, GoodsReceipt::query()->count());
        $this->assertSame(1, StockMovement::query()->where('movement_type', StockMovement::TYPE_PURCHASE_RECEIPT)->count());
        $this->assertSame(1, DB::table('audit_logs')->where('event', 'inventory.goods_receipt.posted')->count());
    }

    public function test_goods_receipt_http_boundary_rejects_a_nonce_after_session_regeneration_before_mutation(): void
    {
        $finance = $this->actor('finance_officer');
        $director = $this->actor('director');
        $receiver = $this->actor('ca_supervisor');
        $this->selectBranch($finance);
        $supplier = app(SupplierAdministrationService::class)->create($finance, $this->supplierAttributes('RECEIPT-REGENERATED'));
        $fixture = $this->inventoryFixture($finance, 'RECEIPT-REGENERATED');
        $service = app(ProcurementService::class);
        $order = $service->create($finance, $this->purchaseOrderAttributes($supplier, $fixture, '5.000'));
        $order = $service->submit($finance, $order, $this->transition($order));
        $this->selectBranch($director);
        $order = $service->approve($director, $order, $this->transition($order));
        $this->selectBranch($receiver);
        $nonce = $this->get(route('inventory.index'))->assertOk()->inertiaProps('receiptMemoryContext.sessionNonce');
        $auditCount = DB::table('audit_logs')->count();

        $this->app['session']->regenerate();
        $marker = session('inertia.authentication_history_boundary.v2');
        session(['inertia.authentication_history_boundary.v2' => [...$marker, 'session_fingerprint' => str_repeat('a', 64)]]);
        $this->post(route('inventory.purchase-orders.receipts.store', $order), [
            ...$this->receiptAttributes($order, $order->lines()->sole()->public_id, '1.000', (string) Str::uuid()),
            'receipt_session_nonce' => $nonce,
        ], ['X-Inertia-Error-Bag' => 'inventoryOperations'])->assertSessionHasErrors('receipt_session_nonce');

        $this->assertSame(PurchaseOrder::STATUS_APPROVED, $order->refresh()->status);
        $this->assertSame('0.000', $order->lines()->sole()->received_quantity);
        $this->assertSame(0, GoodsReceipt::query()->count());
        $this->assertSame(0, StockMovement::query()->count());
        $this->assertSame($auditCount, DB::table('audit_logs')->count());
    }

    public function test_purchase_order_lines_are_editable_only_while_draft(): void
    {
        $finance = $this->actor('finance_officer');
        $this->selectBranch($finance);
        $supplier = app(SupplierAdministrationService::class)->create($finance, $this->supplierAttributes('DRAFT'));
        $fixture = $this->inventoryFixture($finance, 'DRAFT');
        $service = app(ProcurementService::class);
        $order = $service->create($finance, $this->purchaseOrderAttributes($supplier, $fixture, '10.000'));

        $order = $service->updateDraft($finance, $order, [...$this->purchaseOrderAttributes($supplier, $fixture, '7.000'), 'lock_version' => $order->lock_version]);
        $this->assertSame('7.000', $order->lines()->sole()->ordered_quantity);
        $this->assertDatabaseHas('audit_logs', ['event' => 'inventory.purchase_order.draft_updated', 'subject_id' => $order->id]);

        $order = $service->submit($finance, $order, $this->transition($order));
        $this->expectValidation(fn () => $service->updateDraft($finance, $order, [...$this->purchaseOrderAttributes($supplier, $fixture, '8.000'), 'lock_version' => $order->lock_version]), 'purchase_order');
        $this->assertSame('7.000', $order->lines()->sole()->ordered_quantity);
        $this->assertSame(0, DB::table('stock_movements')->count());
    }

    public function test_goods_receipt_idempotency_key_cannot_cross_purchase_orders(): void
    {
        $finance = $this->actor('finance_officer');
        $director = $this->actor('director');
        $receiver = $this->actor('ca_supervisor');
        $this->selectBranch($finance);
        $supplier = app(SupplierAdministrationService::class)->create($finance, $this->supplierAttributes('RECEIPT-KEY'));
        $fixture = $this->inventoryFixture($finance, 'RECEIPT-KEY');
        $service = app(ProcurementService::class);
        $orders = [];
        foreach ([1, 2] as $_) {
            $order = $service->create($finance, $this->purchaseOrderAttributes($supplier, $fixture, '5.000'));
            $order = $service->submit($finance, $order, $this->transition($order));
            $this->selectBranch($director);
            $orders[] = $service->approve($director, $order, $this->transition($order));
            $this->selectBranch($finance);
        }
        $this->selectBranch($receiver);
        $key = (string) Str::uuid();
        $firstLine = $orders[0]->lines()->sole();
        $secondLine = $orders[1]->lines()->sole();
        $service->receive($receiver, $orders[0], $this->receiptAttributes($orders[0], $firstLine->public_id, '2.000', $key));
        $movementCount = StockMovement::query()->count();
        $auditCount = DB::table('audit_logs')->where('event', 'inventory.goods_receipt.posted')->count();

        $this->expectValidation(fn () => $service->receive($receiver, $orders[1], $this->receiptAttributes($orders[1], $secondLine->public_id, '2.000', $key)), 'idempotency_key');

        $this->assertSame('0.000', $secondLine->refresh()->received_quantity);
        $this->assertSame(PurchaseOrder::STATUS_APPROVED, $orders[1]->refresh()->status);
        $this->assertSame(1, GoodsReceipt::query()->count());
        $this->assertSame($movementCount, StockMovement::query()->count());
        $this->assertSame($auditCount, DB::table('audit_logs')->where('event', 'inventory.goods_receipt.posted')->count());
    }

    public function test_receiving_rejects_overage_invalid_status_and_self_approval_without_mutation(): void
    {
        $finance = $this->actor('finance_officer');
        $director = $this->actor('director');
        $receiver = $this->actor('ca_supervisor');
        $this->selectBranch($finance);
        $supplier = app(SupplierAdministrationService::class)->create($finance, $this->supplierAttributes('P0-REJECT'));
        $fixture = $this->inventoryFixture($finance, 'P0-REJECT');
        $service = app(ProcurementService::class);
        $order = $service->create($finance, $this->purchaseOrderAttributes($supplier, $fixture, '5.000'));
        $this->selectBranch($receiver);
        $this->expectValidation(fn () => $service->receive($receiver, $order, $this->receiptAttributes($order, $order->lines()->sole()->public_id, '1.000', (string) Str::uuid())), 'purchase_order');
        $this->assertSame(0, DB::table('inventory_goods_receipts')->count());
        $this->assertSame(0, DB::table('stock_movements')->count());

        $this->selectBranch($finance);
        $order = $service->submit($finance, $order, $this->transition($order));
        $this->selectBranch($director);
        $order = $service->approve($director, $order, $this->transition($order));
        $this->selectBranch($receiver);
        $this->expectValidation(fn () => $service->receive($receiver, $order, $this->receiptAttributes($order, $order->lines()->sole()->public_id, '6.000', (string) Str::uuid())), 'lines');
        $this->assertSame(0, DB::table('inventory_goods_receipts')->count());
        $this->assertSame(0, DB::table('inventory_batches')->where('batch_number', 'SYN-RECEIPT-BATCH')->count());
        $this->assertSame(0, DB::table('stock_movements')->count());

        $creatorApprover = $this->actor('director');
        $creatorApprover->givePermissionTo(ProcurementService::CREATE_PERMISSION);
        $this->selectBranch($creatorApprover);
        $ownSupplier = app(SupplierAdministrationService::class)->create($creatorApprover, $this->supplierAttributes('OWN'));
        $ownFixture = $this->inventoryFixture($creatorApprover, 'OWN');
        $own = $service->create($creatorApprover, $this->purchaseOrderAttributes($ownSupplier, $ownFixture));
        $own = $service->submit($creatorApprover, $own, $this->transition($own));
        $this->expectException(AuthorizationException::class);
        $service->approve($creatorApprover, $own, $this->transition($own));
    }

    public function test_approved_purchase_order_cancellation_revalidates_approval_authority_after_locking(): void
    {
        $finance = $this->actor('finance_officer');
        $director = $this->actor('director');
        $this->selectBranch($finance);
        $supplier = app(SupplierAdministrationService::class)->create($finance, $this->supplierAttributes('CANCEL'));
        $fixture = $this->inventoryFixture($finance, 'CANCEL');
        $service = app(ProcurementService::class);
        $order = $service->create($finance, $this->purchaseOrderAttributes($supplier, $fixture));
        $staleCreatorView = PurchaseOrder::query()->findOrFail($order->id);
        $order = $service->submit($finance, $order, $this->transition($order));
        $this->selectBranch($director);
        $order = $service->approve($director, $order, $this->transition($order));

        $this->selectBranch($finance);
        $this->expectException(AuthorizationException::class);
        $service->cancel($finance, $staleCreatorView, [...$this->transition($order), 'reason' => 'Synthetic stale cancellation attempt.']);
    }

    public function test_stock_request_dispatch_and_receipt_are_two_stage_exactly_once_movements(): void
    {
        $requester = $this->actor('ca');
        $supervisor = $this->actor('ca_supervisor');
        $this->selectBranch($supervisor);
        $fixture = $this->inventoryFixture($supervisor, 'P1');
        $destination = $this->location($supervisor, 'P1-DEST', InventoryLocation::TYPE_DISPENSARY);
        app(InventoryMovementService::class)->openingBalance($supervisor, $this->opening($fixture, '12.000'));
        $service = app(StockRequestService::class);
        $this->selectBranch($requester);
        $request = $service->create($requester, ['expected_branch_id' => $this->branch->id, 'source_location_public_id' => $fixture['location']->public_id, 'destination_location_public_id' => $destination->public_id, 'lines' => [['sku_public_id' => $fixture['sku']->public_id, 'quantity' => '7.000']]]);
        $this->assertSame(0, DB::table('stock_movements')->whereIn('movement_type', [StockMovement::TYPE_TRANSFER_DISPATCH, StockMovement::TYPE_TRANSFER_RECEIPT])->count());
        $this->selectBranch($supervisor);
        $request = $service->approve($supervisor, $request, $this->stockRequestTransition($request));
        $line = $request->lines()->sole();
        $dispatchKey = (string) Str::uuid();
        $dispatch = ['expected_branch_id' => $this->branch->id, 'lock_version' => $request->lock_version, 'dispatch_idempotency_key' => $dispatchKey, 'lines' => [['line_public_id' => $line->public_id, 'batch_public_id' => $fixture['batch']->public_id, 'quantity' => '7.000']]];
        $this->expectValidation(fn () => $service->dispatch($supervisor, $request, [...$dispatch, 'lock_version' => $request->lock_version - 1]), 'lock_version');
        $this->assertDatabaseHas('inventory_stock_balances', ['inventory_location_id' => $fixture['location']->id, 'quantity' => 12]);
        $request = $service->dispatch($supervisor, $request, $dispatch);
        $this->assertSame(StockRequest::STATUS_DISPATCHED, $request->status);
        $this->assertDatabaseHas('inventory_stock_balances', ['inventory_location_id' => $fixture['location']->id, 'quantity' => 5]);
        $this->assertDatabaseMissing('inventory_stock_balances', ['inventory_location_id' => $destination->id, 'quantity' => 7]);
        $again = $service->dispatch($supervisor, $request, $dispatch);
        $this->assertSame($request->id, $again->id);
        $this->assertSame(1, DB::table('stock_movements')->where('movement_type', StockMovement::TYPE_TRANSFER_DISPATCH)->count());

        $receiveKey = (string) Str::uuid();
        $this->selectBranch($requester);
        $receive = ['expected_branch_id' => $this->branch->id, 'lock_version' => $request->lock_version, 'receive_idempotency_key' => $receiveKey];
        $request = $service->receive($requester, $request, $receive);
        $this->assertSame(StockRequest::STATUS_RECEIVED, $request->status);
        $service->receive($requester, $request, $receive);
        $this->assertDatabaseHas('inventory_stock_balances', ['inventory_location_id' => $destination->id, 'quantity' => 7]);
        $this->assertSame(1, DB::table('stock_movements')->where('movement_type', StockMovement::TYPE_TRANSFER_RECEIPT)->count());
        $this->assertDatabaseHas('audit_logs', ['event' => 'inventory.stock_request.received', 'subject_id' => $request->id]);
    }

    public function test_insufficient_dispatch_fails_atomically_and_request_can_be_rejected(): void
    {
        $requester = $this->actor('ca');
        $supervisor = $this->actor('ca_supervisor');
        $this->selectBranch($supervisor);
        $fixture = $this->inventoryFixture($supervisor, 'P1-REJECT');
        $destination = $this->location($supervisor, 'P1-REJECT-DEST', InventoryLocation::TYPE_DISPENSARY);
        app(InventoryMovementService::class)->openingBalance($supervisor, $this->opening($fixture, '2.000'));
        $service = app(StockRequestService::class);
        $this->selectBranch($requester);
        $request = $service->create($requester, ['expected_branch_id' => $this->branch->id, 'source_location_public_id' => $fixture['location']->public_id, 'destination_location_public_id' => $destination->public_id, 'lines' => [['sku_public_id' => $fixture['sku']->public_id, 'quantity' => '3.000']]]);
        $this->selectBranch($supervisor);
        $request = $service->approve($supervisor, $request, $this->stockRequestTransition($request));
        $line = $request->lines()->sole();
        $this->expectValidation(fn () => $service->dispatch($supervisor, $request, ['expected_branch_id' => $this->branch->id, 'lock_version' => $request->lock_version, 'dispatch_idempotency_key' => (string) Str::uuid(), 'lines' => [['line_public_id' => $line->public_id, 'batch_public_id' => $fixture['batch']->public_id, 'quantity' => '3.000']]]), 'quantity');
        $this->assertSame(StockRequest::STATUS_APPROVED, $request->refresh()->status);
        $this->assertDatabaseHas('inventory_stock_balances', ['inventory_location_id' => $fixture['location']->id, 'quantity' => 2]);
        $this->assertSame(0, DB::table('stock_movements')->where('movement_type', StockMovement::TYPE_TRANSFER_DISPATCH)->count());

        $this->selectBranch($requester);
        $other = $service->create($requester, ['expected_branch_id' => $this->branch->id, 'source_location_public_id' => $fixture['location']->public_id, 'destination_location_public_id' => $destination->public_id, 'lines' => [['sku_public_id' => $fixture['sku']->public_id, 'quantity' => '1.000']]]);
        $this->selectBranch($supervisor);
        $other = $service->reject($supervisor, $other, [...$this->stockRequestTransition($other), 'reason' => 'Synthetic demand no longer applies.']);
        $this->assertSame(StockRequest::STATUS_REJECTED, $other->status);
    }

    public function test_stock_request_branch_source_cannot_be_overridden_by_warehouse_authority(): void
    {
        $supervisor = $this->actor('ca_supervisor');
        $this->selectBranch($supervisor);
        $fixture = $this->inventoryFixture($supervisor, 'SOURCE-A');
        $destination = $this->location($supervisor, 'SOURCE-A-DEST', InventoryLocation::TYPE_DISPENSARY);
        $warehouse = app(InventoryReferenceAdministrationService::class)->createLocation($supervisor, null, null, ['code' => 'SYN-ORG-WH', 'name' => 'Synthetic Organisation Warehouse', 'type' => InventoryLocation::TYPE_MEDICAL_STOCK]);
        $warehouseRequest = app(StockRequestService::class)->create($supervisor, ['expected_branch_id' => $this->branch->id, 'source_location_public_id' => $warehouse->public_id, 'destination_location_public_id' => $destination->public_id, 'lines' => [['sku_public_id' => $fixture['sku']->public_id, 'quantity' => '1.000']]]);
        $this->assertSame($warehouse->id, $warehouseRequest->source_location_id);
        $warehouseDestinationRequest = app(StockRequestService::class)->create($supervisor, ['expected_branch_id' => $this->branch->id, 'source_location_public_id' => $fixture['location']->public_id, 'destination_location_public_id' => $warehouse->public_id, 'lines' => [['sku_public_id' => $fixture['sku']->public_id, 'quantity' => '1.000']]]);
        $this->assertSame($warehouse->id, $warehouseDestinationRequest->destination_location_id);

        $otherBranch = new Branch;
        $otherBranch->forceFill(['organisation_id' => $supervisor->organisation_id, 'code' => 'SYN-SOURCE-B', 'name' => 'Synthetic Source Branch B', 'timezone' => 'Asia/Kuala_Lumpur', 'is_active' => true])->save();
        $otherSupervisor = $this->actor('ca_supervisor', $otherBranch);
        $this->selectBranch($otherSupervisor, $otherBranch);
        $otherSource = app(InventoryReferenceAdministrationService::class)->createLocation($otherSupervisor, $otherBranch, null, ['code' => 'SYN-SOURCE-B-STORE', 'name' => 'Synthetic Branch B Store', 'type' => InventoryLocation::TYPE_BRANCH_STORE]);

        $this->selectBranch($supervisor);
        $requestCount = StockRequest::query()->count();
        $movementCount = StockMovement::query()->count();
        $auditCount = DB::table('audit_logs')->count();
        try {
            app(StockRequestService::class)->create($supervisor, ['expected_branch_id' => $this->branch->id, 'source_location_public_id' => $otherSource->public_id, 'destination_location_public_id' => $destination->public_id, 'lines' => [['sku_public_id' => $fixture['sku']->public_id, 'quantity' => '1.000']]]);
            $this->fail('Warehouse authority allowed a foreign branch-owned source.');
        } catch (NotFoundHttpException) {
            $this->addToAssertionCount(1);
        }
        $this->post(route('inventory.stock-requests.store'), ['expected_branch_id' => $this->branch->id, 'source_location_public_id' => $otherSource->public_id, 'destination_location_public_id' => $destination->public_id, 'lines' => [['sku_public_id' => $fixture['sku']->public_id, 'quantity' => '1.000']]])->assertNotFound();
        $this->assertSame($requestCount, StockRequest::query()->count());
        $this->assertSame($movementCount, StockMovement::query()->count());
        $this->assertSame($auditCount, DB::table('audit_logs')->count());

        $ca = $this->actor('ca');
        $this->selectBranch($ca);
        $this->expectAuthorization(fn () => app(StockRequestService::class)->create($ca, ['expected_branch_id' => $this->branch->id, 'source_location_public_id' => $warehouse->public_id, 'destination_location_public_id' => $destination->public_id, 'lines' => [['sku_public_id' => $fixture['sku']->public_id, 'quantity' => '1.000']]]), 'organisation warehouse source');

        $valid = app(StockRequestService::class)->create($ca, ['expected_branch_id' => $this->branch->id, 'source_location_public_id' => $fixture['location']->public_id, 'destination_location_public_id' => $destination->public_id, 'lines' => [['sku_public_id' => $fixture['sku']->public_id, 'quantity' => '1.000']]]);
        DB::table('inventory_stock_requests')->where('id', $valid->id)->update(['source_location_id' => $otherSource->id]);
        $this->selectBranch($supervisor);
        try {
            app(StockRequestService::class)->approve($supervisor, $valid, $this->stockRequestTransition($valid));
            $this->fail('Approval retained authority over a branch-owned source that is no longer authorized.');
        } catch (NotFoundHttpException) {
            $this->addToAssertionCount(1);
        }
        $this->assertSame(StockRequest::STATUS_REQUESTED, $valid->refresh()->status);

        $dispatchGuard = app(StockRequestService::class)->create($supervisor, ['expected_branch_id' => $this->branch->id, 'source_location_public_id' => $fixture['location']->public_id, 'destination_location_public_id' => $destination->public_id, 'lines' => [['sku_public_id' => $fixture['sku']->public_id, 'quantity' => '1.000']]]);
        $dispatchGuard = app(StockRequestService::class)->approve($supervisor, $dispatchGuard, $this->stockRequestTransition($dispatchGuard));
        $dispatchLine = $dispatchGuard->lines()->sole();
        DB::table('inventory_stock_requests')->where('id', $dispatchGuard->id)->update(['source_location_id' => $otherSource->id]);
        $movementCount = StockMovement::query()->count();
        $auditCount = DB::table('audit_logs')->count();
        try {
            app(StockRequestService::class)->dispatch($supervisor, $dispatchGuard, ['expected_branch_id' => $this->branch->id, 'lock_version' => $dispatchGuard->lock_version, 'dispatch_idempotency_key' => (string) Str::uuid(), 'lines' => [['line_public_id' => $dispatchLine->public_id, 'batch_public_id' => $fixture['batch']->public_id, 'quantity' => '1.000']]]);
            $this->fail('A previously approved request bypassed current source authority at dispatch.');
        } catch (NotFoundHttpException) {
            $this->addToAssertionCount(1);
        }
        $this->assertSame(StockRequest::STATUS_APPROVED, $dispatchGuard->refresh()->status);
        $this->assertSame($movementCount, StockMovement::query()->count());
        $this->assertSame($auditCount, DB::table('audit_logs')->count());

        $foreignOrganisation = new Organisation;
        $foreignOrganisation->forceFill(['code' => 'SYN-SOURCE-FOREIGN', 'name' => 'Synthetic Source Foreign Organisation', 'is_active' => true])->save();
        $foreignLocation = new InventoryLocation;
        $foreignLocation->forceFill(['public_id' => (string) Str::uuid(), 'organisation_id' => $foreignOrganisation->id, 'branch_id' => null, 'parent_id' => null, 'code' => 'SYN-FOREIGN-WH', 'name' => 'Synthetic Foreign Warehouse', 'type' => InventoryLocation::TYPE_MEDICAL_STOCK, 'is_active' => true])->save();
        foreach ([[$foreignLocation->public_id, $destination->public_id], [$fixture['location']->public_id, $foreignLocation->public_id]] as [$sourceId, $destinationId]) {
            try {
                app(StockRequestService::class)->create($supervisor, ['expected_branch_id' => $this->branch->id, 'source_location_public_id' => $sourceId, 'destination_location_public_id' => $destinationId, 'lines' => [['sku_public_id' => $fixture['sku']->public_id, 'quantity' => '1.000']]]);
                $this->fail('A cross-organisation Stock Request location was accepted.');
            } catch (NotFoundHttpException) {
                $this->addToAssertionCount(1);
            }
        }

        $technical = $this->actor('technical_admin');
        $this->selectBranch($technical);
        $this->post(route('inventory.stock-requests.store'), ['expected_branch_id' => $this->branch->id, 'source_location_public_id' => $fixture['location']->public_id, 'destination_location_public_id' => $destination->public_id, 'lines' => [['sku_public_id' => $fixture['sku']->public_id, 'quantity' => '1.000']]])->assertForbidden();
    }

    public function test_stock_request_creation_revalidates_actor_and_current_assignment(): void
    {
        $supervisor = $this->actor('ca_supervisor');
        $this->selectBranch($supervisor);
        $fixture = $this->inventoryFixture($supervisor, 'SOURCE-AUTH');
        $destination = $this->location($supervisor, 'SOURCE-AUTH-DEST', InventoryLocation::TYPE_DISPENSARY);

        foreach (['inactive', 'ended', 'future'] as $state) {
            $actor = $this->actor('ca');
            $this->selectBranch($actor);
            if ($state === 'inactive') {
                $actor->forceFill(['is_active' => false])->save();
            } elseif ($state === 'ended') {
                StaffBranchAssignment::query()->where('staff_profile_id', $actor->staffProfile->id)->update(['valid_until' => now()->setTimezone($this->branch->timezone)->subDay()->toDateString()]);
            } else {
                StaffBranchAssignment::query()->where('staff_profile_id', $actor->staffProfile->id)->update(['valid_from' => now()->setTimezone($this->branch->timezone)->addDay()->toDateString()]);
            }
            $requestCount = StockRequest::query()->count();
            $movementCount = StockMovement::query()->count();
            $auditCount = DB::table('audit_logs')->count();
            try {
                app(StockRequestService::class)->create($actor, ['expected_branch_id' => $this->branch->id, 'source_location_public_id' => $fixture['location']->public_id, 'destination_location_public_id' => $destination->public_id, 'lines' => [['sku_public_id' => $fixture['sku']->public_id, 'quantity' => '1.000']]]);
                $this->fail('Stock request '.$state.' actor was accepted.');
            } catch (AuthorizationException|NotFoundHttpException) {
                $this->addToAssertionCount(1);
            }
            $this->assertSame($requestCount, StockRequest::query()->count());
            $this->assertSame($movementCount, StockMovement::query()->count());
            $this->assertSame($auditCount, DB::table('audit_logs')->count());
        }
    }

    public function test_stocktake_posts_gain_and_loss_once_and_rejects_stale_snapshot(): void
    {
        $supervisor = $this->actor('ca_supervisor');
        $this->selectBranch($supervisor);
        $fixture = $this->inventoryFixture($supervisor, 'P2-ST');
        app(InventoryMovementService::class)->openingBalance($supervisor, $this->opening($fixture, '10.000'));
        $service = app(InventoryControlService::class);
        $stocktake = $service->createStocktake($supervisor, ['expected_branch_id' => $this->branch->id, 'location_public_id' => $fixture['location']->public_id]);
        $this->assertSame('10.000', $stocktake->lines()->sole()->expected_quantity);
        $stocktake = $service->startCounting($supervisor, $stocktake, $this->stocktakeTransition($stocktake));
        $line = $stocktake->lines()->sole();
        $stocktake = $service->recordCounts($supervisor, $stocktake, [...$this->stocktakeTransition($stocktake), 'lines' => [['line_public_id' => $line->public_id, 'physical_quantity' => '12.000']]]);
        $stocktake = $service->postStocktake($supervisor, $stocktake, $this->stocktakeTransition($stocktake));
        $this->assertSame(InventoryStocktake::STATUS_POSTED, $stocktake->status);
        $this->assertDatabaseHas('inventory_stock_balances', ['inventory_location_id' => $fixture['location']->id, 'quantity' => 12]);
        $this->assertSame(1, DB::table('stock_movements')->where('movement_type', StockMovement::TYPE_STOCKTAKE_GAIN)->count());
        $this->expectValidation(fn () => $service->postStocktake($supervisor, $stocktake, $this->stocktakeTransition($stocktake)), 'stocktake');

        $loss = $service->createStocktake($supervisor, ['expected_branch_id' => $this->branch->id, 'location_public_id' => $fixture['location']->public_id]);
        $loss = $service->startCounting($supervisor, $loss, $this->stocktakeTransition($loss));
        $lossLine = $loss->lines()->sole();
        $loss = $service->recordCounts($supervisor, $loss, [...$this->stocktakeTransition($loss), 'lines' => [['line_public_id' => $lossLine->public_id, 'physical_quantity' => '11.000']]]);
        $service->postStocktake($supervisor, $loss, $this->stocktakeTransition($loss));
        $this->assertDatabaseHas('inventory_stock_balances', ['inventory_location_id' => $fixture['location']->id, 'quantity' => 11]);
        $this->assertSame(1, DB::table('stock_movements')->where('movement_type', StockMovement::TYPE_STOCKTAKE_LOSS)->count());

        $stale = $service->createStocktake($supervisor, ['expected_branch_id' => $this->branch->id, 'location_public_id' => $fixture['location']->public_id]);
        $stale = $service->startCounting($supervisor, $stale, $this->stocktakeTransition($stale));
        $staleLine = $stale->lines()->sole();
        $stale = $service->recordCounts($supervisor, $stale, [...$this->stocktakeTransition($stale), 'lines' => [['line_public_id' => $staleLine->public_id, 'physical_quantity' => '11.000']]]);
        $service->adjust($supervisor, [...$this->adjustment($fixture, 'in', '1.000'), 'reason_code' => 'found_stock']);
        $this->expectValidation(fn () => $service->postStocktake($supervisor, $stale, $this->stocktakeTransition($stale)), 'stocktake');
        $this->assertSame(InventoryStocktake::STATUS_REVIEW, $stale->refresh()->status);
        $this->assertDatabaseHas('inventory_stock_balances', ['inventory_location_id' => $fixture['location']->id, 'quantity' => 12]);

        $newKey = $service->createStocktake($supervisor, ['expected_branch_id' => $this->branch->id, 'location_public_id' => $fixture['location']->public_id]);
        $newKey = $service->startCounting($supervisor, $newKey, $this->stocktakeTransition($newKey));
        $newKeyLine = $newKey->lines()->sole();
        $newKey = $service->recordCounts($supervisor, $newKey, [...$this->stocktakeTransition($newKey), 'lines' => [['line_public_id' => $newKeyLine->public_id, 'physical_quantity' => '12.000']]]);
        $additional = $this->inventoryFixture($supervisor, 'P2-ST-NEW');
        $additional['location'] = $fixture['location'];
        $service->adjust($supervisor, [...$this->adjustment($additional, 'in', '2.000'), 'reason_code' => 'found_stock']);
        $stocktakeMovementCount = DB::table('stock_movements')->whereIn('movement_type', [StockMovement::TYPE_STOCKTAKE_GAIN, StockMovement::TYPE_STOCKTAKE_LOSS])->count();

        $this->expectValidation(fn () => $service->postStocktake($supervisor, $newKey, $this->stocktakeTransition($newKey)), 'stocktake');

        $this->assertSame(InventoryStocktake::STATUS_REVIEW, $newKey->refresh()->status);
        $this->assertSame($stocktakeMovementCount, DB::table('stock_movements')->whereIn('movement_type', [StockMovement::TYPE_STOCKTAKE_GAIN, StockMovement::TYPE_STOCKTAKE_LOSS])->count());
    }

    public function test_movement_projection_labels_signs_directions_and_filters_non_dispensary_types(): void
    {
        $supervisor = $this->actor('ca_supervisor');
        $this->selectBranch($supervisor);
        $fixture = $this->inventoryFixture($supervisor, 'MOVEMENT');
        $destination = $this->location($supervisor, 'MOVEMENT-DEST', InventoryLocation::TYPE_DISPENSARY);
        $expected = [
            StockMovement::TYPE_OPENING => ['Opening Balance', '+2.5', 'To '.$destination->name, null, $destination->id],
            StockMovement::TYPE_TRANSFER => ['Inventory Transfer', '-2.5 / +2.5', $fixture['location']->name.' → '.$destination->name, $fixture['location']->id, $destination->id],
            StockMovement::TYPE_PURCHASE_RECEIPT => ['Purchase Receipt', '+2.5', 'To '.$destination->name, null, $destination->id],
            StockMovement::TYPE_TRANSFER_DISPATCH => ['Transfer Dispatch', '-2.5', 'From '.$fixture['location']->name, $fixture['location']->id, null],
            StockMovement::TYPE_TRANSFER_RECEIPT => ['Transfer Receipt', '+2.5', 'To '.$destination->name, null, $destination->id],
            StockMovement::TYPE_STOCKTAKE_GAIN => ['Stocktake Gain', '+2.5', 'To '.$destination->name, null, $destination->id],
            StockMovement::TYPE_STOCKTAKE_LOSS => ['Stocktake Loss', '-2.5', 'From '.$fixture['location']->name, $fixture['location']->id, null],
            StockMovement::TYPE_ADJUSTMENT_IN => ['Adjustment In', '+2.5', 'To '.$destination->name, null, $destination->id],
            StockMovement::TYPE_ADJUSTMENT_OUT => ['Adjustment Out', '-2.5', 'From '.$fixture['location']->name, $fixture['location']->id, null],
        ];
        foreach ($expected as $type => [, , , $sourceId, $destinationId]) {
            $movement = new StockMovement;
            $movement->forceFill([
                'public_id' => (string) Str::uuid(), 'organisation_id' => $supervisor->organisation_id,
                'inventory_sku_id' => $fixture['sku']->id, 'inventory_batch_id' => $fixture['batch']->id,
                'source_location_id' => $sourceId, 'destination_location_id' => $destinationId,
                'quantity' => '2.500', 'movement_type' => $type, 'reference_type' => 'inventory_adjustment',
                'reference_public_id' => (string) Str::uuid(), 'actor_user_id' => $supervisor->id, 'occurred_at' => now()->utc(),
            ])->save();
        }

        foreach ($expected as $type => [$label, $quantity, $direction]) {
            $this->get(route('inventory.index', ['tab' => 'movements', 'movement_type' => $type]))
                ->assertOk()
                ->assertInertia(fn ($page) => $page
                    ->has('inventory.data', 1)
                    ->where('inventory.data.0.type', $type)
                    ->where('inventory.data.0.typeLabel', $label)
                    ->where('inventory.data.0.quantityDisplay', $quantity)
                    ->where('inventory.data.0.direction', $direction));
        }
    }

    public function test_stock_availability_and_filter_include_location_batch_and_expiry_eligibility(): void
    {
        $supervisor = $this->actor('ca_supervisor');
        $this->selectBranch($supervisor);
        $fixture = $this->inventoryFixture($supervisor, 'AVAILABILITY');
        app(InventoryMovementService::class)->openingBalance($supervisor, $this->opening($fixture, '10.000'));

        $this->get(route('inventory.index', ['status' => 'active']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('inventory.data', 1)
                ->where('inventory.data.0.available', true)
                ->where('inventory.data.0.availabilityStatus', 'Available'));

        $assertUnavailable = function (string $context): void {
            $this->get(route('inventory.index'))->assertOk()->assertInertia(fn ($page) => $page
                ->has('inventory.data', 1)->where('inventory.data.0.available', function (bool $available) use ($context): bool {
                    $this->assertFalse($available, $context.' should be unavailable.');

                    return true;
                })->where('inventory.data.0.availabilityStatus', 'Unavailable'));
            $this->get(route('inventory.index', ['status' => 'active']))->assertOk()->assertInertia(fn ($page) => $page->has('inventory.data', 0));
            $this->get(route('inventory.index', ['status' => 'inactive']))->assertOk()->assertInertia(fn ($page) => $page->has('inventory.data', 1));
        };

        DB::table('inventory_stock_balances')->where('inventory_location_id', $fixture['location']->id)->update(['quantity' => '0.000']);
        $assertUnavailable('zero quantity');
        DB::table('inventory_stock_balances')->where('inventory_location_id', $fixture['location']->id)->update(['quantity' => '10.000']);
        $fixture['location']->forceFill(['is_active' => false])->save();
        $assertUnavailable('inactive location');
        $fixture['location']->forceFill(['is_active' => true])->save();
        DB::table('inventory_items')->where('id', $fixture['sku']->inventory_item_id)->update(['is_active' => false]);
        $assertUnavailable('inactive item');
        DB::table('inventory_items')->where('id', $fixture['sku']->inventory_item_id)->update(['is_active' => true]);
        $fixture['sku']->forceFill(['is_active' => false])->save();
        $assertUnavailable('inactive SKU');
        $fixture['sku']->forceFill(['is_active' => true])->save();
        $fixture['batch']->forceFill(['status' => InventoryBatch::STATUS_QUARANTINED])->save();
        $assertUnavailable('inactive batch');
        $fixture['batch']->forceFill(['status' => InventoryBatch::STATUS_AVAILABLE, 'expiry_date' => now()->setTimezone($this->branch->timezone)->toDateString()])->save();
        $assertUnavailable('expired batch');
        $fixture['batch']->forceFill(['expiry_date' => now()->setTimezone($this->branch->timezone)->addDay()->toDateString()])->save();
        $this->get(route('inventory.index', ['status' => 'active']))->assertOk()->assertInertia(fn ($page) => $page
            ->has('inventory.data', 1)->where('inventory.data.0.available', true)->where('inventory.data.0.availabilityStatus', 'Available'));
    }

    public function test_operations_directory_batches_document_line_queries(): void
    {
        $finance = $this->actor('finance_officer');
        $this->selectBranch($finance);
        $supplier = app(SupplierAdministrationService::class)->create($finance, $this->supplierAttributes('DIRECTORY'));
        $fixture = $this->inventoryFixture($finance, 'DIRECTORY');
        foreach (['5.000', '6.000'] as $quantity) {
            app(ProcurementService::class)->create($finance, $this->purchaseOrderAttributes($supplier, $fixture, $quantity));
        }

        $supervisor = $this->actor('ca_supervisor');
        $this->selectBranch($supervisor);
        $destination = $this->location($supervisor, 'DIRECTORY-DEST', InventoryLocation::TYPE_DISPENSARY);
        $requester = $this->actor('ca');
        $this->selectBranch($requester);
        foreach (['2.000', '3.000'] as $quantity) {
            app(StockRequestService::class)->create($requester, ['expected_branch_id' => $this->branch->id, 'source_location_public_id' => $fixture['location']->public_id, 'destination_location_public_id' => $destination->public_id, 'lines' => [['sku_public_id' => $fixture['sku']->public_id, 'quantity' => $quantity]]]);
        }

        $this->selectBranch($supervisor);
        app(InventoryMovementService::class)->openingBalance($supervisor, $this->opening($fixture, '10.000'));
        foreach ([1, 2] as $_) {
            app(InventoryControlService::class)->createStocktake($supervisor, ['expected_branch_id' => $this->branch->id, 'location_public_id' => $fixture['location']->public_id]);
        }

        $lineQueries = ['inventory_purchase_order_lines' => 0, 'inventory_stock_request_lines' => 0, 'inventory_stocktake_lines' => 0];
        DB::listen(function (QueryExecuted $query) use (&$lineQueries): void {
            foreach (array_keys($lineQueries) as $table) {
                if (str_contains($query->sql, $table)) {
                    $lineQueries[$table]++;
                }
            }
        });

        $this->get(route('inventory.index'))->assertOk()->assertInertia(fn ($page) => $page
            ->has('operations.purchaseOrders', 2)
            ->has('operations.stockRequests', 2)
            ->has('operations.stocktakes', 2));
        $this->assertSame(['inventory_purchase_order_lines' => 1, 'inventory_stock_request_lines' => 1, 'inventory_stocktake_lines' => 1], $lineQueries);
    }

    public function test_adjustment_and_reorder_visibility_preserve_nonnegative_and_expiry_rules(): void
    {
        $supervisor = $this->actor('ca_supervisor');
        $this->selectBranch($supervisor);
        $fixture = $this->inventoryFixture($supervisor, 'P2-ADJ');
        $service = app(InventoryControlService::class);
        $service->adjust($supervisor, $this->adjustment($fixture, 'in', '5.000'));
        $this->expectValidation(fn () => $service->adjust($supervisor, [...$this->adjustment($fixture, 'in', '1.000'), 'reason_code' => '']), 'reason_code');
        $this->expectValidation(fn () => $service->adjust($supervisor, $this->adjustment($fixture, 'out', '6.000')), 'quantity');
        $this->assertSame(1, DB::table('inventory_adjustments')->count());
        $this->assertDatabaseHas('inventory_stock_balances', ['inventory_location_id' => $fixture['location']->id, 'quantity' => 5]);
        $service->setReorderLevel($supervisor, ['expected_branch_id' => $this->branch->id, 'location_public_id' => $fixture['location']->public_id, 'sku_public_id' => $fixture['sku']->public_id, 'reorder_level' => '5.000']);
        $this->get(route('inventory.index'))->assertOk()->assertInertia(fn ($page) => $page->has('operations.lowStock', 1)->where('operations.lowStock.0.sku', 'P2-ADJ-SKU'));

        DB::table('inventory_stock_balances')->where('inventory_location_id', $fixture['location']->id)->update(['quantity' => '0.000']);
        $fixture['batch']->forceFill(['expiry_date' => now()->setTimezone($this->branch->timezone)->addDays(30)->toDateString()])->save();
        $this->get(route('inventory.index'))->assertOk()->assertInertia(fn ($page) => $page
            ->has('operations.lowStock', 1)
            ->has('operations.expiringBatches', 0));

        DB::table('inventory_stock_balances')->where('inventory_location_id', $fixture['location']->id)->update(['quantity' => '1.000']);
        $otherLocation = $this->location($supervisor, 'P2-ADJ-OTHER', InventoryLocation::TYPE_BRANCH_STORE);
        app(InventoryMovementService::class)->openingBalance($supervisor, [
            'expected_branch_id' => $this->branch->id,
            'location_public_id' => $otherLocation->public_id,
            'sku_public_id' => $fixture['sku']->public_id,
            'batch_public_id' => $fixture['batch']->public_id,
            'quantity' => '1.000',
        ]);
        $expiring = $this->get(route('inventory.index'))->assertOk()->inertiaProps('operations.expiringBatches');
        $this->assertCount(2, $expiring);
        $this->assertEqualsCanonicalizing(
            [$fixture['location']->name, $otherLocation->name],
            array_column($expiring, 'location'),
        );
        $this->assertSame([$fixture['sku']->sku_code], array_values(array_unique(array_column($expiring, 'sku'))));
        $this->assertSame([$fixture['batch']->batch_number], array_values(array_unique(array_column($expiring, 'batch'))));
        DB::table('inventory_items')->where('id', $fixture['sku']->inventory_item_id)->update(['is_active' => false]);
        $this->get(route('inventory.index'))->assertOk()->assertInertia(fn ($page) => $page
            ->has('operations.batches', 0)
            ->has('operations.lowStock', 0)
            ->has('operations.expiringBatches', 0));
    }

    public function test_adjustment_idempotency_replays_once_and_rejects_changed_intent(): void
    {
        $supervisor = $this->actor('ca_supervisor');
        $this->selectBranch($supervisor);
        $fixture = $this->inventoryFixture($supervisor, 'ADJ-IDEMPOTENT');
        $service = app(InventoryControlService::class);
        $key = (string) Str::uuid();
        $input = [...$this->adjustment($fixture, 'in', '5.000'), 'idempotency_key' => $key];

        $first = $service->adjust($supervisor, $input);
        $again = $service->adjust($supervisor, $input);
        $this->assertSame($first->id, $again->id);
        $this->assertSame(1, DB::table('inventory_adjustments')->count());
        $this->assertSame(1, DB::table('stock_movements')->where('movement_type', StockMovement::TYPE_ADJUSTMENT_IN)->count());
        $this->assertSame(1, DB::table('audit_logs')->where('event', 'inventory.adjustment.posted')->count());
        $this->assertDatabaseHas('inventory_stock_balances', ['inventory_location_id' => $fixture['location']->id, 'quantity' => 5]);

        $this->expectValidation(fn () => $service->adjust($supervisor, [...$input, 'quantity' => '6.000']), 'idempotency_key');
        $this->assertSame(1, DB::table('inventory_adjustments')->count());
        $this->assertSame(1, DB::table('stock_movements')->where('movement_type', StockMovement::TYPE_ADJUSTMENT_IN)->count());
        $this->assertDatabaseHas('inventory_stock_balances', ['inventory_location_id' => $fixture['location']->id, 'quantity' => 5]);

        $this->post(route('inventory.adjustments.store'), [...$this->adjustment($fixture, 'in', '1.000'), 'idempotency_key' => $key])
            ->assertSessionHasErrors('idempotency_key');
    }

    public function test_inventory_operations_permissions_are_explicit_and_technical_admin_has_none(): void
    {
        $roles = PermissionCatalogue::roles();
        $this->assertContains(SupplierAdministrationService::PERMISSION, $roles['finance_officer']);
        $this->assertContains(ProcurementService::APPROVE_PERMISSION, $roles['director']);
        $this->assertNotContains(ProcurementService::CREATE_PERMISSION, $roles['director']);
        $this->assertContains(StockRequestService::CREATE_PERMISSION, $roles['ca']);
        $this->assertContains(InventoryControlService::ADJUST_PERMISSION, $roles['ca_supervisor']);
        foreach ([SupplierAdministrationService::PERMISSION, ProcurementService::CREATE_PERMISSION, ProcurementService::APPROVE_PERMISSION, StockRequestService::CREATE_PERMISSION, InventoryControlService::ADJUST_PERMISSION] as $permission) {
            $this->assertNotContains($permission, $roles['technical_admin']);
        }
    }

    public function test_critical_operations_reject_inactive_staff_and_ended_branch_assignments(): void
    {
        $supervisor = $this->actor('ca_supervisor');
        $this->selectBranch($supervisor);
        $fixture = $this->inventoryFixture($supervisor, 'AUTHORITY');
        $service = app(InventoryControlService::class);
        $supervisor->forceFill(['is_active' => false])->save();
        try {
            $service->adjust($supervisor, $this->adjustment($fixture, 'in', '1.000'));
            $this->fail('Inactive staff retained Inventory adjustment authority.');
        } catch (AuthorizationException) {
            $this->assertSame(0, DB::table('inventory_adjustments')->count());
        }

        $supervisor->forceFill(['is_active' => true])->save();
        StaffBranchAssignment::query()->where('staff_profile_id', $supervisor->staffProfile->id)->update(['valid_until' => now()->subDay()->toDateString()]);
        try {
            $service->adjust($supervisor, $this->adjustment($fixture, 'in', '1.000'));
            $this->fail('Staff with an ended branch assignment retained Inventory authority.');
        } catch (AuthorizationException) {
            $this->assertSame(0, DB::table('inventory_adjustments')->count());
        }
    }

    public function test_foreign_supplier_references_and_technical_admin_routes_fail_closed(): void
    {
        $finance = $this->actor('finance_officer');
        $this->selectBranch($finance);
        $fixture = $this->inventoryFixture($finance, 'FOREIGN');
        $foreignOrganisation = new Organisation;
        $foreignOrganisation->forceFill(['code' => 'SYN-I2-FOREIGN', 'name' => 'Synthetic I2 Foreign Organisation', 'is_active' => true])->save();
        $foreignSupplier = new InventorySupplier;
        $foreignSupplier->forceFill(['public_id' => (string) Str::uuid(), 'organisation_id' => $foreignOrganisation->id, 'code' => 'FOREIGN', 'name' => 'Synthetic Foreign Supplier', 'is_active' => true])->save();

        $this->expectException(ModelNotFoundException::class);
        try {
            app(ProcurementService::class)->create($finance, $this->purchaseOrderAttributes($foreignSupplier, $fixture));
        } finally {
            $this->assertSame(0, PurchaseOrder::query()->count());
        }
    }

    public function test_technical_admin_http_route_is_denied_and_posted_evidence_is_immutable(): void
    {
        $technical = $this->actor('technical_admin');
        $this->selectBranch($technical);
        $this->post(route('inventory.suppliers.store'), $this->supplierAttributes('ROUTE'))->assertForbidden();

        $supervisor = $this->actor('ca_supervisor');
        $this->selectBranch($supervisor);
        $fixture = $this->inventoryFixture($supervisor, 'IMMUTABLE');
        $adjustment = app(InventoryControlService::class)->adjust($supervisor, $this->adjustment($fixture, 'in', '1.000'));
        $this->expectException(LogicException::class);
        $adjustment->delete();
    }

    /** @return array<string, mixed> */
    private function supplierAttributes(string $suffix = 'SUP'): array
    {
        return ['code' => 'SYN-'.$suffix, 'name' => 'Synthetic '.$suffix.' Supplier', 'contact_name' => 'Synthetic Contact', 'business_email' => mb_strtolower($suffix).'@supplier.test', 'business_phone' => '+60 3 5555 0101'];
    }

    /** @return array{sku:mixed,batch:InventoryBatch,location:InventoryLocation} */
    private function inventoryFixture(User $actor, string $prefix): array
    {
        $referenceActor = $actor->can('inventory.references.manage.organisation') ? $actor : $this->actor('ca_supervisor');
        $this->selectBranch($referenceActor);
        $service = app(InventoryReferenceAdministrationService::class);
        $item = $service->createItem($referenceActor, ['code' => $prefix.'-ITEM', 'generic_name' => 'Synthetic '.$prefix.' Item', 'brand_name' => null, 'strength' => null, 'dosage_form' => null, 'route' => null, 'manufacturer' => null, 'mal_number' => null]);
        $sku = $service->createSku($referenceActor, $item, ['sku_code' => $prefix.'-SKU', 'barcode' => null, 'pack_size' => '1', 'purchase_unit' => 'unit', 'stock_unit' => 'unit', 'dispensing_unit' => 'unit', 'unit_conversion' => '1', 'storage_type' => 'ambient', 'cold_chain_required' => false, 'do_not_freeze' => false, 'protect_from_light' => false, 'batch_tracking_required' => true, 'expiry_tracking_required' => true]);
        $location = $this->location($referenceActor, $prefix.'-STORE', InventoryLocation::TYPE_BRANCH_STORE);
        $batch = app(InventoryReferenceAdministrationService::class)->createBatch($referenceActor, $sku, ['batch_number' => $prefix.'-BATCH', 'expiry_date' => '2028-12-31', 'received_at' => '2026-09-11', 'status' => InventoryBatch::STATUS_AVAILABLE]);
        $this->selectBranch($actor);

        return compact('sku', 'batch', 'location');
    }

    private function location(User $actor, string $code, string $type): InventoryLocation
    {
        return app(InventoryReferenceAdministrationService::class)->createLocation($actor, $this->branch, null, ['code' => $code, 'name' => 'Synthetic '.$code, 'type' => $type]);
    }

    /** @param array{sku:mixed,batch:InventoryBatch,location:InventoryLocation} $fixture @return array<string, mixed> */
    private function opening(array $fixture, string $quantity): array
    {
        return ['expected_branch_id' => $this->branch->id, 'location_public_id' => $fixture['location']->public_id, 'sku_public_id' => $fixture['sku']->public_id, 'batch_public_id' => $fixture['batch']->public_id, 'quantity' => $quantity];
    }

    /** @param array{sku:mixed,location:InventoryLocation} $fixture @return array<string, mixed> */
    private function purchaseOrderAttributes(InventorySupplier $supplier, array $fixture, string $quantity = '10.000'): array
    {
        return ['expected_branch_id' => $this->branch->id, 'supplier_public_id' => $supplier->public_id, 'destination_location_public_id' => $fixture['location']->public_id, 'lines' => [['sku_public_id' => $fixture['sku']->public_id, 'quantity' => $quantity]]];
    }

    /** @return array<string, mixed> */
    private function transition(PurchaseOrder $order): array
    {
        return ['expected_branch_id' => $this->branch->id, 'lock_version' => $order->lock_version];
    }

    /** @return array<string, mixed> */
    private function receiptAttributes(PurchaseOrder $order, string $linePublicId, string $quantity, string $key): array
    {
        return [...$this->transition($order), 'idempotency_key' => $key, 'lines' => [['line_public_id' => $linePublicId, 'quantity' => $quantity, 'batch_number' => 'SYN-RECEIPT-BATCH', 'expiry_date' => '2028-12-31']]];
    }

    /** @return array<string, mixed> */
    private function stockRequestTransition(StockRequest $request): array
    {
        return ['expected_branch_id' => $this->branch->id, 'lock_version' => $request->lock_version];
    }

    /** @return array<string, mixed> */
    private function stocktakeTransition(InventoryStocktake $stocktake): array
    {
        return ['expected_branch_id' => $this->branch->id, 'lock_version' => $stocktake->lock_version];
    }

    /** @param array{sku:mixed,batch:InventoryBatch,location:InventoryLocation} $fixture @return array<string, mixed> */
    private function adjustment(array $fixture, string $direction, string $quantity): array
    {
        return ['expected_branch_id' => $this->branch->id, 'location_public_id' => $fixture['location']->public_id, 'sku_public_id' => $fixture['sku']->public_id, 'batch_public_id' => $fixture['batch']->public_id, 'direction' => $direction, 'quantity' => $quantity, 'reason_code' => 'correction', 'reason_note' => 'Synthetic test adjustment.', 'idempotency_key' => (string) Str::uuid()];
    }

    private function expectValidation(callable $callback, string $field): void
    {
        try {
            $callback();
            $this->fail('Expected validation failure for '.$field.'.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($field, $exception->errors());
        }
    }

    private function expectAuthorization(callable $callback, string $context): void
    {
        try {
            $callback();
            $this->fail('Expected Inventory authorization failure for '.$context.'.');
        } catch (AuthorizationException) {
            $this->addToAssertionCount(1);
        }
    }
}
