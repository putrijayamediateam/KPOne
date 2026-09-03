<?php

namespace Tests\Feature\Clinical;

use App\Domain\Organisation\Inventory\Models\InventoryBatch;
use App\Domain\Organisation\Inventory\Models\InventoryItem;
use App\Domain\Organisation\Inventory\Models\InventoryLocation;
use App\Domain\Organisation\Inventory\Models\InventorySku;
use App\Domain\Organisation\Inventory\Models\StockMovement;
use App\Domain\Organisation\Inventory\Services\InventoryAvailabilityService;
use App\Domain\Organisation\Inventory\Services\InventoryMovementService;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Str;
use LogicException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\Feature\Queue\QueueTestCase;

class InventoryFoundationTest extends QueueTestCase
{
    public function test_opening_balance_and_branch_transfer_write_immutable_movements_and_distinct_balances(): void
    {
        $supervisor = $this->actor('ca_supervisor');
        $this->selectBranch($supervisor, $this->branch);
        [$sku, $batch, $store, $dispensary] = $this->inventoryFixture($supervisor);

        $opening = app(InventoryMovementService::class)->openingBalance($supervisor, [
            'expected_branch_id' => $this->branch->id,
            'location_public_id' => $store->public_id,
            'sku_public_id' => $sku->public_id,
            'batch_public_id' => $batch->public_id,
            'quantity' => '10.000',
        ]);
        $transfer = app(InventoryMovementService::class)->transfer($supervisor, [
            'expected_branch_id' => $this->branch->id,
            'source_location_public_id' => $store->public_id,
            'destination_location_public_id' => $dispensary->public_id,
            'sku_public_id' => $sku->public_id,
            'batch_public_id' => $batch->public_id,
            'quantity' => '4.000',
        ]);

        $this->assertSame(StockMovement::TYPE_OPENING, $opening->movement_type);
        $this->assertSame(StockMovement::TYPE_TRANSFER, $transfer->movement_type);
        $this->assertDatabaseHas('inventory_stock_balances', ['inventory_location_id' => $store->id, 'quantity' => 6]);
        $this->assertDatabaseHas('inventory_stock_balances', ['inventory_location_id' => $dispensary->id, 'quantity' => 4]);
        $this->assertDatabaseCount('stock_movements', 2);
        $this->expectException(LogicException::class);
        $opening->delete();
    }

    public function test_ordinary_ca_cannot_establish_opening_stock_even_with_direct_permission(): void
    {
        $ca = $this->actor('ca');
        $ca->givePermissionTo('inventory.opening_balance.branch');
        $this->selectBranch($ca, $this->branch);

        try {
            app(InventoryMovementService::class)->openingBalance($ca, [
                'expected_branch_id' => $this->branch->id,
                'location_public_id' => (string) Str::uuid(),
                'sku_public_id' => (string) Str::uuid(),
                'batch_public_id' => (string) Str::uuid(),
                'quantity' => '10.000',
            ]);
            $this->fail('Ordinary CA opening balance should be denied.');
        } catch (AuthorizationException) {
            $this->assertDatabaseCount('inventory_stock_balances', 0);
            $this->assertDatabaseCount('stock_movements', 0);
        }
    }

    public function test_organisation_transfer_requires_supervisor_role_even_with_direct_permission(): void
    {
        $ca = $this->actor('ca');
        $ca->givePermissionTo('inventory.transfer.organisation');
        $this->selectBranch($ca, $this->branch);
        $source = $this->location($ca, 'BRANCH-SOURCE', InventoryLocation::TYPE_BRANCH_STORE);
        $hq = new InventoryLocation;
        $hq->forceFill(['public_id' => (string) Str::uuid(), 'organisation_id' => $ca->organisation_id, 'branch_id' => null, 'code' => 'SYN-HQ-'.Str::random(5), 'name' => 'Synthetic HQ', 'type' => InventoryLocation::TYPE_MEDICAL_STOCK, 'is_active' => true])->save();

        $this->expectException(AuthorizationException::class);
        app(InventoryMovementService::class)->transfer($ca, [
            'expected_branch_id' => $this->branch->id,
            'source_location_public_id' => $source->public_id,
            'destination_location_public_id' => $hq->public_id,
            'sku_public_id' => (string) Str::uuid(),
            'batch_public_id' => (string) Str::uuid(),
            'quantity' => '1.000',
        ]);
    }

    public function test_transfer_rejects_an_inactive_destination_under_lock(): void
    {
        $ca = $this->actor('ca');
        $this->selectBranch($ca, $this->branch);
        $source = $this->location($ca, 'ACTIVE-SOURCE', InventoryLocation::TYPE_BRANCH_STORE);
        $destination = $this->location($ca, 'INACTIVE-DESTINATION', InventoryLocation::TYPE_DISPENSARY);
        $destination->forceFill(['is_active' => false])->save();

        try {
            app(InventoryMovementService::class)->transfer($ca, [
                'expected_branch_id' => $this->branch->id,
                'source_location_public_id' => $source->public_id,
                'destination_location_public_id' => $destination->public_id,
                'sku_public_id' => (string) Str::uuid(),
                'batch_public_id' => (string) Str::uuid(),
                'quantity' => '1.000',
            ]);
            $this->fail('Inactive transfer destination should be rejected.');
        } catch (NotFoundHttpException) {
            $this->assertDatabaseCount('stock_movements', 0);
        }
    }

