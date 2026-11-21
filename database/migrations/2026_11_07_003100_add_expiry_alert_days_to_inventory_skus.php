<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * STK-42 (owner decision 25, 20-21 Nov 2026): each SKU may set how many days before expiry Stock review lists a batch as
 * expiring. NULL means the default of 90 days, so every existing SKU behaves exactly as before. Allowed: 7 to 365.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_skus', function (Blueprint $t): void {
            $t->smallInteger('expiry_alert_days')->nullable();
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE inventory_skus ADD CONSTRAINT inventory_skus_expiry_alert_days_range CHECK (expiry_alert_days IS NULL OR expiry_alert_days BETWEEN 7 AND 365)');
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE inventory_skus DROP CONSTRAINT IF EXISTS inventory_skus_expiry_alert_days_range');
        }
        Schema::table('inventory_skus', function (Blueprint $t): void {
            $t->dropColumn('expiry_alert_days');
        });
    }
};
