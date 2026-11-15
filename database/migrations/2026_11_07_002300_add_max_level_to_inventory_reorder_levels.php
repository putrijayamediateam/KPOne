<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * STK-30: an optional maximum per SKU and location (approved by B.E.R.N.I.S.S for the owner). Reorder suggestions
 * order up to it; nothing is ordered automatically. Additive and nullable: existing levels keep no maximum.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_reorder_levels', function (Blueprint $table): void {
            $table->decimal('max_level', 15, 3)->nullable()->after('reorder_level');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE inventory_reorder_levels ADD CONSTRAINT reorder_levels_max_check CHECK (max_level IS NULL OR max_level >= reorder_level)');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE inventory_reorder_levels DROP CONSTRAINT IF EXISTS reorder_levels_max_check');
        }

        Schema::table('inventory_reorder_levels', function (Blueprint $table): void {
            $table->dropColumn('max_level');
        });
    }
};
