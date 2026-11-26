<?php

namespace Tests\Feature\Inventory;

use App\Domain\Access\BranchAccessService;
use App\Domain\Organisation\Inventory\Models\PurchaseOrder;
use App\Domain\Organisation\Inventory\Services\InventoryOperationsDirectoryService;
use App\Domain\Organisation\Inventory\Services\ProcurementService;
use App\Domain\Organisation\Inventory\Services\PurchaseOrderPrintService;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Visit\VisitTestCase;
use Tests\Support\VerifiedReceivingFixture;

final class PurchaseOrderDeliveryMetadataTest extends VisitTestCase
{
    private function selectedActor(User $actor): User
    {
        app(BranchAccessService::class)->select($actor, $this->branch);

        return $actor;
    }

    private function input(array $f, array $extra = []): array
    {
        return ['expected_branch_id' => $this->branch->id, 'supplier_public_id' => $f['supplier']->public_id,
            'destination_location_public_id' => $f['location']->public_id,
            'lines' => [['sku_public_id' => $f['sku']->public_id, 'quantity' => '5.000', 'unit_cost_sen' => 125]], ...$extra];
    }

    public function test_nullable_legacy_create_and_omitted_update_preserve_while_explicit_blank_clears(): void
    {
        $f = (new VerifiedReceivingFixture($this->branch))->build();
        $service = app(ProcurementService::class);
        $actor = $this->selectedActor($f['finance']);
        $legacy = $service->create($actor, $this->input($f));
        $this->assertNull($legacy->expected_delivery_date);
        $this->assertNull($legacy->supplier_reference);
        $order = $service->create($actor, $this->input($f, ['expected_delivery_date' => '2024-02-29', 'supplier_reference' => '  SYN-QT-81  ']));
        $this->assertSame('2024-02-29', $order->expected_delivery_date->format('Y-m-d'));
        $this->assertSame('SYN-QT-81', $order->supplier_reference);
        $order = $service->updateDraft($actor, $order, $this->input($f, ['lock_version' => 1]));
        $this->assertSame(2, $order->lock_version);
        $this->assertSame('SYN-QT-81', $order->supplier_reference);
        $this->assertSame('2024-02-29', $order->expected_delivery_date->format('Y-m-d'));
        $order = $service->updateDraft($actor, $order, $this->input($f, ['lock_version' => 2, 'expected_delivery_date' => '', 'supplier_reference' => '  ']));
        $this->assertNull($order->expected_delivery_date);
        $this->assertNull($order->supplier_reference);
        $this->assertSame(3, $order->lock_version);
        $audit = DB::table('audit_logs')->whereIn('event', ['inventory.purchase_order.created', 'inventory.purchase_order.draft_updated'])->pluck('metadata')->implode(' ');
        $this->assertStringNotContainsString('SYN-QT-81', $audit);
        $this->assertStringNotContainsString('2024-02-29', $audit);
        $this->assertStringContainsString('delivery_metadata_fields', $audit);
    }

    public function test_invalid_metadata_refuses_without_creating_or_updating_any_order(): void
    {
        $f = (new VerifiedReceivingFixture($this->branch))->build();
        $service = app(ProcurementService::class);
        $actor = $this->selectedActor($f['finance']);
        $count = PurchaseOrder::count();
        foreach ([['expected_delivery_date' => '0000-01-01'], ['expected_delivery_date' => '2025-02-29'], ['expected_delivery_date' => '29/02/2024'], ['expected_delivery_date' => []], ['supplier_reference' => []], ['supplier_reference' => str_repeat('x', 121)], ['supplier_reference' => "quote\nunsafe"]] as $bad) {
            $this->assertThrows(fn () => $service->create($actor, $this->input($f, $bad)), ValidationException::class);
            $this->assertSame($count, PurchaseOrder::count());
        }
    }

    public function test_version_and_every_non_draft_state_refuse_metadata_changes_without_stock_effects(): void
    {
        $f = (new VerifiedReceivingFixture($this->branch))->build();
        $service = app(ProcurementService::class);
        $actor = $this->selectedActor($f['finance']);
        $movementCount = DB::table('stock_movements')->count();
        foreach (['submitted', 'approved', 'partially_received', 'fully_received', 'closed', 'cancelled'] as $status) {
            $order = $service->create($actor, $this->input($f, ['supplier_reference' => 'ORIGINAL']));
            // Synthetic state fixture only; no new runtime transition. Closed uses the existing complete-close shape.
            if ($status === 'closed') {
                DB::table('inventory_purchase_orders')->where('id', $order->id)->update(['status' => 'fully_received']);
            }
            DB::table('inventory_purchase_orders')->where('id', $order->id)->update(['status' => $status, ...($status === 'closed' ? ['close_kind' => 'complete'] : [])]);
            $this->assertThrows(fn () => $service->updateDraft($actor, $order, $this->input($f, ['lock_version' => 1, 'supplier_reference' => 'CHANGED'])), ValidationException::class);
            $this->assertSame('ORIGINAL', $order->refresh()->supplier_reference);
            $this->assertThrows(fn () => DB::transaction(fn () => DB::table('inventory_purchase_orders')->where('id', $order->id)->update(['supplier_reference' => 'RAW-CHANGE', 'lock_version' => 2])), QueryException::class);
        }
        $draft = $service->create($actor, $this->input($f));
        $this->assertThrows(fn () => $service->updateDraft($actor, $draft, $this->input($f, ['lock_version' => 0, 'supplier_reference' => 'STALE'])), ValidationException::class);
        $this->assertThrows(fn () => DB::transaction(fn () => DB::table('inventory_purchase_orders')->where('id', $draft->id)->update(['supplier_reference' => 'NO-VERSION'])), QueryException::class);
        $this->assertNull($draft->refresh()->supplier_reference);
        $this->assertSame($movementCount, DB::table('stock_movements')->count());
    }

