<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Q-3 (owner decision 2026-11-05): a branch may call by number and short name. Existing rows are unchanged. */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE branch_display_settings DROP CONSTRAINT IF EXISTS branch_display_settings_call_mode_check');
            DB::statement("ALTER TABLE branch_display_settings ADD CONSTRAINT branch_display_settings_call_mode_check CHECK (call_display_mode IN ('number', 'short_name', 'name'))");
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            // Refuse rather than lose a branch's chosen setting.
            if (DB::table('branch_display_settings')->where('call_display_mode', 'short_name')->exists()) {
                throw new RuntimeException('A branch still calls by short name. Change it to number or full name before rolling back.');
            }
            DB::statement('ALTER TABLE branch_display_settings DROP CONSTRAINT IF EXISTS branch_display_settings_call_mode_check');
            DB::statement("ALTER TABLE branch_display_settings ADD CONSTRAINT branch_display_settings_call_mode_check CHECK (call_display_mode IN ('number', 'name'))");
        }
    }
};
