<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PNL-26 (owner decision 2026-11-21, item 16): an optional limit per visit for each Panel, in sen. It is only ever used
 * for warnings (to the doctor and at billing): it never blocks a visit, a bill or a claim. Empty means no limit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('panels', function (Blueprint $table): void {
            $table->unsignedBigInteger('visit_limit_sen')->nullable();
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE panels ADD CONSTRAINT panels_visit_limit_check CHECK (visit_limit_sen IS NULL OR visit_limit_sen BETWEEN 1 AND 999999999)');
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE panels DROP CONSTRAINT IF EXISTS panels_visit_limit_check');
        }

        Schema::table('panels', function (Blueprint $table): void {
            $table->dropColumn('visit_limit_sen');
        });
    }
};
