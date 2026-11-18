<?php

namespace Tests\Unit\Agents;

use App\Domain\Agents\Inventory\InventoryRules;
use PHPUnit\Framework\TestCase;

/** AG-31: which location sends and which receives in a transfer suggestion. */
class TransferLocationsTest extends TestCase
{
    private const PAYLOAD = ['sku_public_id' => 'sku-1', 'from_branch_code' => 'PUCHONG', 'to_branch_code' => 'CHERAS'];

    /** @param array<string, list<array<string, string>>> $byBranch */
    private function balances(array $byBranch): array
    {
        return ['skus' => [['sku' => 'sku-1', 'locations' => $byBranch]]];
    }

    private function row(string $location, string $eligible, string $level): array
    {
        return ['location' => $location, 'eligible' => $eligible, 'reorderLevel' => $level];
    }

    public function test_the_sender_is_the_location_with_the_most_spare_and_the_receiver_the_one_furthest_below(): void
    {
        $picked = InventoryRules::transferLocations($this->balances([
            'PUCHONG' => [$this->row('p-a', '10.000', '8.000'), $this->row('p-b', '30.000', '10.000'), $this->row('p-c', '5.000', '9.000')],
            'CHERAS' => [$this->row('c-a', '4.000', '5.000'), $this->row('c-b', '1.000', '9.000'), $this->row('c-c', '20.000', '2.000')],
        ]), self::PAYLOAD);

        $this->assertSame(['source_location_public_id' => 'p-b', 'destination_location_public_id' => 'c-b'], $picked);
    }

    public function test_a_tie_goes_to_the_lower_location_id_so_the_answer_is_stable(): void
    {
        $picked = InventoryRules::transferLocations($this->balances([
            'PUCHONG' => [$this->row('p-z', '12.000', '2.000'), $this->row('p-m', '12.000', '2.000')],
            'CHERAS' => [$this->row('c-z', '0.000', '5.000'), $this->row('c-m', '0.000', '5.000')],
        ]), self::PAYLOAD);

        $this->assertSame(['source_location_public_id' => 'p-m', 'destination_location_public_id' => 'c-m'], $picked);
    }

    public function test_without_a_location_that_can_send_or_a_location_below_its_level_nothing_is_picked(): void
    {
        $atLevel = [$this->row('p-a', '8.000', '8.000')];
        $short = [$this->row('c-a', '1.000', '5.000')];

        $this->assertNull(InventoryRules::transferLocations($this->balances(['PUCHONG' => $atLevel, 'CHERAS' => $short]), self::PAYLOAD));
        $this->assertNull(InventoryRules::transferLocations($this->balances(['PUCHONG' => [$this->row('p-a', '9.000', '8.000')], 'CHERAS' => [$this->row('c-a', '5.000', '5.000')]]), self::PAYLOAD));
        $this->assertNull(InventoryRules::transferLocations($this->balances(['PUCHONG' => [], 'CHERAS' => $short]), self::PAYLOAD));
        $this->assertNull(InventoryRules::transferLocations($this->balances(['CHERAS' => $short]), self::PAYLOAD));
    }
}
