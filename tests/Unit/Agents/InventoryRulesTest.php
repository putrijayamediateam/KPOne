<?php

namespace Tests\Unit\Agents;

use App\Domain\Agents\Inventory\InventoryRules;
use PHPUnit\Framework\TestCase;

/** AG-6: the boundaries of the fixed rules in contract section 6. */
class InventoryRulesTest extends TestCase
{
    private const SKU = '11111111-1111-4111-8111-111111111111';

    private const LOC = '22222222-2222-4222-8222-222222222222';

    private const BATCH = '33333333-3333-4333-8333-333333333333';

    /** @return array<string, mixed> */
    private function low(string $quantity, string $level): array
    {
        return ['sku' => self::SKU, 'location' => self::LOC, 'quantity' => $quantity, 'reorderLevel' => $level];
    }

    public function test_restock_is_the_shortage_rounded_up_and_nothing_at_the_level(): void
    {
        $this->assertSame([[
            'sku_public_id' => self::SKU, 'location_public_id' => self::LOC, 'current_quantity' => '10.000', 'reorder_level' => '20.000',
            'suggested_quantity' => '10.000', 'reason_code' => 'at_or_below_reorder_level',
        ]], InventoryRules::restocks([$this->low('10.000', '20.000')]));
        $this->assertSame('3.000', InventoryRules::restocks([$this->low('2.000', '4.500')])[0]['suggested_quantity']);
        $this->assertSame('1.000', InventoryRules::restocks([$this->low('19.999', '20.000')])[0]['suggested_quantity']);
        $this->assertSame([], InventoryRules::restocks([$this->low('20.000', '20.000')]));
    }

    public function test_restock_takes_off_what_a_transfer_already_covers(): void
    {
        $this->assertSame('4.000', InventoryRules::restocks([$this->low('10.000', '20.000')], [self::SKU => '6.000'])[0]['suggested_quantity']);
        $this->assertSame([], InventoryRules::restocks([$this->low('10.000', '20.000')], [self::SKU => '10.000']));
        // The cover is used once across the SKU's locations.
        $two = InventoryRules::restocks([$this->low('0.000', '5.000'), [...$this->low('0.000', '5.000'), 'location' => self::BATCH]], [self::SKU => '7.000']);
        $this->assertSame([self::BATCH], array_column($two, 'location_public_id'));
        $this->assertSame('3.000', $two[0]['suggested_quantity']);
    }

    /** @return array<string, mixed> */
    private function batch(string $expiry, string $quantity = '5.000'): array
    {
        return ['sku' => self::SKU, 'batch' => self::BATCH, 'location' => self::LOC, 'quantity' => $quantity, 'expiryDate' => $expiry];
    }

    public function test_expiry_window_and_actions_at_90_and_60_days(): void
    {
        $today = '2026-11-06';
        $warn = fn (string $expiry, string $quantity = '5.000'): array => InventoryRules::expiryWarnings([$this->batch($expiry, $quantity)], $today);

        $this->assertSame([], $warn('2027-02-05')); // 91 days
        $this->assertSame([90, 'use_first'], [$warn('2027-02-04')[0]['days_left'], $warn('2027-02-04')[0]['suggested_action']]);
        $this->assertSame([60, 'use_first'], [$warn('2027-01-05')[0]['days_left'], $warn('2027-01-05')[0]['suggested_action']]);
        $this->assertSame([59, 'write_off_review'], [$warn('2027-01-04')[0]['days_left'], $warn('2027-01-04')[0]['suggested_action']]);
        $this->assertSame([0, 'write_off_review'], [$warn('2026-11-06')[0]['days_left'], $warn('2026-11-06')[0]['suggested_action']]);
        $this->assertSame([-3, 'write_off_review'], [$warn('2026-11-03')[0]['days_left'], $warn('2026-11-03')[0]['suggested_action']]);
        $this->assertSame([], $warn('2026-12-01', '0.000'));
        $this->assertSame(['sku_public_id' => self::SKU, 'batch_public_id' => self::BATCH, 'location_public_id' => self::LOC, 'quantity' => '5.000', 'expiry_date' => '2026-12-01', 'days_left' => 25, 'suggested_action' => 'write_off_review'], $warn('2026-12-01')[0]);
    }

