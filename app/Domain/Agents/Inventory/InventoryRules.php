<?php

namespace App\Domain\Agents\Inventory;

use Carbon\CarbonImmutable;

/**
 * The fixed rules of contract section 6, computed in PHP with whole thousandths (never floating point) from the
 * three inventory data sources. Each method returns proposal payloads in the exact ProposalTypes shape.
 */
final class InventoryRules
{
    public const EXPIRY_WINDOW_DAYS = 90;

    public const USE_FIRST_FROM_DAYS = 60;

    public const SLOW_MOVING_DAYS = 60;

    /** At or beyond this many days without dispensing, the suggestion is to stop reordering instead of reviewing. */
    public const STOP_REORDERING_DAYS = 120;

    /**
     * Transfer: only when this branch is below its reorder level and a sending branch stays at or above its own
     * level after sending; the quantity is the smaller of the shortage and that surplus. The sender with the
     * largest surplus is chosen (ties: branch code). A sender's surplus is reduced by what it was already asked
     * to send today to other branches, so two branches are never offered the same stock.
     *
     * @param  array<string, mixed>  $balances  the `inventory.branch_balances` result
     * @param  array<string, int>  $alreadySending  thousandths keyed "SKU public id|sender branch code"
     * @return list<array<string, string>>
     */
    public static function transfers(array $balances, string $branchCode, array $alreadySending = []): array
    {
        $proposals = [];
        foreach ((array) $balances['skus'] as $sku) {
            $perBranch = collect((array) $sku['perBranch'])->keyBy('branch');
            $here = $perBranch->get($branchCode);
            if ($here === null) {
                continue;
            }
            $shortage = self::milli($here['reorderLevel']) - self::milli($here['eligible']);
            if ($shortage <= 0) {
                continue;
            }
            $best = null;
            foreach ($perBranch as $code => $there) {
                $surplus = self::milli($there['eligible']) - self::milli($there['reorderLevel']) - ($alreadySending[$sku['sku'].'|'.$code] ?? 0);
                if ($code === $branchCode || $surplus <= 0) {
                    continue;
                }
                if ($best === null || $surplus > $best['surplus'] || ($surplus === $best['surplus'] && strcmp((string) $code, $best['code']) < 0)) {
                    $best = ['code' => (string) $code, 'surplus' => $surplus, 'row' => $there];
                }
            }
            if ($best === null) {
                continue;
            }
            $proposals[] = [
                'sku_public_id' => (string) $sku['sku'],
                'from_branch_code' => $best['code'],
                'to_branch_code' => $branchCode,
                'quantity' => self::fromMilli(min($shortage, $best['surplus'])),
                'from_current_quantity' => self::fromMilli(self::milli($best['row']['eligible'])),
                'from_reorder_level' => self::fromMilli(self::milli($best['row']['reorderLevel'])),
                'to_current_quantity' => self::fromMilli(self::milli($here['eligible'])),
                'to_reorder_level' => self::fromMilli(self::milli($here['reorderLevel'])),
            ];
        }

        return $proposals;
    }

    /**
     * Restock: reorder level minus current quantity, rounded up to whole units, per SKU and location at or below
     * its level. When the location has a maximum (STK-30) the suggestion fills up to the maximum instead (maximum
     * minus current quantity); the trigger is the same, so a location exactly at its reorder level still gets none.
     * Quantity already covered by a transfer suggestion for the same SKU is taken off first; a location with
     * nothing left to order (exactly at its level, or covered by the transfer) gets no suggestion.
     *
     * @param  list<array<string, mixed>>  $lowStock  `inventory.stock_review` rows.lowStock
     * @param  array<string, string>  $transferred  quantity by SKU public id
     * @return list<array<string, string>>
     */
    public static function restocks(array $lowStock, array $transferred = []): array
    {
        $covered = array_map(fn (string $quantity): int => self::milli($quantity), $transferred);
        $proposals = [];
        foreach ($lowStock as $row) {
            $sku = (string) $row['sku'];
            $shortage = self::milli($row['reorderLevel']) - self::milli($row['quantity']);
            $maxLevel = isset($row['maxLevel']) ? self::milli($row['maxLevel']) : null;
            if ($shortage > 0 && $maxLevel !== null) {
                $shortage = max($maxLevel - self::milli($row['quantity']), $shortage);
            }
            $cover = min(max($shortage, 0), $covered[$sku] ?? 0);
            $covered[$sku] = ($covered[$sku] ?? 0) - $cover;
            $shortage -= $cover;
            if ($shortage <= 0) {
                continue;
            }
            $proposal = [
                'sku_public_id' => $sku,
                'location_public_id' => (string) $row['location'],
                'current_quantity' => self::fromMilli(self::milli($row['quantity'])),
                'reorder_level' => self::fromMilli(self::milli($row['reorderLevel'])),
                'suggested_quantity' => self::fromMilli(intdiv($shortage + 999, 1000) * 1000),
                'reason_code' => 'at_or_below_reorder_level',
            ];
            if ($maxLevel !== null) {
                $proposal['max_level'] = self::fromMilli($maxLevel);
            }
            $proposals[] = $proposal;
        }

        return $proposals;
    }

