<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * G2a (owner, 16 Nov 2026): overtime approved after its month's regular run was locked is flagged, never paid
 * automatically. HR adds each one to a draft adjustment run of that month with "Add to this adjustment"; the line is
 * worked out at run time like any overtime line, so the run fingerprint (and the G1 check) covers it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_payroll_late_items', function (Blueprint $t): void {
            $t->id();
            $t->uuid('public_id')->unique();
            $t->unsignedBigInteger('organisation_id');
            $t->unsignedBigInteger('run_id');
            $t->unsignedBigInteger('worker_id');
            $t->unsignedBigInteger('overtime_request_id');
            $t->unsignedBigInteger('added_by_user_id');
            $t->timestampTz('created_at');
            $t->foreign(['run_id', 'organisation_id'], 'hr_payroll_late_items_run_fk')->references(['id', 'organisation_id'])->on('hr_payroll_runs')->restrictOnDelete();
            $t->foreign(['worker_id', 'organisation_id'], 'hr_payroll_late_items_worker_fk')->references(['id', 'organisation_id'])->on('hr_workers')->restrictOnDelete();
            $t->foreign('overtime_request_id', 'hr_payroll_late_items_overtime_fk')->references('id')->on('hr_overtime_requests')->restrictOnDelete();
            $t->foreign(['added_by_user_id', 'organisation_id'], 'hr_payroll_late_items_actor_fk')->references(['id', 'organisation_id'])->on('users')->restrictOnDelete();
            $t->unique(['run_id', 'overtime_request_id'], 'hr_payroll_late_items_once');
        });

        DB::unprepared(<<<'SQL'
-- A late item belongs to a draft adjustment run, is approved overtime of the worker's own account in the run's month,
-- and is in the same organisation. It is removed only while the run is a draft.
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
CREATE TRIGGER hr_payroll_late_items_guard BEFORE INSERT OR UPDATE OR DELETE ON hr_payroll_late_items FOR EACH ROW EXECUTE FUNCTION guard_hr_payroll_late_item();
CREATE TRIGGER hr_payroll_late_items_truncate BEFORE TRUNCATE ON hr_payroll_late_items FOR EACH STATEMENT EXECUTE FUNCTION guard_hr_records_append_only();
SQL);
    }

    public function down(): void
    {
        if (Schema::hasTable('hr_payroll_late_items') && DB::table('hr_payroll_late_items')->exists()) {
            throw new RuntimeException('Late items exist; this migration cannot be rolled back without losing payroll evidence.');
        }
        Schema::dropIfExists('hr_payroll_late_items');
        DB::unprepared('DROP FUNCTION IF EXISTS guard_hr_payroll_late_item()');
    }
};
