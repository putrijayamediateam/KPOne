<?php

namespace Tests\Feature\Inventory;

use App\Domain\Access\BranchAccessService;
use App\Domain\Organisation\Inventory\Models\InventoryBatch;
use App\Domain\Organisation\Inventory\Models\InventoryLocation;
use App\Domain\Organisation\Inventory\Models\InventorySku;
use App\Domain\Organisation\Inventory\Models\StockMovement;
use App\Domain\Organisation\Inventory\Services\InventoryAvailabilityService;
use App\Domain\Organisation\Inventory\Services\InventoryControlService;
use App\Domain\Organisation\Inventory\Services\InventoryDirectoryService;
use App\Domain\Organisation\Inventory\Services\InventoryMovementService;
use App\Domain\Organisation\Inventory\Services\InventoryReferenceAdministrationService;
use App\Domain\Organisation\Inventory\Services\InventoryStockReviewService;
use App\Domain\Organisation\Inventory\Services\TreatmentUsageService;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Visit\VisitTestCase;
use Tests\Support\ReturnsLocationFixture;
use Tests\Support\SupplierReturnFixture;

/** STK-11: a patient returns location that Dispensary never picks from, and the dispensary_return movement (DSP-7). */
final class DispensaryReturnsLocationTest extends VisitTestCase
{
    private function fx(): SupplierReturnFixture
    {
        return new SupplierReturnFixture($this->branch);
    }

    private function returnsLocation(User $director, string $type = InventoryLocation::TYPE_DISPENSARY_RETURNS): InventoryLocation
    {
        return ReturnsLocationFixture::create($director, $this->branch, $type);
    }

    /** @return array{0: InventorySku, 1: InventoryBatch} */
    private function skuAndBatch(array $f): array
    {
        return [InventorySku::query()->findOrFail($f['sku']->id), InventoryBatch::query()->findOrFail($f['returnBatchId'])];
    }

    private function credit(User $actor, InventoryLocation $location, array $f, string $quantity = '2.000'): StockMovement
    {
        [$sku, $batch] = $this->skuAndBatch($f);

        return DB::transaction(fn () => app(InventoryMovementService::class)->recordDispensaryReturn($actor, $location, $sku, $batch, $quantity, (string) Str::uuid()));
    }

    private function balance(InventoryLocation $location, array $f): string
    {
        return (string) (DB::table('inventory_stock_balances')->where('inventory_location_id', $location->id)->where('inventory_batch_id', $f['returnBatchId'])->value('quantity') ?? '0.000');
    }

    private function refused(callable $attempt, string $field): string
    {
        try {
            $attempt();
            $this->fail('Expected refusal on '.$field);
        } catch (ValidationException $e) {
            $this->assertArrayHasKey($field, $e->errors());

            return $e->errors()[$field][0];
        }
    }

    private function pgRefused(callable $attempt, string $message): void
    {
        try {
            DB::transaction(fn () => $attempt());
            $this->fail('PostgreSQL accepted: '.$message);
        } catch (QueryException $e) {
            $this->assertSame('23514', $e->getCode());
            $this->assertStringContainsString($message, $e->getMessage());
        }
    }