    /**
     * Expiry: batches with positive quantity expiring within 90 days of the branch-local date, or already
     * expired; `use_first` with 60 to 90 days left, `write_off_review` under 60 days or expired.
     *
     * @param  list<array<string, mixed>>  $batches  `inventory.stock_review` rows.expiring and rows.expired
     * @return list<array<string, mixed>>
     */
    public static function expiryWarnings(array $batches, string $today): array
    {
        $day = CarbonImmutable::parse($today)->startOfDay();
        $proposals = [];
        foreach ($batches as $row) {
            $daysLeft = (int) $day->diffInDays(CarbonImmutable::parse((string) $row['expiryDate'])->startOfDay(), false);
            if (self::milli($row['quantity']) <= 0 || $daysLeft > self::EXPIRY_WINDOW_DAYS) {
                continue;
            }
            $proposals[] = [
                'sku_public_id' => (string) $row['sku'],
                'batch_public_id' => (string) $row['batch'],
                'location_public_id' => (string) $row['location'],
                'quantity' => self::fromMilli(self::milli($row['quantity'])),
                'expiry_date' => (string) $row['expiryDate'],
                'days_left' => $daysLeft,
                'suggested_action' => $daysLeft >= self::USE_FIRST_FROM_DAYS ? 'use_first' : 'write_off_review',
            ];
        }

        return $proposals;
    }

    /**
     * Slow-moving: positive quantity and no dispensing for 60 days or more. Stock never dispensed from a location
     * counts from the day it first arrived there, so new stock is not called slow. `review` from 60 days,
     * `stop_reordering` from 120 days.
     *
     * @param  list<array<string, mixed>>  $locations  `inventory.dispensing_activity` locations
     * @return list<array<string, mixed>>
     */
    public static function slowMoving(array $locations): array
    {
        $proposals = [];
        foreach ($locations as $row) {
            $days = $row['daysSinceDispensed'] ?? $row['daysSinceArrived'];
            if (self::milli($row['onHand']) <= 0 || $days === null || (int) $days < self::SLOW_MOVING_DAYS) {
                continue;
            }
            $proposals[] = [
                'sku_public_id' => (string) $row['sku'],
                'location_public_id' => (string) $row['location'],
                'quantity' => self::fromMilli(self::milli($row['onHand'])),
                'last_dispensed_date' => $row['lastDispensedDate'],
                'days_without_dispensing' => (int) $days,
                'suggested_action' => (int) $days >= self::STOP_REORDERING_DAYS ? 'stop_reordering' : 'review',
            ];
        }

        return $proposals;
    }

    public static function milli(mixed $value): int
    {
        $text = trim((string) ($value ?? '0'));
        $negative = str_starts_with($text, '-');
        [$whole, $fraction] = array_pad(explode('.', ltrim($text, '+-'), 2), 2, '');
        $milli = (int) $whole * 1000 + (int) str_pad(substr($fraction, 0, 3), 3, '0');

        return $negative ? -$milli : $milli;
    }

    public static function fromMilli(int $milli): string
    {
        $sign = $milli < 0 ? '-' : '';
        $milli = abs($milli);

        return $sign.intdiv($milli, 1000).'.'.str_pad((string) ($milli % 1000), 3, '0', STR_PAD_LEFT);
    }
}