    /** @param list<array{0: string, 1: string, 2: string}> $branches  code, eligible, reorder level @return array<string, mixed> */
    private function balances(array $branches): array
    {
        return ['skus' => [['sku' => self::SKU, 'perBranch' => array_map(fn (array $b): array => ['branch' => $b[0], 'onHand' => $b[1], 'eligible' => $b[1], 'reorderLevel' => $b[2]], $branches)]]];
    }

    public function test_transfer_never_takes_the_sender_below_its_level(): void
    {
        $limited = InventoryRules::transfers($this->balances([['CHERAS', '4.000', '10.000'], ['PUCHONG', '8.000', '5.000'], ['SUNGAI_BESI', '5.000', '5.000']]), 'CHERAS');
        $this->assertSame([[
            'sku_public_id' => self::SKU, 'from_branch_code' => 'PUCHONG', 'to_branch_code' => 'CHERAS', 'quantity' => '3.000',
            'from_current_quantity' => '8.000', 'from_reorder_level' => '5.000', 'to_current_quantity' => '4.000', 'to_reorder_level' => '10.000',
        ]], $limited);

        // A sender exactly at its level has nothing to give.
        $this->assertSame([], InventoryRules::transfers($this->balances([['CHERAS', '4.000', '10.000'], ['PUCHONG', '5.000', '5.000']]), 'CHERAS'));
        // A receiver at its level asks for nothing.
        $this->assertSame([], InventoryRules::transfers($this->balances([['CHERAS', '10.000', '10.000'], ['PUCHONG', '50.000', '5.000']]), 'CHERAS'));
        // The shortage limits the quantity when the surplus is larger.
        $this->assertSame('6.000', InventoryRules::transfers($this->balances([['CHERAS', '4.000', '10.000'], ['PUCHONG', '50.000', '5.000']]), 'CHERAS')[0]['quantity']);
    }

    public function test_transfer_sender_is_the_largest_surplus_with_ties_by_code(): void
    {
        $this->assertSame('SUNGAI_BESI', InventoryRules::transfers($this->balances([['CHERAS', '0.000', '10.000'], ['PUCHONG', '7.000', '5.000'], ['SUNGAI_BESI', '9.000', '5.000']]), 'CHERAS')[0]['from_branch_code']);
        $this->assertSame('PUCHONG', InventoryRules::transfers($this->balances([['CHERAS', '0.000', '10.000'], ['SUNGAI_BESI', '9.000', '5.000'], ['PUCHONG', '9.000', '5.000']]), 'CHERAS')[0]['from_branch_code']);
    }

    /** @return array<string, mixed> */
    private function location(?int $sinceDispensed, ?int $sinceArrived, string $onHand = '5.000'): array
    {
        return ['sku' => self::SKU, 'location' => self::LOC, 'onHand' => $onHand, 'lastDispensedDate' => $sinceDispensed === null ? null : '2026-01-01', 'daysSinceDispensed' => $sinceDispensed, 'firstArrivedDate' => '2025-01-01', 'daysSinceArrived' => $sinceArrived];
    }

    public function test_slow_moving_from_60_days_and_stop_reordering_from_120(): void
    {
        $slow = fn (?int $dispensed, ?int $arrived, string $onHand = '5.000'): array => InventoryRules::slowMoving([$this->location($dispensed, $arrived, $onHand)]);

        $this->assertSame([], $slow(59, 400));
        $this->assertSame([60, 'review'], [$slow(60, 400)[0]['days_without_dispensing'], $slow(60, 400)[0]['suggested_action']]);
        $this->assertSame('review', $slow(119, 400)[0]['suggested_action']);
        $this->assertSame('stop_reordering', $slow(120, 400)[0]['suggested_action']);
        $this->assertSame([], $slow(200, 400, '0.000'));
        // Never dispensed: counted from the first arrival, so new stock is not slow.
        $this->assertSame([], $slow(null, 59));
        $this->assertSame(['sku_public_id' => self::SKU, 'location_public_id' => self::LOC, 'quantity' => '5.000', 'last_dispensed_date' => null, 'days_without_dispensing' => 60, 'suggested_action' => 'review'], $slow(null, 60)[0]);
    }

    public function test_quantities_are_exact_thousandths(): void
    {
        $this->assertSame([4500, -1250, 0, 7], [InventoryRules::milli('4.5'), InventoryRules::milli('-1.25'), InventoryRules::milli(null), InventoryRules::milli('0.007')]);
        $this->assertSame(['4.500', '-1.250', '0.000'], [InventoryRules::fromMilli(4500), InventoryRules::fromMilli(-1250), InventoryRules::fromMilli(0)]);
    }
}