    public function test_approval_and_scoped_print_retain_date_only_metadata_without_new_cost_authority(): void
    {
        $f = (new VerifiedReceivingFixture($this->branch))->build();
        $service = app(ProcurementService::class);
        $receiver = $this->selectedActor($f['receiver']);
        $order = $service->create($receiver, $this->input($f, ['expected_delivery_date' => '2026-12-01', 'supplier_reference' => 'SYN-QT-PRINT']));
        $order = $service->submit($receiver, $order, ['expected_branch_id' => $this->branch->id, 'lock_version' => 1]);
        $this->assertThrows(fn () => $service->updateDraft($receiver, $order, $this->input($f, ['lock_version' => 2, 'supplier_reference' => 'AFTER-SUBMIT'])), ValidationException::class);
        $service->approve($this->selectedActor($f['director']), $order, ['expected_branch_id' => $this->branch->id, 'lock_version' => 2]);
        $print = app(PurchaseOrderPrintService::class)->print($this->selectedActor($receiver), $order->public_id)['order'];
        $this->assertSame('2026-12-01', $print['expectedDeliveryDate']);
        $this->assertSame('SYN-QT-PRINT', $print['supplierReference']);
        $this->assertFalse($print['showPrices']);
        $this->assertArrayNotHasKey('unitCostSen', $print['lines'][0]);
    }

    public function test_exact_migration_up_down_up_preserves_existing_values_and_guards(): void
    {
        $f = (new VerifiedReceivingFixture($this->branch))->build();
        $service = app(ProcurementService::class);
        $order = $service->create($this->selectedActor($f['finance']), $this->input($f, ['expected_delivery_date' => '2026-12-01', 'supplier_reference' => 'PRESERVE']));
        $migration = require database_path('migrations/2026_11_26_120000_add_purchase_order_delivery_metadata.php');
        $migration->up();
        $migration->down();
        $migration->up();
        $this->assertSame('PRESERVE', $order->refresh()->supplier_reference);
        $this->assertSame('2026-12-01', $order->expected_delivery_date->format('Y-m-d'));
        $this->assertNull($f['order']->refresh()->supplier_reference);
        $this->assertNull($f['order']->expected_delivery_date);
        $this->assertThrows(fn () => DB::transaction(fn () => DB::table('inventory_purchase_orders')->where('id', $order->id)->update(['supplier_reference' => 'NO-VERSION'])), QueryException::class);
    }

    public function test_http_create_edit_and_scoped_directory_expose_only_saved_metadata(): void
    {
        $f = (new VerifiedReceivingFixture($this->branch))->build();
        $actor = $this->selectedActor($f['receiver']);
        $this->actingAs($actor)->post('/inventory/purchase-orders', $this->input($f, ['expected_delivery_date' => '2027-01-02', 'supplier_reference' => 'HTTP-QUOTE']))->assertSessionHasNoErrors()->assertRedirect();
        $order = PurchaseOrder::query()->where('supplier_reference', 'HTTP-QUOTE')->sole();
        $this->patch('/inventory/purchase-orders/'.$order->public_id, $this->input($f, ['lock_version' => 1, 'supplier_reference' => 'EDITED-QUOTE']))->assertSessionHasNoErrors();
        $rows = app(InventoryOperationsDirectoryService::class)->directory($actor)['purchaseOrders'];
        $row = collect($rows)->firstWhere('publicId', $order->public_id);
        $this->assertSame('2027-01-02', $row['expectedDeliveryDate']);
        $this->assertSame('EDITED-QUOTE', $row['supplierReference']);
        $this->patch('/inventory/purchase-orders/'.$order->public_id, $this->input($f, ['lock_version' => 1, 'supplier_reference' => 'STALE']))->assertSessionHasErrors();
        $this->assertSame('EDITED-QUOTE', $order->refresh()->supplier_reference);
        $this->assertSame(2, $order->lock_version);
        $denied = $this->selectedActor((new VerifiedReceivingFixture($this->branch))->actor('ca'));
        $this->actingAs($denied)->patch('/inventory/purchase-orders/'.$order->public_id, $this->input($f, ['lock_version' => 2, 'supplier_reference' => 'DENIED']))->assertForbidden();
        $this->assertSame('EDITED-QUOTE', $order->refresh()->supplier_reference);
    }

    public function test_revoked_and_inactive_create_authority_cannot_change_saved_metadata(): void
    {
        $f = (new VerifiedReceivingFixture($this->branch))->build();
        $service = app(ProcurementService::class);
        $actor = $this->selectedActor($f['receiver']);
        $order = $service->create($actor, $this->input($f, ['supplier_reference' => 'UNCHANGED']));
        $actor->syncRoles(['ca']);
        $this->assertThrows(fn () => $service->updateDraft($actor, $order, $this->input($f, ['lock_version' => 1, 'supplier_reference' => 'REVOKED'])), AuthorizationException::class);
        $actor->syncRoles(['ca_supervisor']);
        $actor->forceFill(['is_active' => false])->save();
        $this->assertThrows(fn () => $service->updateDraft($actor, $order, $this->input($f, ['lock_version' => 1, 'supplier_reference' => 'INACTIVE'])), AuthorizationException::class);
        $this->assertSame('UNCHANGED', $order->refresh()->supplier_reference);
        $this->assertSame(1, $order->lock_version);
    }
}
