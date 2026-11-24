<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * HR-22c (HR-PLAN 29.4 and 29.8; owner, 24 Nov 2026): a roster shift may carry the station a CA, CA Supervisor or Medical
 * Assistant works at: Registration, MA, Dispensary or Treatment. Doctors get a room only, and on-call shifts carry no
 * station. Optional; a branch may switch on "Plan stations" (off by default) to see a warning for shifts with none.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_roster_shifts', function (Blueprint $t): void {
            $t->string('station', 16)->nullable();
        });
        DB::statement("ALTER TABLE hr_roster_shifts ADD CONSTRAINT hr_roster_shifts_station CHECK (
            station IS NULL OR (station IN ('registration', 'ma', 'dispensary', 'treatment') AND session_type <> 'on_call'))");

        Schema::table('hr_minimum_staffing', function (Blueprint $t): void {
            $t->boolean('plan_stations')->default(false);
        });

        // Only a CA, CA Supervisor or Medical Assistant takes a station.
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION guard_hr_roster_shift_station() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF NEW.station IS NOT NULL AND NOT EXISTS (SELECT 1 FROM model_has_roles mr JOIN roles r ON r.id = mr.role_id
     WHERE mr.model_id = NEW.staff_user_id AND mr.model_type = 'App\Models\User' AND r.name IN ('ca', 'ca_supervisor', 'medical_assistant')) THEN
  RAISE EXCEPTION 'Only a CA, CA Supervisor or Medical Assistant takes a station' USING ERRCODE = '23514';
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER hr_roster_shifts_station BEFORE INSERT OR UPDATE OF station, staff_user_id ON hr_roster_shifts FOR EACH ROW EXECUTE FUNCTION guard_hr_roster_shift_station();
SQL);
    }

    public function down(): void
    {
        // Evidence convention (Suresh, m2): refuse once stations or the switch are in use.
        if (DB::table('hr_roster_shifts')->whereNotNull('station')->exists() || DB::table('hr_minimum_staffing')->where('plan_stations', true)->exists()) {
            throw new RuntimeException('Roster stations are in use; this migration is not rolled back.');
        }
        DB::unprepared('DROP TRIGGER IF EXISTS hr_roster_shifts_station ON hr_roster_shifts; DROP FUNCTION IF EXISTS guard_hr_roster_shift_station();');
        DB::statement('ALTER TABLE hr_roster_shifts DROP CONSTRAINT IF EXISTS hr_roster_shifts_station');
        Schema::table('hr_roster_shifts', fn (Blueprint $t) => $t->dropColumn('station'));
        Schema::table('hr_minimum_staffing', fn (Blueprint $t) => $t->dropColumn('plan_stations'));
    }
};