    public function test_a_dispensary_return_credits_the_returns_location_only(): void
    {
        $f = $this->fx()->received('10.000');
        $returns = $this->returnsLocation($f['director']);
        $checker = $this->fx()->actor('ca_supervisor');
        $movement = $this->credit($checker, $returns, $f, '2.500');
        $this->assertSame(StockMovement::TYPE_DISPENSARY_RETURN, $movement->movement_type);
        $this->assertNull($movement->source_location_id);
        $this->assertSame($returns->id, $movement->destination_location_id);
        $this->assertSame('dispensary_return_check', $movement->reference_type);
        $this->assertSame('2.500', $this->balance($returns, $f));
        $this->assertSame('10.000', $this->balance($f['location'], $f));

        // To a location that is not a returns location, or with a bad quantity, nothing is written.
        $this->assertSame('Returned medicine can only go to a returns location.', $this->refused(fn () => $this->credit($checker, $f['location'], $f), 'location'));
        $this->refused(fn () => $this->credit($checker, $returns, $f, '0.0005'), 'quantity');
        $this->assertSame(1, StockMovement::query()->where('movement_type', StockMovement::TYPE_DISPENSARY_RETURN)->count());

        // The movement list shows it as a plain incoming "Patient Return", counted under Adjusted in the summary.
        $this->assertContains(StockMovement::TYPE_DISPENSARY_RETURN, InventoryDirectoryService::MOVEMENT_TYPES);
        $finance = $this->fx()->actor('finance_officer');
        $this->actingAs($finance)->get(route('inventory.index', ['tab' => 'movements', 'movement_type' => 'dispensary_return']))
            ->assertInertia(fn ($page) => $page->where('inventory.total', 1)->where('inventory.data.0.typeLabel', 'Patient Return')
                ->where('inventory.data.0.quantityDisplay', '+2.5')->where('inventory.data.0.reference', 'Patient return check'));
        $date = now()->setTimezone($this->branch->timezone)->toDateString();
        $rows = $this->actingAs($finance)->postJson(route('reports.stock-movement.run'), ['expected_branch_id' => $this->branch->id, 'from' => $date, 'to' => $date])->assertOk()->json('result.rows');
        $row = collect($rows)->first(fn (array $r) => $r[1] === $f['sku']->sku_code);
        // Received 10.000 at delivery. Adjusted holds the fixture's 10.000 opening balance (recorded today) plus the
        // 2.500 returned, and the closing reconciles.
        $this->assertSame(['10.000', '12.500', '22.500', 'Yes'], [$row[4], $row[6], $row[8], $row[9]]);
    }

    public function test_returns_stock_leaves_only_by_write_off_or_a_move_between_returns_locations(): void
    {
        $f = $this->fx()->received('10.000');
        $returns = $this->returnsLocation($f['director']);
        $supervisor = $this->fx()->actor('ca_supervisor');
        $this->credit($supervisor, $returns, $f, '3.000');
        [$sku, $batch] = $this->skuAndBatch($f);
        $ledger = app(InventoryMovementService::class);

        // Dispensing, treatment use and supplier return from a returns location are refused before any write.
        $this->refused(fn () => DB::transaction(fn () => $ledger->debitForDispense($supervisor, $returns, $sku, $batch, '1.000', 0, (string) Str::uuid())), 'location');
        $this->refused(fn () => DB::transaction(fn () => $ledger->recordTreatmentUsage($supervisor, $returns, $sku, $batch, '1.000', StockMovement::TYPE_TREATMENT_USAGE, (string) Str::uuid())), 'location');
        $this->refused(fn () => DB::transaction(fn () => $ledger->recordSupplierReturn($supervisor, $returns, $sku, $batch, '1.000', (string) Str::uuid())), 'location');
        $this->assertSame('3.000', $this->balance($returns, $f));

        // Treatment usage hides the returns location and refuses it with its own field.
        app(BranchAccessService::class)->select($supervisor, $this->branch);
        $this->actingAs($supervisor)->get(route('inventory.treatment-usage'))->assertInertia(fn ($page) => $page
            ->where('usage.stock.rows', fn ($rows) => collect($rows)->every(fn ($row) => (int) $row['location_id'] !== $returns->id)));
        $this->assertStringContainsString('patient returns location', $this->refused(fn () => app(TreatmentUsageService::class)->record($supervisor, ['expected_branch_id' => $this->branch->id,
            'retry_key' => (string) Str::uuid(), 'location_id' => $returns->id, 'batch_id' => $f['returnBatchId'], 'unit' => $sku->stock_unit, 'quantity' => '1.000', 'kind' => 'usage', 'reason' => 'wound_care']), 'location_id'));

        // A governed write-off (STK-5) is the way out; a direct transfer may only go to another returns location.
        DB::transaction(fn () => $ledger->recordGovernedChange($supervisor, $returns, $sku, $batch, '1.000', StockMovement::TYPE_STOCK_WRITEOFF, (string) Str::uuid()));
        $this->assertSame('2.000', $this->balance($returns, $f));
        $transferrer = $this->fx()->actor('ca_supervisor');
        $move = fn (InventoryLocation $from, InventoryLocation $to, string $quantity) => $ledger->transfer($transferrer, ['expected_branch_id' => $this->branch->id,
            'source_location_public_id' => $from->public_id, 'destination_location_public_id' => $to->public_id, 'sku_public_id' => $sku->public_id, 'batch_public_id' => $batch->public_id, 'quantity' => $quantity]);
        $message = 'Returned medicine can only move to another returns location, or be written off.';
        $this->assertSame($message, $this->refused(fn () => $move($returns, $f['location'], '1.000'), 'location'));
        $this->assertSame($message, $this->refused(fn () => $move($f['location'], $returns, '1.000'), 'location'));
        $shelf = $this->returnsLocation($f['director']);
        $move($returns, $shelf, '2.000');
        $this->assertSame('0.000', $this->balance($returns, $f));
        $this->assertSame('2.000', $this->balance($shelf, $f));
        $this->assertSame('10.000', $this->balance($f['location'], $f));
    }

