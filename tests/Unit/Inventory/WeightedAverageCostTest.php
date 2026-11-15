<?php

namespace Tests\Unit\Inventory;

use App\Domain\Organisation\Inventory\Services\WeightedAverageCostService as Wac;
use PHPUnit\Framework\TestCase;

/**
 * STK-27: hand-worked examples for each weighted average rule, on synthetic movement rows (no database). Money is in
 * thousandths of a sen and quantity in thousandths of a unit; 1 unit at 100 sen = 100,000 thousandths of a sen.
 */
final class WeightedAverageCostTest extends TestCase
{
    /** Locations: 1 and 2 at branch 10, 3 at branch 20, 4 the organisation store. */
    private const BRANCH_OF = [1 => 10, 2 => 10, 3 => 20, 4 => 0];

    private int $id = 0;

    private function row(string $type, string $quantity, ?int $from = null, ?int $to = null, array $extra = [], int $sku = 1): object
    {
        return (object) ['id' => ++$this->id, 'movement_type' => $type, 'inventory_sku_id' => $sku, 'source_location_id' => $from, 'destination_location_id' => $to,
            'quantity' => $quantity, 'unit_cost_sen' => null, 'cost_total_sen' => null, 'cost_source' => null, 'reference_public_id' => null, ...$extra];
    }

    /** @return array<string, mixed> */
    private function position(array $result, int $branch, int $sku = 1): array
    {
        foreach ($result['positions'] as $position) {
            if ($position['sku_id'] === $sku && $position['branch_key'] === $branch) {
                return $position;
            }
        }
        $this->fail("No position for SKU $sku at branch $branch.");
    }

    public function test_a_worked_month_of_receipts_dispensing_a_transfer_an_estimate_and_a_count_gain(): void
    {
        $rows = [
            $this->row('opening_balance', '10.000', null, 1, ['unit_cost_sen' => 100]),                 // 10 at 100 sen = RM 10.00
            $this->row('purchase_receipt', '10.000', null, 1, ['cost_total_sen' => 1500, 'cost_source' => 'actual']), // +10 for RM 15.00
            $this->row('dispense', '4.000', 2, null),                                                     // avg 125 sen: 4 = RM 5.00
            $this->row('transfer_dispatch', '6.000', 1, null, ['reference_public_id' => 'R1']),           // 6 = RM 7.50 into transit
            $this->row('transfer_receipt', '5.000', null, 3, ['reference_public_id' => 'R1']),            // 5 of the 6 arrive: RM 6.25
            $this->row('purchase_receipt', '2.000', null, 1, ['unit_cost_sen' => 200, 'cost_source' => 'estimate']), // +2 at the estimate
            $this->row('stocktake_gain', '1.000', null, 2),                                              // no cost: at the average
        ];
        $result = (new Wac)->replayRows($rows, self::BRANCH_OF);
        $m = $result['movements'];

        $this->assertSame(1_000_000, $m[1]['value_milli_sen']);
        $this->assertSame('opening', $m[1]['basis']);
        $this->assertSame(1_500_000, $m[2]['value_milli_sen']);
        $this->assertSame('receipt', $m[2]['basis']);
        $this->assertSame(500_000, $m[3]['value_milli_sen']);
        $this->assertSame(750_000, $m[4]['value_milli_sen']);
        $this->assertSame(625_000, $m[5]['value_milli_sen']);
        $this->assertSame('transfer', $m[5]['basis']);
        $this->assertSame(400_000, $m[6]['value_milli_sen']);
        $this->assertSame('estimate', $m[6]['basis']);
        $this->assertTrue($m[6]['estimate']);
        // Branch 10 held 12 units worth 1,650,000 (avg 137.5 sen); one more at that average.
        $this->assertSame(137_500, $m[7]['value_milli_sen']);
        $this->assertSame('average', $m[7]['basis']);

        $branch10 = $this->position($result, 10);
        $this->assertSame(13_000, $branch10['quantity']);
        $this->assertSame(1_787_500, $branch10['value_milli_sen']);
        $this->assertSame(137_500, Wac::average($branch10));
        $this->assertTrue($branch10['includes_estimate']);
        $this->assertSame(1788, Wac::sen($branch10['value_milli_sen']));

        $branch20 = $this->position($result, 20);
        $this->assertSame(5_000, $branch20['quantity']);
        $this->assertSame(625_000, $branch20['value_milli_sen']);
        $this->assertSame(125_000, Wac::average($branch20));
        $this->assertFalse($branch20['includes_estimate']);

        // The unit not yet received stays in transit at the cost it left at.
        $this->assertSame([['sku_id' => 1, 'reference' => 'R1', 'quantity' => 1_000, 'value_milli_sen' => 125_000, 'uncosted_quantity' => 0, 'includes_estimate' => false]], $result['inTransit']);

        // Nothing is lost or made up: what came in equals what is on hand, in transit and gone out.
        $in = 1_000_000 + 1_500_000 + 400_000 + 137_500;
        $this->assertSame($in, $branch10['value_milli_sen'] + $branch20['value_milli_sen'] + 125_000 + 500_000);
    }

