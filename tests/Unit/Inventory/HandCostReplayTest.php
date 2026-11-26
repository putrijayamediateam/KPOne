<?php

namespace Tests\Unit\Inventory;

use App\Domain\Organisation\Inventory\Services\WeightedAverageCostService;
use PHPUnit\Framework\TestCase;

final class HandCostReplayTest extends TestCase
{
    private function row(int $id, string $type, string $quantity, ?int $from, ?int $to, array $cost = []): object
    {
        return (object) ['id' => $id, 'movement_type' => $type, 'inventory_sku_id' => 1, 'source_location_id' => $from, 'destination_location_id' => $to,
            'quantity' => $quantity, 'unit_cost_sen' => null, 'cost_total_sen' => null, 'cost_source' => null, 'reference_public_id' => 'SYN-TRANSFER', ...$cost];
    }

    private function accepted(int $cost = 20000): array
    {
        return ['id' => 70, 'cost_total_sen' => $cost, 'accepted_at' => '2026-11-26 00:00:00'];
    }

    public function test_reversal_at_average_preserves_the_hand_cost_residual_and_provenance(): void
    {
        $r = (new WeightedAverageCostService)->replayRows([
            $this->row(1, 'opening_balance', '10.000', null, 1, ['unit_cost_sen' => 1000]),
            $this->row(2, 'purchase_receipt', '10.000', null, 1, ['unit_cost_sen' => 500, 'cost_source' => 'estimate']),
            $this->row(3, 'purchase_receipt_reversal', '10.000', 1, null),
        ], [1 => 10], [2 => $this->accepted()]);
        $this->assertSame(15000000, $r['positions'][0]['value_milli_sen']); // RM150 remains, not RM100.
        $this->assertSame(10000, $r['positions'][0]['quantity']);
        $this->assertSame(15000000, $r['movements'][3]['value_milli_sen']);
        $this->assertFalse($r['positions'][0]['includes_estimate']);
        $this->assertSame('hand', $r['movements'][2]['basis']);
        $this->assertSame([70], array_keys($r['positions'][0]['hand_costs']));
        $this->assertSame([70], array_keys($r['movements'][3]['hand_costs']));
    }

    public function test_provenance_follows_partial_transit_direct_transfer_and_average_gains_then_clears_when_empty(): void
    {
        $r = (new WeightedAverageCostService)->replayRows([
            $this->row(1, 'opening_balance', '10.000', null, 1),
            $this->row(2, 'transfer_dispatch', '10.000', 1, null),
            $this->row(3, 'transfer_receipt', '6.000', null, 2),
            $this->row(4, 'transfer', '2.000', 2, 3),
            $this->row(5, 'stocktake_gain', '1.000', null, 3),
            $this->row(6, 'dispense', '3.000', 3, null),
            $this->row(7, 'opening_balance', '1.000', null, 3, ['unit_cost_sen' => 100]),
        ], [1 => 10, 2 => 20, 3 => 30], [1 => $this->accepted()]);
        $this->assertSame(8000000, $r['inTransit'][0]['value_milli_sen']);
        $this->assertSame([70], array_keys($r['inTransit'][0]['hand_costs']));
        $this->assertSame([70], array_keys($r['movements'][5]['hand_costs']));
        $this->assertSame(6000000, $r['movements'][6]['value_milli_sen']);
        $positions = array_column($r['positions'], null, 'branch_key');
        $this->assertArrayNotHasKey('hand_costs', $positions[10]);
        $this->assertSame([70], array_keys($positions[20]['hand_costs']));
        $this->assertArrayNotHasKey('hand_costs', $positions[30]);
        $this->assertSame(100000, $positions[30]['value_milli_sen']);
    }

    public function test_actual_costs_cannot_be_overridden_but_zero_accepted_cost_is_costed_not_missing(): void
    {
        $rows = [$this->row(1, 'purchase_receipt', '10.000', null, 1, ['cost_total_sen' => 1234, 'cost_source' => 'actual'])];
        $wac = new WeightedAverageCostService;
        $this->assertSame($wac->replayRows($rows, [1 => 10]), $wac->replayRows($rows, [1 => 10], [1 => $this->accepted()]));
        $r = $wac->replayRows([$this->row(1, 'opening_balance', '10.000', null, 1)], [1 => 10], [1 => $this->accepted(0)]);
        $this->assertSame(0, $r['positions'][0]['uncosted_quantity']);
        $this->assertSame(10000, $r['positions'][0]['costed_quantity']);
        $this->assertSame(0, $r['positions'][0]['value_milli_sen']);
        $this->assertSame([70], array_keys($r['positions'][0]['hand_costs']));
    }
}
