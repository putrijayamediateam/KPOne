<?php

namespace Tests\Feature\Inventory;

use App\Domain\Access\BranchAccessService;
use App\Domain\Organisation\Inventory\Models\InventoryBatch;
use App\Domain\Organisation\Inventory\Services\InventoryMovementService;
use App\Domain\Organisation\Inventory\Services\StockUnitQuantity;
use App\Domain\Organisation\Inventory\Services\WeightedAverageCostService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Visit\VisitTestCase;
use Tests\Support\GovernedStockFixture;

/** STK-27: the engine reads the real ledger, agrees with stock on hand, keeps past dates fixed and writes nothing. */
final class WeightedAverageCostLedgerTest extends VisitTestCase
{
    /** @return array<string, mixed> */
    private function stock(): array
    {
        $this->travelTo(now($this->branch->timezone)->setTime(9, 0));
        // An opening with no cost (the fixture's), then a second batch opened at 100 sen a unit an hour later.
        $f = (new GovernedStockFixture($this->branch))->build('10.000');
        $this->travel(1)->hours();
        $batch = new InventoryBatch;
        $batch->forceFill(['public_id' => (string) Str::uuid(), 'organisation_id' => $this->branch->organisation_id, 'inventory_sku_id' => $f['sku']->id, 'batch_number' => 'SYN-WAC-2', 'expiry_date' => now($this->branch->timezone)->addDays(120)->toDateString(), 'received_at' => now()->subDay(), 'status' => 'available'])->save();
        app(BranchAccessService::class)->select($f['proposer'], $this->branch);
        app(InventoryMovementService::class)->openingBalance($f['proposer'], ['expected_branch_id' => $this->branch->id, 'sku_public_id' => $f['sku']->public_id, 'location_public_id' => $f['location']->public_id, 'batch_public_id' => $batch->public_id, 'quantity' => '10.000', 'unit_cost_sen' => 100]);

        return $f;
    }

    public function test_the_replay_matches_stock_on_hand_and_follows_the_no_cost_rule(): void
    {
        $f = $this->stock();
        $result = app(WeightedAverageCostService::class)->replay($this->branch->organisation_id, null, [$f['sku']->id]);
        $position = collect($result['positions'])->firstWhere('branch_key', $this->branch->id);

        $onHand = DB::table('inventory_stock_balances')->where('inventory_sku_id', $f['sku']->id)->pluck('quantity')
            ->sum(fn ($quantity): int => StockUnitQuantity::scaled((string) $quantity, true));
        $this->assertSame($onHand, $position['quantity']);
        $this->assertSame(20_000, $position['quantity']);
        // The first opening had no cost and no average to take, so it is counted apart; the second is valued.
        $this->assertSame(10_000, $position['uncosted_quantity']);
        $this->assertSame(10_000, $position['costed_quantity']);
        $this->assertSame(1_000_000, $position['value_milli_sen']);
        $this->assertSame(100_000, WeightedAverageCostService::average($position));
        $this->assertSame(['no_cost', 'opening'], array_column(array_values($result['movements']), 'basis'));
    }

    public function test_a_past_instant_ignores_later_movements(): void
    {
        $f = $this->stock();
        $firstOnly = app(WeightedAverageCostService::class)->replay($this->branch->organisation_id, now()->subMinutes(30), [$f['sku']->id]);

        $this->assertCount(1, $firstOnly['movements']);
        $position = collect($firstOnly['positions'])->firstWhere('branch_key', $this->branch->id);
        $this->assertSame(10_000, $position['quantity']);
        $this->assertSame(0, $position['value_milli_sen']);
    }

    public function test_the_replay_writes_nothing_anywhere(): void
    {
        $this->stock();
        $tables = collect(DB::select('select tablename from pg_tables where schemaname = current_schema() order by tablename'))->pluck('tablename');
        $snapshot = fn (): array => $tables->mapWithKeys(fn (string $table): array => [$table => DB::table($table)->count()])->all();
        $before = $snapshot();
        $movements = DB::table('stock_movements')->orderBy('id')->get()->toJson();

        app(WeightedAverageCostService::class)->replay($this->branch->organisation_id);

        $this->assertSame($before, $snapshot());
        $this->assertSame($movements, DB::table('stock_movements')->orderBy('id')->get()->toJson());
    }
}