    public function test_a_box_price_per_tablet_keeps_fractions_of_a_sen(): void
    {
        // A box of 100 tablets for RM 12.34 is 12.34 sen a tablet (the STK-10 gap 3).
        $result = (new Wac)->replayRows([
            $this->row('purchase_receipt', '100.000', null, 1, ['cost_total_sen' => 1234, 'cost_source' => 'actual']),
            $this->row('dispense', '3.000', 1, null),
        ], self::BRANCH_OF);

        $this->assertSame(37_020, $result['movements'][2]['value_milli_sen']);
        $this->assertSame(37, Wac::sen($result['movements'][2]['value_milli_sen']));
        $this->assertSame(12_340, Wac::average($this->position($result, 10)));
    }

    public function test_rounding_never_loses_or_makes_money_when_stock_runs_out(): void
    {
        // RM 0.01 (1,000 thousandths of a sen) over 3 units, taken one at a time.
        $result = (new Wac)->replayRows([
            $this->row('purchase_receipt', '3.000', null, 1, ['cost_total_sen' => 1, 'cost_source' => 'actual']),
            $this->row('dispense', '1.000', 1, null),
            $this->row('dispense', '1.000', 1, null),
            $this->row('dispense', '1.000', 1, null),
        ], self::BRANCH_OF);
        $out = array_sum(array_map(fn (array $m): int => $m['type'] === 'dispense' ? $m['value_milli_sen'] : 0, $result['movements']));

        $this->assertSame([333, 334, 333], [$result['movements'][2]['value_milli_sen'], $result['movements'][3]['value_milli_sen'], $result['movements'][4]['value_milli_sen']]);
        $this->assertSame(1_000, $out);
        $branch = $this->position($result, 10);
        $this->assertSame(0, $branch['quantity']);
        $this->assertSame(0, $branch['value_milli_sen']);
        $this->assertNull(Wac::average($branch));
    }

    public function test_stock_with_no_cost_and_no_average_is_counted_apart_never_valued_at_zero(): void
    {
        $result = (new Wac)->replayRows([
            $this->row('opening_balance', '3.000', null, 3),                                              // no cost, no average yet
            $this->row('dispense', '1.000', 3, null),
            $this->row('purchase_receipt', '2.000', null, 3, ['cost_total_sen' => 300, 'cost_source' => 'actual']),
            $this->row('opening_balance', '1.000', null, 3),                                              // no cost, now at the average
        ], self::BRANCH_OF);
        $m = $result['movements'];

        $this->assertSame('no_cost', $m[1]['basis']);
        $this->assertSame(3_000, $m[1]['uncosted_quantity']);
        $this->assertSame(0, $m[2]['value_milli_sen']);
        $this->assertSame(1_000, $m[2]['uncosted_quantity']);
        $this->assertSame('average', $m[4]['basis']);
        $this->assertSame(150_000, $m[4]['value_milli_sen']);

        $branch = $this->position($result, 20);
        $this->assertSame(5_000, $branch['quantity']);
        $this->assertSame(3_000, $branch['costed_quantity']);
        $this->assertSame(2_000, $branch['uncosted_quantity']);
        $this->assertSame(450_000, $branch['value_milli_sen']);
        $this->assertSame(150_000, Wac::average($branch));
    }