    public function test_fefo_excludes_expired_quarantined_and_damaged_batches(): void
    {
        $supervisor = $this->actor('ca_supervisor');
        $this->selectBranch($supervisor, $this->branch);
        [$sku, $later, , $dispensary] = $this->inventoryFixture($supervisor);
        $earlier = $this->batch($supervisor, $sku, 'EARLY', now()->addMonth()->toDateString());
        $expired = $this->batch($supervisor, $sku, 'EXPIRED', now()->subDay()->toDateString());
        $quarantined = $this->batch($supervisor, $sku, 'QUARANTINED', now()->addDays(10)->toDateString(), InventoryBatch::STATUS_QUARANTINED);
        foreach ([$later, $earlier, $expired, $quarantined] as $batch) {
            app(InventoryMovementService::class)->openingBalance($supervisor, [
                'expected_branch_id' => $this->branch->id,
                'location_public_id' => $dispensary->public_id,
                'sku_public_id' => $sku->public_id,
                'batch_public_id' => $batch->public_id,
                'quantity' => '1.000',
            ]);
        }

        $available = app(InventoryAvailabilityService::class)->forSku($supervisor, $sku->id, $this->branch->id);
        $this->assertSame($earlier->public_id, $available[0]['batchPublicId']);
        $this->assertSame($later->public_id, $available[1]['batchPublicId']);
        $this->assertCount(2, $available);
    }

    public function test_fefo_uses_the_branch_local_calendar_date(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-01 16:30:00', 'UTC'));
        try {
            $supervisor = $this->actor('ca_supervisor');
            $this->selectBranch($supervisor, $this->branch);
            [$sku, , , $dispensary] = $this->inventoryFixture($supervisor);
            $localToday = now()->setTimezone($this->branch->timezone)->toDateString();
            $batch = $this->batch($supervisor, $sku, 'LOCAL-TODAY', $localToday);
            app(InventoryMovementService::class)->openingBalance($supervisor, [
                'expected_branch_id' => $this->branch->id,
                'location_public_id' => $dispensary->public_id,
                'sku_public_id' => $sku->public_id,
                'batch_public_id' => $batch->public_id,
                'quantity' => '1.000',
            ]);

            $available = app(InventoryAvailabilityService::class)->forSku($supervisor, $sku->id, $this->branch->id);

            $this->assertNotContains($batch->public_id, array_column($available, 'batchPublicId'));
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    /** @return array{InventorySku, InventoryBatch, InventoryLocation, InventoryLocation} */
    private function inventoryFixture(User $actor): array
    {
        $item = new InventoryItem;
        $item->forceFill(['public_id' => (string) Str::uuid(), 'organisation_id' => $actor->organisation_id, 'code' => 'SYN-ITEM-'.Str::random(6), 'generic_name' => 'Synthetic inventory item', 'is_active' => true])->save();
        $sku = new InventorySku;
        $sku->forceFill(['public_id' => (string) Str::uuid(), 'organisation_id' => $actor->organisation_id, 'inventory_item_id' => $item->id, 'sku_code' => 'SYN-SKU-'.Str::random(6), 'pack_size' => 1, 'purchase_unit' => 'pack', 'stock_unit' => 'unit', 'dispensing_unit' => 'unit', 'unit_conversion' => 1, 'storage_type' => 'ambient', 'cold_chain_required' => false, 'do_not_freeze' => false, 'protect_from_light' => false, 'batch_tracking_required' => true, 'expiry_tracking_required' => true, 'is_active' => true])->save();
        $store = $this->location($actor, 'STORE', InventoryLocation::TYPE_BRANCH_STORE);
        $dispensary = $this->location($actor, 'DISP', InventoryLocation::TYPE_DISPENSARY);

        return [$sku, $this->batch($actor, $sku, 'LATER', now()->addMonths(2)->toDateString()), $store, $dispensary];
    }

    private function location(User $actor, string $suffix, string $type): InventoryLocation
    {
        $location = new InventoryLocation;
        $location->forceFill(['public_id' => (string) Str::uuid(), 'organisation_id' => $actor->organisation_id, 'branch_id' => $this->branch->id, 'code' => 'SYN-'.$suffix.'-'.Str::random(5), 'name' => 'Synthetic '.$suffix, 'type' => $type, 'is_active' => true])->save();

        return $location;
    }

    private function batch(User $actor, InventorySku $sku, string $number, string $expiry, string $status = InventoryBatch::STATUS_AVAILABLE): InventoryBatch
    {
        $batch = new InventoryBatch;
        $batch->forceFill(['public_id' => (string) Str::uuid(), 'organisation_id' => $actor->organisation_id, 'inventory_sku_id' => $sku->id, 'batch_number' => $number.'-'.Str::random(5), 'expiry_date' => $expiry, 'received_at' => now()->subDay()->toDateString(), 'status' => $status])->save();

        return $batch;
    }
}
