<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PNL-4 (owner decision 2026-11-05): each Panel has its own follow-up days (default 30). A Panel claim
 * appears on the Follow-up list once that many days pass with no new action on it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('panels', function (Blueprint $table): void {
            $table->unsignedSmallInteger('claim_follow_up_days')->default(30);
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE panels ADD CONSTRAINT panels_claim_follow_up_days_check CHECK (claim_follow_up_days BETWEEN 1 AND 365)');
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE panels DROP CONSTRAINT IF EXISTS panels_claim_follow_up_days_check');
        }

        Schema::table('panels', function (Blueprint $table): void {
            $table->dropColumn('claim_follow_up_days');
        });
    }
};
