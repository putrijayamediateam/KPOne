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
            // Refuses (leaves the wider rule) while any branch still uses short_name, so no setting is lost.
            if (DB::table('branch_display_settings')->where('call_display_mode', 'short_name')->exists()) {
                return;
            }
            DB::statement('ALTER TABLE branch_display_settings DROP CONSTRAINT IF EXISTS branch_display_settings_call_mode_check');
            DB::statement("ALTER TABLE branch_display_settings ADD CONSTRAINT branch_display_settings_call_mode_check CHECK (call_display_mode IN ('number', 'name'))");
        }
    }
};