    public function test_returns_stock_is_never_usable_stock(): void
    {
        $f = $this->fx()->received('10.000');
        $returns = $this->returnsLocation($f['director']);
        $supervisor = $this->fx()->actor('ca_supervisor');
        $this->credit($supervisor, $returns, $f, '4.000');
        [$sku] = $this->skuAndBatch($f);

        // Pickers and availability never offer returns stock, even outside the dispensary-only view.
        foreach ([false, true] as $dispensaryOnly) {
            $rows = app(InventoryAvailabilityService::class)->forSku($supervisor, $sku->id, $this->branch->id, $dispensaryOnly);
            $this->assertNotContains($returns->public_id, array_column($rows, 'locationPublicId'));
            $this->assertContains($f['location']->public_id, array_column($rows, 'locationPublicId'));
        }

        // STK-4: a reorder level on the returns location never raises or hides low stock, and returned stock is
        // not listed as expiring. A real shortage at the dispensary stays visible.
        $this->actingAs($supervisor);
        foreach ([[$returns, '99.000'], [$f['location'], '50.000']] as [$location, $level]) {
            app(InventoryControlService::class)->setReorderLevel($supervisor, ['expected_branch_id' => $this->branch->id,
                'location_public_id' => $location->public_id, 'sku_public_id' => $sku->public_id, 'reorder_level' => $level]);
        }
        $page = app(InventoryStockReviewService::class)->page($supervisor, []);
        $low = collect($page['lists']['low_stock']['rows']);
        $this->assertTrue($low->contains(fn ($row) => (int) $row['location_id'] === $f['location']->id));
        $this->assertFalse($low->contains(fn ($row) => (int) $row['location_id'] === $returns->id));
        DB::table('inventory_batches')->where('id', $f['returnBatchId'])->update(['expiry_date' => now()->setTimezone($this->branch->timezone)->addDays(10)->toDateString()]);
        $expiring = collect(app(InventoryStockReviewService::class)->page($supervisor, [])['lists']['expiring']['rows']);
        $this->assertTrue($expiring->contains(fn ($row) => (int) $row['location_id'] === $f['location']->id));
        $this->assertFalse($expiring->contains(fn ($row) => (int) $row['location_id'] === $returns->id));
        // The Inventory page's operations alerts leave returned stock out as well.
        $alerts = collect($this->actingAs($supervisor)->get(route('inventory.index'))->assertOk()->inertiaProps('operations.expiringBatches'));
        $this->assertTrue($alerts->contains('location', $f['location']->name));
        $this->assertFalse($alerts->contains('location', $returns->name));
    }

    public function test_a_returns_location_keeps_its_type(): void
    {
        $f = $this->fx()->received('2.000');
        $service = app(InventoryReferenceAdministrationService::class);
        $returns = $this->returnsLocation($f['director']);
        $this->assertSame('A returns location keeps its type. Create a new location instead.', $this->refused(fn () => $service->updateLocation($f['director'], $returns, ['type' => InventoryLocation::TYPE_DISPENSARY]), 'type'));
        $store = $this->returnsLocation($f['director'], InventoryLocation::TYPE_BRANCH_STORE);
        $this->refused(fn () => $service->updateLocation($f['director'], $store, ['type' => InventoryLocation::TYPE_DISPENSARY_RETURNS]), 'type');
        $this->assertSame(InventoryLocation::TYPE_DISPENSARY_RETURNS, $returns->refresh()->type);
        // A returns location always belongs to one branch.
        $this->refused(fn () => $service->createLocation($f['director'], null, null, ['code' => 'SYN-RET-SHARED', 'name' => 'Shared returns', 'type' => InventoryLocation::TYPE_DISPENSARY_RETURNS]), 'branch_id');
        // Other renames still work.
        $this->assertSame('Synthetic returns shelf', $service->updateLocation($f['director'], $returns, ['name' => 'Synthetic returns shelf'])->name);
    }

