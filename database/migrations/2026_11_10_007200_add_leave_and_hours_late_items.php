<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * G2b (owner, 16 Nov 2026): the G2a pattern for leave and worked hours changed after a month's regular run was locked.
 * - leave_cancelled: unpaid leave a locked run took off pay, cancelled after the lock (added back on the adjustment);
 * - leave_approved: unpaid leave approved after the lock, which no locked run took off (a deduction on the adjustment);
 * - hours: an hourly person whose worked hours for the month changed after the lock (one line per person).
 * Nothing is paid or taken unless HR presses "Add to this adjustment". Worked minutes are kept on each hourly person of
 * a run, so a later run can tell what was already paid for.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_payroll_run_people', function (Blueprint $t): void {
            $t->unsignedInteger('worked_minutes')->nullable(); // hourly people: the month's worked minutes this run pays for
        });
        Schema::table('hr_payroll_late_items', function (Blueprint $t): void {
            $t->string('kind', 20)->default('overtime');
            $t->unsignedBigInteger('leave_request_id')->nullable();
            $t->foreign('leave_request_id', 'hr_payroll_late_items_leave_fk')->references('id')->on('hr_leave_requests')->restrictOnDelete();
            $t->unique(['run_id', 'leave_request_id'], 'hr_payroll_late_items_leave_once');
        });
        DB::statement('ALTER TABLE hr_payroll_late_items ALTER COLUMN overtime_request_id DROP NOT NULL');
        DB::statement("ALTER TABLE hr_payroll_late_items ADD CONSTRAINT hr_payroll_late_items_kind CHECK (
 kind IN ('overtime', 'leave_cancelled', 'leave_approved', 'hours')
 AND (kind = 'overtime') = (overtime_request_id IS NOT NULL)
 AND (kind IN ('leave_cancelled', 'leave_approved')) = (leave_request_id IS NOT NULL))");
        DB::statement("CREATE UNIQUE INDEX hr_payroll_late_items_hours_once ON hr_payroll_late_items (run_id, worker_id) WHERE kind = 'hours'");

        DB::unprepared(<<<'SQL'
-- A late item belongs to a draft adjustment run of the organisation, for a person of the run's month, and is removed
-- only while the run is a draft. Per kind:
--  overtime: approved overtime of that person in the month that no locked run paid (G2a);
--  leave_cancelled: cancelled unpaid leave starting in the month that a locked run took off pay and none gave back;
--  leave_approved: approved unpaid leave starting in the month that no locked run has a line for;
--  hours: a person of the locked regular run this adjustment corrects.
CREATE OR REPLACE FUNCTION guard_hr_payroll_late_item() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE r hr_payroll_runs%ROWTYPE;
BEGIN
 IF TG_OP = 'DELETE' THEN
  SELECT * INTO r FROM hr_payroll_runs WHERE id = OLD.run_id;
  IF r.status <> 'draft' THEN
   RAISE EXCEPTION 'A late item leaves only a draft run' USING ERRCODE = 'P0001';
  END IF;
  RETURN OLD;
 END IF;
 IF TG_OP = 'UPDATE' THEN
  RAISE EXCEPTION 'Late items are never changed' USING ERRCODE = 'P0001';
 END IF;
 SELECT * INTO r FROM hr_payroll_runs WHERE id = NEW.run_id;
 IF r.kind <> 'adjustment' OR r.status <> 'draft' OR r.organisation_id <> NEW.organisation_id THEN
  RAISE EXCEPTION 'A late item goes only on a draft adjustment run' USING ERRCODE = '23514';
 END IF;
 IF NEW.kind = 'overtime' THEN
  IF NOT EXISTS (SELECT 1 FROM hr_overtime_requests o JOIN hr_workers w ON w.user_id = o.user_id
    WHERE o.id = NEW.overtime_request_id AND w.id = NEW.worker_id AND o.organisation_id = NEW.organisation_id AND o.status = 'approved'
      AND date_trunc('month', o.local_date) = r.month) THEN
   RAISE EXCEPTION 'A late item is approved overtime of that person in the run month' USING ERRCODE = '23514';
  END IF;
  IF EXISTS (SELECT 1 FROM hr_payroll_lines l JOIN hr_payroll_runs x ON x.id = l.run_id
    WHERE x.status = 'locked' AND l.source_kind = 'overtime' AND l.source_id = NEW.overtime_request_id) THEN
   RAISE EXCEPTION 'This overtime is already paid by a locked run' USING ERRCODE = '23514';
  END IF;
 ELSIF NEW.kind IN ('leave_cancelled', 'leave_approved') THEN
  IF NOT EXISTS (SELECT 1 FROM hr_leave_requests q JOIN hr_leave_types t ON t.id = q.leave_type_id JOIN hr_workers w ON w.user_id = q.user_id
    WHERE q.id = NEW.leave_request_id AND w.id = NEW.worker_id AND q.organisation_id = NEW.organisation_id AND NOT t.is_paid
      AND q.status = CASE NEW.kind WHEN 'leave_cancelled' THEN 'cancelled' ELSE 'approved' END
      AND date_trunc('month', q.start_date) = r.month) THEN
   RAISE EXCEPTION 'A late leave item is unpaid leave of that person in the run month' USING ERRCODE = '23514';
  END IF;
  IF NEW.kind = 'leave_cancelled' AND (NOT EXISTS (SELECT 1 FROM hr_payroll_lines l JOIN hr_payroll_runs x ON x.id = l.run_id
      WHERE x.status = 'locked' AND l.source_kind = 'leave' AND l.source_id = NEW.leave_request_id AND l.section = 'deduction')
    OR EXISTS (SELECT 1 FROM hr_payroll_lines l JOIN hr_payroll_runs x ON x.id = l.run_id
      WHERE x.status = 'locked' AND l.source_kind = 'leave' AND l.source_id = NEW.leave_request_id AND l.section = 'earnings')) THEN
   RAISE EXCEPTION 'Cancelled leave goes back only when a locked run took it and none gave it back' USING ERRCODE = '23514';
  END IF;
  IF NEW.kind = 'leave_approved' AND EXISTS (SELECT 1 FROM hr_payroll_lines l JOIN hr_payroll_runs x ON x.id = l.run_id
      WHERE x.status = 'locked' AND l.source_kind = 'leave' AND l.source_id = NEW.leave_request_id) THEN
   RAISE EXCEPTION 'This leave is already on a locked run' USING ERRCODE = '23514';
  END IF;
 ELSE
  IF NOT EXISTS (SELECT 1 FROM hr_payroll_run_people p WHERE p.run_id = r.adjusts_run_id AND p.worker_id = NEW.worker_id) THEN
   RAISE EXCEPTION 'An hours item is for a person of the locked run it corrects' USING ERRCODE = '23514';
  END IF;
 END IF;
 RETURN NEW;
END $$;
SQL);
    }

    public function down(): void
    {
        if (DB::table('hr_payroll_late_items')->where('kind', '<>', 'overtime')->exists()) {
            throw new RuntimeException('Leave or hours late items exist; this migration cannot be rolled back without losing payroll evidence.');
        }
        DB::statement('DROP INDEX IF EXISTS hr_payroll_late_items_hours_once');
        DB::statement('ALTER TABLE hr_payroll_late_items DROP CONSTRAINT IF EXISTS hr_payroll_late_items_kind');
        DB::statement('ALTER TABLE hr_payroll_late_items ALTER COLUMN overtime_request_id SET NOT NULL');
        Schema::table('hr_payroll_late_items', function (Blueprint $t): void {
            $t->dropUnique('hr_payroll_late_items_leave_once');
            $t->dropForeign('hr_payroll_late_items_leave_fk');
            $t->dropColumn(['kind', 'leave_request_id']);
        });
        Schema::table('hr_payroll_run_people', function (Blueprint $t): void {
            $t->dropColumn('worked_minutes');
        });
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION guard_hr_payroll_late_item() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE r hr_payroll_runs%ROWTYPE;
BEGIN
 IF TG_OP = 'DELETE' THEN
  SELECT * INTO r FROM hr_payroll_runs WHERE id = OLD.run_id;
  IF r.status <> 'draft' THEN
   RAISE EXCEPTION 'A late item leaves only a draft run' USING ERRCODE = 'P0001';
  END IF;
  RETURN OLD;
 END IF;
 IF TG_OP = 'UPDATE' THEN
  RAISE EXCEPTION 'Late items are never changed' USING ERRCODE = 'P0001';
 END IF;
 SELECT * INTO r FROM hr_payroll_runs WHERE id = NEW.run_id;
 IF r.kind <> 'adjustment' OR r.status <> 'draft' OR r.organisation_id <> NEW.organisation_id THEN
  RAISE EXCEPTION 'A late item goes only on a draft adjustment run' USING ERRCODE = '23514';
 END IF;
 IF NOT EXISTS (SELECT 1 FROM hr_overtime_requests o JOIN hr_workers w ON w.user_id = o.user_id
   WHERE o.id = NEW.overtime_request_id AND w.id = NEW.worker_id AND o.organisation_id = NEW.organisation_id AND o.status = 'approved'
     AND date_trunc('month', o.local_date) = r.month) THEN
  RAISE EXCEPTION 'A late item is approved overtime of that person in the run month' USING ERRCODE = '23514';
 END IF;
 IF EXISTS (SELECT 1 FROM hr_payroll_lines l JOIN hr_payroll_runs x ON x.id = l.run_id
   WHERE x.status = 'locked' AND l.source_kind = 'overtime' AND l.source_id = NEW.overtime_request_id) THEN
  RAISE EXCEPTION 'This overtime is already paid by a locked run' USING ERRCODE = '23514';
 END IF;
 RETURN NEW;
END $$;
SQL);
    }
};
