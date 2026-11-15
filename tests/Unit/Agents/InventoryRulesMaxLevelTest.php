<?php

namespace Tests\Unit\Agents;

use App\Domain\Agents\Inventory\InventoryRules;
use PHPUnit\Framework\TestCase;

/** AG-14: restock fills up to the maximum when the location has one (STK-30); the trigger is unchanged. */
class InventoryRulesMaxLevelTest extends TestCase
{
    private const SKU = '11111111-1111-4111-8111-111111111111';

    private const LOC = '22222222-2222-4222-8222-222222222222';

    /** @return array<string, mixed> */
    private function low(string $quantity, string $level, ?string $max): array
    {
        return ['sku' => self::SKU, 'location' => self::LOC, 'quantity' => $quantity, 'reorderLevel' => $level, 'maxLevel' => $max];
    }

    public function test_a_maximum_makes_the_suggestion_fill_up_to_it_and_the_payload_says_so(): void
    {
        $this->assertSame([[
            'sku_public_id' => self::SKU, 'location_public_id' => self::LOC, 'current_quantity' => '10.000', 'reorder_level' => '20.000',
            'suggested_quantity' => '40.000', 'reason_code' => 'at_or_below_reorder_level', 'max_level' => '50.000',
        ]], InventoryRules::restocks([$this->low('10.000', '20.000', '50.000')]));
        // Rounded up to whole units, like the reorder-level case.
        $this->assertSame('41.000', InventoryRules::restocks([$this->low('9.500', '20.000', '50.000')])[0]['suggested_quantity']);
    }

    public function test_without_a_maximum_nothing_changes_and_no_max_field_is_added(): void
    {
        $withNull = InventoryRules::restocks([$this->low('10.000', '20.000', null)]);
        $without = InventoryRules::restocks([['sku' => self::SKU, 'location' => self::LOC, 'quantity' => '10.000', 'reorderLevel' => '20.000']]);
        $this->assertSame($without, $withNull);
        $this->assertSame('10.000', $withNull[0]['suggested_quantity']);
        $this->assertArrayNotHasKey('max_level', $withNull[0]);
    }

    public function test_the_trigger_is_unchanged_exactly_at_the_reorder_level_still_gets_nothing(): void
    {
        $this->assertSame([], InventoryRules::restocks([$this->low('20.000', '20.000', '50.000')]));
    }

    public function test_a_transfer_already_covering_part_comes_off_the_fill_to_maximum(): void
    {
        $this->assertSame('10.000', InventoryRules::restocks([$this->low('10.000', '20.000', '50.000')], [self::SKU => '30.000'])[0]['suggested_quantity']);
        $this->assertSame([], InventoryRules::restocks([$this->low('10.000', '20.000', '50.000')], [self::SKU => '40.000']));
    }
}