    public function test_writeoffs_returns_usage_and_supplier_returns_leave_at_the_average_and_moves_inside_a_branch_change_nothing(): void
    {
        $result = (new Wac)->replayRows([
            $this->row('purchase_receipt', '4.000', null, 1, ['cost_total_sen' => 1000, 'cost_source' => 'actual']), // 250 sen each
            $this->row('transfer', '2.000', 1, 2),                                                       // same branch: nothing moves
            $this->row('expiry_writeoff', '1.000', 2, null),
            $this->row('supplier_return', '1.000', 1, null),
            $this->row('treatment_usage', '1.000', 1, null),
            $this->row('dispensary_return', '1.000', null, 2),                                           // a patient return, at the average
            $this->row('transfer', '1.000', 2, 4),                                                       // to the organisation store
        ], self::BRANCH_OF);
        $m = $result['movements'];

        $this->assertSame(0, $m[2]['value_milli_sen']);
        foreach ([3, 4, 5, 6, 7] as $id) {
            $this->assertSame(250_000, $m[$id]['value_milli_sen'], "movement $id");
        }
        $this->assertSame('transfer', $m[7]['basis']);
        $this->assertSame(250_000, $this->position($result, 0)['value_milli_sen']);
        $branch = $this->position($result, 10);
        $this->assertSame(1_000, $branch['quantity']);
        $this->assertSame(250_000, $branch['value_milli_sen']);
    }

    public function test_a_past_date_gives_the_same_figures_whatever_comes_later(): void
    {
        $earlier = [
            $this->row('opening_balance', '10.000', null, 1, ['unit_cost_sen' => 100]),
            $this->row('purchase_receipt', '10.000', null, 1, ['cost_total_sen' => 3000, 'cost_source' => 'actual']),
            $this->row('dispense', '5.000', 1, null),
        ];
        $before = (new Wac)->replayRows($earlier, self::BRANCH_OF);
        $later = [...$earlier,
            $this->row('purchase_receipt', '50.000', null, 1, ['cost_total_sen' => 99999, 'cost_source' => 'actual']),
            $this->row('dispense', '20.000', 1, null),
        ];
        $after = (new Wac)->replayRows($later, self::BRANCH_OF);

        foreach ([1, 2, 3] as $id) {
            $this->assertSame($before['movements'][$id], $after['movements'][$id]);
        }
    }

    public function test_an_estimate_mark_clears_once_that_stock_is_gone(): void
    {
        $result = (new Wac)->replayRows([
            $this->row('purchase_receipt', '2.000', null, 1, ['unit_cost_sen' => 50, 'cost_source' => 'estimate']),
            $this->row('dispense', '2.000', 1, null),
            $this->row('purchase_receipt', '2.000', null, 1, ['cost_total_sen' => 200, 'cost_source' => 'actual']),
        ], self::BRANCH_OF);

        $this->assertTrue($result['movements'][2]['estimate']);
        $this->assertFalse($this->position($result, 10)['includes_estimate']);
    }

    public function test_twenty_thousand_movements_replay_quickly_with_whole_numbers(): void
    {
        $rows = [];
        for ($i = 0; $i < 10_000; $i++) {
            $rows[] = $this->row('purchase_receipt', '7.125', null, 1 + ($i % 3), ['cost_total_sen' => 1000 + $i, 'cost_source' => 'actual'], 1 + ($i % 50));
            $rows[] = $this->row('dispense', '3.500', 1 + ($i % 3), null, [], 1 + ($i % 50));
        }
        usort($rows, fn (object $a, object $b): int => [$a->inventory_sku_id, $a->id] <=> [$b->inventory_sku_id, $b->id]);
        $started = hrtime(true);
        $result = (new Wac)->replayRows($rows, self::BRANCH_OF);
        $seconds = (hrtime(true) - $started) / 1e9;

        $this->assertLessThan(5.0, $seconds);
        $this->assertCount(20_000, $result['movements']);
        foreach ($result['positions'] as $position) {
            $this->assertIsInt($position['value_milli_sen']);
            $this->assertGreaterThanOrEqual(0, $position['value_milli_sen']);
        }
    }
}
