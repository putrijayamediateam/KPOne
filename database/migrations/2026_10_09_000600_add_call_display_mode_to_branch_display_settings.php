<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('branch_display_settings', function (Blueprint $table) {
            $table->string('call_display_mode', 16)->default('number');
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE branch_display_settings ADD CONSTRAINT branch_display_settings_call_mode_check CHECK (call_display_mode IN ('number', 'name'))");
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE branch_display_settings DROP CONSTRAINT IF EXISTS branch_display_settings_call_mode_check');
        }

        Schema::table('branch_display_settings', function (Blueprint $table) {
            $table->dropColumn('call_display_mode');
        });
    }
};
