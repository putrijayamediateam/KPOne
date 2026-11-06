<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * STK-10B (option B of ANALYSIS-STK-10, owner decisions 2026-11-06): receipt cost evidence an accountant can rely on.
 * A delivered batch records the total paid for it, exactly as on the supplier invoice (no per-unit rounding), and
 * every recorded cost says whether it is actual or the order's estimate. Existing rows are not changed: their cost
 * source stays empty, meaning "recorded before STK-10B".
 */
return new class extends Migration
{
    // Every comparison is NULL-safe: a CHECK that evaluates to NULL passes, so a plain "=" would let a total through
    // with no cost source.
    private const SHAPE = "(cost_source IS NULL OR cost_source IN ('actual','estimate'))
        AND (cost_total_sen IS NULL OR (cost_total_sen >= 0 AND cost_source IS NOT DISTINCT FROM 'actual' AND unit_cost_sen IS NULL))
        AND (cost_source IS DISTINCT FROM 'estimate' OR (unit_cost_sen IS NOT NULL AND cost_total_sen IS NULL))
        AND (cost_source IS DISTINCT FROM 'actual' OR (unit_cost_sen IS NULL) <> (cost_total_sen IS NULL))";

    public function up(): void
    {
        foreach (['inventory_receipt_lots', 'stock_movements'] as $table) {
            Schema::table($table, function (Blueprint $t): void {
                $t->unsignedBigInteger('cost_total_sen')->nullable();
                $t->string('cost_source', 10)->nullable();
            });
        }

        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE inventory_receipt_lots ADD CONSTRAINT receipt_lots_cost_shape CHECK ('.self::SHAPE.')');
        DB::statement('ALTER TABLE stock_movements ADD CONSTRAINT stock_movements_cost_shape CHECK ('.self::SHAPE." AND (cost_source IS NULL OR movement_type = 'purchase_receipt'))");
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE inventory_receipt_lots DROP CONSTRAINT IF EXISTS receipt_lots_cost_shape');
            DB::statement('ALTER TABLE stock_movements DROP CONSTRAINT IF EXISTS stock_movements_cost_shape');
        }
        foreach (['inventory_receipt_lots', 'stock_movements'] as $table) {
            Schema::table($table, fn (Blueprint $t) => $t->dropColumn(['cost_total_sen', 'cost_source']));
        }
    }
};
