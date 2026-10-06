<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE queue_calls DROP CONSTRAINT IF EXISTS queue_calls_service_check');
            DB::statement("ALTER TABLE queue_calls ADD CONSTRAINT queue_calls_service_check CHECK (service IN ('consultation', 'dispensary', 'treatment'))");
        }
    }

    public function down(): void
    {
        // Additive: narrowing the constraint again would reject call records already written.
    }
};
