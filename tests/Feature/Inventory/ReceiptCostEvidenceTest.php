<?php

namespace Tests\Feature\Inventory;

use App\Domain\Access\BranchAccessService;
use App\Domain\Organisation\Inventory\Models\StockMovement;
use App\Domain\Organisation\Inventory\Services\ProcurementService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Visit\VisitTestCase;
use Tests\Support\VerifiedReceivingFixture;

/** STK-10B: receipt cost evidence an accountant can rely on (owner decisions 2026-11-06). */
final class ReceiptCostEvidenceTest extends VisitTestCase
{
    /** @return array<string, mixed> */
    private function receive(array $lot, string $quantity = '2.000'): array
    {
        $f = (new VerifiedReceivingFixture($this->branch))->build('100.000');
        app(BranchAccessService::class)->select($f['receiver'], $this->branch);
        $payload = $f['payload'];
        $payload['lines'][0] = [...$payload['lines'][0], 'quantity' => $quantity, ...$lot];
        $receipt = app(ProcurementService::class)->receive($f['receiver'], $f['order'], $payload);
        $lot = DB::table('inventory_receipt_lots as lot')->join('inventory_receipt_line_evidence as line', 'line.id', '=', 'lot.line_evidence_id')
            ->where('line.goods_receipt_id', $receipt->id)->sole(['lot.*']);
        $movement = StockMovement::query()->where('movement_type', StockMovement::TYPE_PURCHASE_RECEIPT)->where('reference_public_id', $receipt->public_id)->sole();

        return [$lot, $movement, $f];
    }

    public function test_an_invoice_total_is_kept_exactly_and_marked_actual(): void
    {
        // A box of 100 tablets for RM 12.34: 12.34 sen a tablet cannot be stored per unit, so the total is kept.
        [$lot, $movement] = $this->receive(['cost_total_sen' => 1234], '100.000');
        $this->assertSame([1234, 'actual', null], [(int) $lot->cost_total_sen, $lot->cost_source, $lot->unit_cost_sen]);
        $this->assertSame([1234, 'actual', null], [(int) $movement->cost_total_sen, $movement->cost_source, $movement->unit_cost_sen]);
    }

    public function test_a_blank_cost_keeps_the_order_estimate_marked_as_an_estimate(): void
    {
        [$lot, $movement] = $this->receive([]);
        $this->assertSame([125, 'estimate', null], [(int) $lot->unit_cost_sen, $lot->cost_source, $lot->cost_total_sen]);
        $this->assertSame([125, 'estimate', null], [(int) $movement->unit_cost_sen, $movement->cost_source, $movement->cost_total_sen]);
    }

    public function test_a_legacy_unit_cost_is_actual_and_both_together_are_refused(): void
    {
        [$lot] = $this->receive(['unit_cost_sen' => 150]);
        $this->assertSame([150, 'actual', null], [(int) $lot->unit_cost_sen, $lot->cost_source, $lot->cost_total_sen]);
        try {
            $this->receive(['unit_cost_sen' => 150, 'cost_total_sen' => 300]);
            $this->fail('Both a unit cost and a total were accepted');
        } catch (ValidationException $e) {
            $this->assertSame('Enter the total cost on the supplier invoice for each batch, not a unit cost as well.', $e->errors()['lines'][0]);
        }
        try {
            $this->receive(['cost_total_sen' => -1]);
            $this->fail('A negative total was accepted');
        } catch (ValidationException $e) {
            $this->assertTrue(collect(array_keys($e->errors()))->contains(fn (string $key) => str_ends_with($key, 'cost_total_sen')), implode(', ', array_keys($e->errors())));
        }
    }

    public function test_postgresql_keeps_actual_and_estimate_apart_and_costs_only_on_receipts(): void
    {
        [$lot, $movement] = $this->receive(['cost_total_sen' => 500]);
        $refused = function (callable $write, string $constraint): void {
            try {
                DB::transaction(fn () => $write());
                $this->fail('PostgreSQL accepted a write against '.$constraint);
            } catch (QueryException $e) {
                $this->assertStringContainsString($constraint, $e->getMessage());
            }
        };
        // Lots are append-only, so each bad shape is tried as a new copy of the saved lot.
        $copy = collect((array) $lot)->except('id')->all();
        foreach ([['cost_source' => 'guess'], ['cost_source' => 'estimate'], ['cost_source' => null], ['unit_cost_sen' => 5], ['cost_total_sen' => null]] as $change) {
            $refused(fn () => DB::table('inventory_receipt_lots')->insert([...$copy, ...$change]), 'receipt_lots_cost_shape');
        }
        $base = ['public_id' => (string) Str::uuid(), 'organisation_id' => $this->organisation->id, 'inventory_sku_id' => $movement->inventory_sku_id, 'inventory_batch_id' => $movement->inventory_batch_id,
            'quantity' => '1.000', 'actor_user_id' => $movement->actor_user_id, 'occurred_at' => now(), 'reference_public_id' => (string) Str::uuid(), 'created_at' => now(), 'updated_at' => now(),
            'source_location_id' => $movement->destination_location_id, 'movement_type' => 'stock_writeoff', 'reference_type' => 'inventory_governed_case'];
        $refused(fn () => DB::table('stock_movements')->insert([...$base, 'cost_total_sen' => 100, 'cost_source' => 'actual']), 'stock_movements_cost_shape');
    }

    public function test_the_migration_replays_and_rolls_back(): void
    {
        $migration = require database_path('migrations/2026_11_07_000700_add_receipt_cost_evidence.php');
        $migration->down();
        $this->assertFalse(DB::getSchemaBuilder()->hasColumn('inventory_receipt_lots', 'cost_source'));
        $migration->up();
        $this->assertTrue(DB::getSchemaBuilder()->hasColumns('stock_movements', ['cost_total_sen', 'cost_source']));
    }
}
