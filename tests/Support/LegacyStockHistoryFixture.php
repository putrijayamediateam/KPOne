<?php

namespace Tests\Support;

use App\Domain\Organisation\Inventory\Models\InventoryAdjustment;
use App\Domain\Organisation\Inventory\Models\InventoryStocktake;
use App\Domain\Organisation\Inventory\Models\StockMovement;
use App\Domain\Organisation\Inventory\Services\StockUnitQuantity;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Synthetic retained history only. No application writer, trigger bypass or real-data conversion. */
final class LegacyStockHistoryFixture
{
    /** @param array<string,mixed> $f */
    public static function stocktake(User $actor, array $f, string $status = 'draft', ?string $expected = null, ?string $physical = null): InventoryStocktake
    {
        return DB::transaction(function () use ($actor, $f, $status, $expected, $physical): InventoryStocktake {
            $balance = DB::table('inventory_stock_balances')->where('inventory_location_id', $f['location']->id)->where('inventory_sku_id', $f['sku']->id)->where('inventory_batch_id', $f['batch']->id)->first();
            $expected ??= $balance?->quantity ?? '0.000';
            $id = DB::table('inventory_stocktakes')->insertGetId(['public_id' => (string) Str::uuid(), 'organisation_id' => $actor->organisation_id, 'inventory_location_id' => $f['location']->id, 'stocktake_number' => 'SYN-HIST-'.Str::upper(Str::random(12)), 'status' => $status, 'lock_version' => $status === 'draft' ? 1 : 3, 'created_by_user_id' => $actor->id, 'counted_by_user_id' => $physical !== null ? $actor->id : null, 'posted_by_user_id' => $status === 'posted' ? $actor->id : null, 'counted_at' => $physical !== null ? now()->utc() : null, 'posted_at' => $status === 'posted' ? now()->utc() : null, 'created_at' => now()->utc(), 'updated_at' => now()->utc()]);
            $delta = $physical !== null ? StockUnitQuantity::scaled($physical, true) - StockUnitQuantity::scaled($expected, true) : null;
            DB::table('inventory_stocktake_lines')->insert(['public_id' => (string) Str::uuid(), 'organisation_id' => $actor->organisation_id, 'stocktake_id' => $id, 'inventory_sku_id' => $f['sku']->id, 'inventory_batch_id' => $f['batch']->id, 'expected_quantity' => $expected, 'expected_balance_lock_version' => $balance?->lock_version ?? 1, 'physical_quantity' => $physical, 'variance_quantity' => $delta !== null ? ($delta < 0 ? '-' : '').StockUnitQuantity::format(abs($delta)) : null, 'created_at' => now()->utc(), 'updated_at' => now()->utc()]);

            return InventoryStocktake::query()->findOrFail($id)->load('lines');
        });
    }

    /** @param array<string,mixed> $f */
    public static function movement(User $actor, array $f, string $type, string $quantity, ?string $reference = null): StockMovement
    {
        if (! in_array($type, ['adjustment_in', 'adjustment_out', 'stocktake_gain', 'stocktake_loss'], true)) {
            throw new \InvalidArgumentException('Historical fixture accepts only the four retained legacy types.');
        }

        return DB::transaction(function () use ($actor, $f, $type, $quantity, $reference): StockMovement {
            $out = in_array($type, ['adjustment_out', 'stocktake_loss'], true);
            $value = StockUnitQuantity::base($quantity, $f['sku']->stock_unit);
            $balance = DB::table('inventory_stock_balances')->where('inventory_location_id', $f['location']->id)->where('inventory_sku_id', $f['sku']->id)->where('inventory_batch_id', $f['batch']->id)->first();
            $result = StockUnitQuantity::scaled($balance?->quantity ?? '0.000', true) + ($out ? -1 : 1) * StockUnitQuantity::scaled($value);
            if ($result < 0) {
                throw new \InvalidArgumentException('Historical fixture would create negative stock.');
            }
            $values = ['quantity' => StockUnitQuantity::format($result), 'lock_version' => ($balance?->lock_version ?? 1) + 1, 'updated_at' => now()->utc()];
            if ($balance) {
                DB::table('inventory_stock_balances')->where('id', $balance->id)->update($values);
            } else {
                DB::table('inventory_stock_balances')->insert([...$values, 'organisation_id' => $actor->organisation_id, 'inventory_location_id' => $f['location']->id, 'inventory_sku_id' => $f['sku']->id, 'inventory_batch_id' => $f['batch']->id, 'created_at' => now()->utc()]);
            }
            $movement = new StockMovement;
            $movement->forceFill(['public_id' => (string) Str::uuid(), 'organisation_id' => $actor->organisation_id, 'inventory_sku_id' => $f['sku']->id, 'inventory_batch_id' => $f['batch']->id, 'source_location_id' => $out ? $f['location']->id : null, 'destination_location_id' => $out ? null : $f['location']->id, 'quantity' => $value, 'unit_snapshot' => ['base_unit' => $f['sku']->stock_unit, 'input_unit' => $f['sku']->stock_unit, 'direction' => 'input_to_base', 'factor' => '1.000', 'precision' => 3], 'movement_type' => $type, 'reference_type' => str_starts_with($type, 'stocktake_') ? 'inventory_stocktake' : 'inventory_adjustment', 'reference_public_id' => $reference ?? (string) Str::uuid(), 'actor_user_id' => $actor->id, 'occurred_at' => now()->utc()])->save();

            return $movement;
        });
    }

    /** @param array<string,mixed> $f */
    public static function adjustment(User $actor, array $f, string $direction, string $quantity, string $reason = 'correction'): InventoryAdjustment
    {
        return DB::transaction(function () use ($actor, $f, $direction, $quantity, $reason): InventoryAdjustment {
            $id = DB::table('inventory_adjustments')->insertGetId(['public_id' => $public = (string) Str::uuid(), 'organisation_id' => $actor->organisation_id, 'idempotency_key' => (string) Str::uuid(), 'request_hash' => hash('sha256', 'synthetic-retained-history'), 'inventory_location_id' => $f['location']->id, 'inventory_sku_id' => $f['sku']->id, 'inventory_batch_id' => $f['batch']->id, 'direction' => $direction, 'quantity' => $quantity, 'reason_code' => $reason, 'reason_note' => 'Synthetic retained history', 'posted_by_user_id' => $actor->id, 'posted_at' => now()->utc(), 'created_at' => now()->utc(), 'updated_at' => now()->utc()]);
            self::movement($actor, $f, $direction === 'in' ? 'adjustment_in' : 'adjustment_out', $quantity, $public);

            return InventoryAdjustment::query()->findOrFail($id);
        });
    }
}
