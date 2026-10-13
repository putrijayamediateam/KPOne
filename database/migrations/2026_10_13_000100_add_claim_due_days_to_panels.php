<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('panels', function (Blueprint $table): void {
            $table->unsignedSmallInteger('claim_due_days')->default(30);
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE panels ADD CONSTRAINT panels_claim_due_days_check CHECK (claim_due_days BETWEEN 0 AND 365)');
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE panels DROP CONSTRAINT IF EXISTS panels_claim_due_days_check');
        }

        Schema::table('panels', function (Blueprint $table): void {
            $table->dropColumn('claim_due_days');
        });
    }
};