    public function test_postgresql_refuses_dispensing_allocating_and_retyping_a_returns_location(): void
    {
        $f = $this->fx()->received('5.000');
        $returns = $this->returnsLocation($f['director']);
        $this->credit($this->fx()->actor('ca_supervisor'), $returns, $f, '2.000');
        $base = ['public_id' => (string) Str::uuid(), 'organisation_id' => $this->organisation->id, 'inventory_sku_id' => $f['sku']->id, 'inventory_batch_id' => $f['returnBatchId'],
            'quantity' => '1.000', 'actor_user_id' => $f['director']->id, 'occurred_at' => now(), 'reference_public_id' => (string) Str::uuid(), 'created_at' => now(), 'updated_at' => now()];
        $this->pgRefused(fn () => DB::table('stock_movements')->insert([...$base, 'movement_type' => 'dispense', 'source_location_id' => $returns->id, 'reference_type' => 'dispensary_allocation']), 'can only be written off or transferred');
        $this->pgRefused(fn () => DB::table('stock_movements')->insert([...$base, 'movement_type' => 'treatment_waste', 'source_location_id' => $returns->id, 'reference_type' => 'inventory_treatment_usage']), 'can only be written off or transferred');
        $this->pgRefused(fn () => DB::table('stock_movements')->insert([...$base, 'movement_type' => 'purchase_receipt', 'destination_location_id' => $returns->id, 'reference_type' => 'inventory_goods_receipt']), 'Only returned medicine');
        $this->pgRefused(fn () => DB::table('stock_movements')->insert([...$base, 'movement_type' => 'dispensary_return', 'destination_location_id' => $f['location']->id, 'reference_type' => 'dispensary_return_check']), 'must go to an active returns location');
        $this->pgRefused(fn () => DB::table('stock_movements')->insert([...$base, 'movement_type' => 'dispensary_return', 'destination_location_id' => $returns->id, 'reference_type' => 'inventory_adjustment']), 'must go to an active returns location');
        $this->pgRefused(fn () => DB::table('stock_movements')->insert([...$base, 'movement_type' => 'transfer', 'source_location_id' => $returns->id, 'destination_location_id' => $f['location']->id, 'reference_type' => 'inventory_transfer']), 'only move to another returns location');
        $this->pgRefused(fn () => DB::table('stock_movements')->insert([...$base, 'movement_type' => 'transfer_dispatch', 'source_location_id' => $returns->id, 'reference_type' => 'inventory_stock_request']), 'can only be written off or transferred');
        $this->pgRefused(fn () => DB::table('dispensary_item_batch_allocations')->insert(['inventory_location_id' => $returns->id]), 'never dispensed');
        $this->pgRefused(fn () => DB::table('inventory_locations')->where('id', $returns->id)->update(['type' => 'dispensary']), 'keeps its type');
        $this->pgRefused(fn () => DB::table('inventory_locations')->where('id', $f['location']->id)->update(['type' => 'dispensary_returns']), 'keeps its type');
        $this->assertSame('2.000', $this->balance($returns, $f));
    }

    public function test_the_migration_replays_and_rolls_back(): void
    {
        $migration = require database_path('migrations/2026_11_07_000400_add_dispensary_returns_location.php');
        $migration->down();
        $this->assertFalse(DB::selectOne("SELECT EXISTS (SELECT 1 FROM pg_trigger WHERE tgname='stock_movements_returns_location') AS present")->present);
        $migration->up();
        $this->assertTrue(DB::selectOne("SELECT EXISTS (SELECT 1 FROM pg_trigger WHERE tgname='stock_movements_returns_location') AS present")->present);
        $this->assertTrue(DB::selectOne("SELECT EXISTS (SELECT 1 FROM pg_trigger WHERE tgname='dispensary_allocations_returns_location') AS present")->present);
        $this->assertTrue(DB::selectOne("SELECT EXISTS (SELECT 1 FROM pg_trigger WHERE tgname='inventory_locations_returns_type') AS present")->present);
    }
}
