<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Hardening (TEAM.md section 7, an HR-3a known limit): a withdrawn attendance correction now records who withdrew it and
 * when, in the row itself, not only in the audit log. Additive: two nullable columns, a scoped foreign key and a guard.
 * Corrections withdrawn before this migration keep empty values (a decided or withdrawn correction never changes, so they
 * are not backfilled; the audit log already says who withdrew them).
 */
return new class extends Migration
{
    public function up(): void
    {
        // A rollback keeps the columns (see down()), so up() adds them only when they are not there yet.
        if (! Schema::hasColumn('hr_attendance_corrections', 'withdrawn_by_user_id')) {
            Schema::table('hr_attendance_corrections', function (Blueprint $t): void {
                $t->unsignedBigInteger('withdrawn_by_user_id')->nullable();
                $t->timestampTz('withdrawn_at')->nullable();
                $t->foreign(['withdrawn_by_user_id', 'organisation_id'], 'hr_attendance_corrections_withdrawn_by_fk')
                    ->references(['id', 'organisation_id'])->on('users')->restrictOnDelete();
            });
        }
        DB::statement(<<<'SQL'
ALTER TABLE hr_attendance_corrections ADD CONSTRAINT hr_attendance_corrections_withdrawal_shape CHECK (
 (withdrawn_by_user_id IS NULL) = (withdrawn_at IS NULL)
 AND (withdrawn_by_user_id IS NULL OR (status = 'cancelled' AND withdrawn_by_user_id = proposed_by_user_id))
)
SQL);
        DB::unprepared(<<<'SQL'
-- A correction starts with no withdrawal. Only the step from pending to cancelled sets it, and it must name the person who
-- proposed the correction and the time. Nothing else sets or changes it.
CREATE OR REPLACE FUNCTION guard_hr_attendance_correction_withdrawal() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF TG_OP = 'INSERT' THEN
  IF NEW.withdrawn_by_user_id IS NOT NULL OR NEW.withdrawn_at IS NOT NULL THEN
   RAISE EXCEPTION 'A correction starts with no withdrawal' USING ERRCODE = '23514';
  END IF;
  RETURN NEW;
 END IF;
 IF OLD.status = 'pending' AND NEW.status = 'cancelled' THEN
  IF NEW.withdrawn_by_user_id IS DISTINCT FROM OLD.proposed_by_user_id OR NEW.withdrawn_at IS NULL THEN
   RAISE EXCEPTION 'A withdrawn correction records who proposed and withdrew it, and when' USING ERRCODE = '23514';
  END IF;
 ELSIF NEW.withdrawn_by_user_id IS DISTINCT FROM OLD.withdrawn_by_user_id OR NEW.withdrawn_at IS DISTINCT FROM OLD.withdrawn_at THEN
  RAISE EXCEPTION 'Only a withdrawal records a withdrawal' USING ERRCODE = '23514';
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER hr_attendance_corrections_withdrawal BEFORE INSERT OR UPDATE ON hr_attendance_corrections
 FOR EACH ROW EXECUTE FUNCTION guard_hr_attendance_correction_withdrawal();
SQL);
    }

    /**
     * Keeps the two columns and their foreign key, so a rollback never deletes who withdrew a correction (HR migrations keep
     * evidence on the way down). Only the guard and the CHECK go; up() then adds them again over the kept columns.
     */
    public function down(): void
    {
        DB::unprepared(<<<'SQL'
DROP TRIGGER IF EXISTS hr_attendance_corrections_withdrawal ON hr_attendance_corrections;
DROP FUNCTION IF EXISTS guard_hr_attendance_correction_withdrawal();
ALTER TABLE hr_attendance_corrections DROP CONSTRAINT IF EXISTS hr_attendance_corrections_withdrawal_shape;
SQL);
    }
};
