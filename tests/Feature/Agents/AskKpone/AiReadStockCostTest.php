<?php

namespace Tests\Feature\Agents\AskKpone;

use App\Domain\Access\BranchAccessService;
use App\Domain\Organisation\Inventory\Models\StockMovement;
use App\Domain\Organisation\Inventory\Services\ProcurementService;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Visit\VisitTestCase;
use Tests\Support\VerifiedReceivingFixture;

/** AG-7b (owner decision 2026-11-06, Option A): Ask KPOne labels every stock cost as actual or estimate. */
final class AiReadStockCostTest extends VisitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('The ai_read schema exists on PostgreSQL only.');
        }
    }

    /**
     * @param  array<string, mixed>  $lot
     * @return array<string, mixed>
     */
    private function receive(array $lot, string $quantity = '2.000'): array
    {
        $f = (new VerifiedReceivingFixture($this->branch))->build('100.000');
        app(BranchAccessService::class)->select($f['receiver'], $this->branch);
        $payload = $f['payload'];
        $payload['lines'][0] = [...$payload['lines'][0], 'quantity' => $quantity, ...$lot];
        $receipt = app(ProcurementService::class)->receive($f['receiver'], $f['order'], $payload);
        $id = StockMovement::query()->where('movement_type', StockMovement::TYPE_PURCHASE_RECEIPT)->where('reference_public_id', $receipt->public_id)->sole()->id;

        return (array) DB::table('ai_read.stock_movements')->where('id', $id)->sole();
    }

    public function test_an_actual_total_and_an_order_estimate_are_told_apart(): void
    {
        $actual = $this->receive(['cost_total_sen' => 1234], '100.000');
        $estimate = $this->receive([]);

        $this->assertSame([null, 1234, 'actual'], [$actual['unit_cost_sen'], (int) $actual['cost_total_sen'], $actual['cost_source']]);
        $this->assertSame([125, null, 'estimate'], [(int) $estimate['unit_cost_sen'], $estimate['cost_total_sen'], $estimate['cost_source']]);
    }

    public function test_the_column_descriptions_say_only_actual_is_an_actual_cost(): void
    {
        $comments = collect(DB::select(<<<'SQL'
            SELECT a.attname AS column_name, col_description(a.attrelid, a.attnum) AS comment
            FROM pg_attribute a WHERE a.attrelid = 'ai_read.stock_movements'::regclass AND a.attnum > 0 AND NOT a.attisdropped
            SQL))->pluck('comment', 'column_name');

        $this->assertStringContainsString('Actual only when cost_source is actual', (string) $comments['unit_cost_sen']);
        $this->assertStringContainsString('estimate', (string) $comments['unit_cost_sen']);
        $this->assertSame('actual, estimate, or empty for costs recorded before STK-10B.', $comments['cost_source']);
        $this->assertNotNull($comments['cost_total_sen']);
        $this->assertStringContainsString('cost_source', (string) DB::scalar("SELECT obj_description('ai_read.stock_movements'::regclass, 'pg_class')"));
    }

    public function test_the_view_does_not_block_rolling_back_the_cost_columns(): void
    {
        $stk10b = require database_path('migrations/2026_11_07_000700_add_receipt_cost_evidence.php');

        $stk10b->down();
        $this->assertSame([], DB::table('ai_read.stock_movements')->whereNotNull('cost_source')->pluck('id')->all());
        $stk10b->up();
        $row = $this->receive(['cost_total_sen' => 1234], '100.000');
        $this->assertSame([1234, 'actual'], [(int) $row['cost_total_sen'], $row['cost_source']]);
    }

    public function test_the_migration_rolls_back_to_the_original_view_and_replays(): void
    {
        $migration = require database_path('migrations/2026_11_07_700100_label_cost_in_ai_read_stock_movements.php');
        $columns = fn (): array => DB::table('information_schema.columns')->where('table_schema', 'ai_read')->where('table_name', 'stock_movements')
            ->orderBy('ordinal_position')->pluck('column_name')->all();

        $migration->down();
        $this->assertSame(['id', 'occurred_at', 'movement_type', 'sku_code', 'batch_number', 'source_location_id', 'destination_location_id', 'quantity', 'unit_cost_sen'], $columns());

        $migration->up();
        $migration->up();
        $this->assertSame(['id', 'occurred_at', 'movement_type', 'sku_code', 'batch_number', 'source_location_id', 'destination_location_id', 'quantity', 'unit_cost_sen', 'cost_total_sen', 'cost_source'], $columns());
    }
}
