<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('payment_methods', 'classification')) {
            Schema::table('payment_methods', function (Blueprint $table): void {
                $table->string('classification', 8)->nullable();
                $table->unsignedInteger('lock_version')->default(0);
            });
            DB::table('payment_methods')->update(['classification' => DB::raw("CASE WHEN UPPER(code) = 'CASH' THEN 'cash' ELSE 'non_cash' END")]);
            if (DB::getDriverName() === 'pgsql') {
                DB::statement("ALTER TABLE payment_methods ADD CONSTRAINT payment_methods_classification_check CHECK (classification IS NULL OR classification IN ('cash', 'non_cash'))");
            }
        }
    }

    public function down(): void {}
};
