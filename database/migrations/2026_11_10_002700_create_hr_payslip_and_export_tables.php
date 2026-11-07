<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * HR-7c payslips and exports (HR-PLAN section 17; B.E.R.N.I.S.S for the owner, 2026-11-07). A payslip is an encrypted
 * snapshot written when its run is locked and never changed; opening one and each export download are append-only
 * evidence rows. No file is stored.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_payslips', function (Blueprint $t): void {
            $t->id();
            $t->uuid('public_id')->unique();
            $t->unsignedBigInteger('organisation_id');
            $t->unsignedBigInteger('run_id');
            $t->unsignedBigInteger('worker_id');
            $t->unsignedBigInteger('user_id');
            $t->text('snapshot_encrypted');
            $t->char('snapshot_hash', 64);
            $t->timestampTz('created_at');
            $t->foreign(['run_id', 'organisation_id'], 'hr_payslips_run_fk')->references(['id', 'organisation_id'])->on('hr_payroll_runs')->restrictOnDelete();
            $t->foreign(['worker_id', 'organisation_id'], 'hr_payslips_worker_fk')->references(['id', 'organisation_id'])->on('hr_workers')->restrictOnDelete();
            $t->foreign(['user_id', 'organisation_id'], 'hr_payslips_user_fk')->references(['id', 'organisation_id'])->on('users')->restrictOnDelete();
            $t->unique(['run_id', 'worker_id'], 'hr_payslips_once');
            $t->unique(['id', 'organisation_id'], 'hr_payslips_scope_unique');
            $t->index(['user_id', 'id'], 'hr_payslips_user');
        });

        Schema::create('hr_payslip_opens', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('organisation_id');
            $t->unsignedBigInteger('payslip_id');
            $t->unsignedBigInteger('opened_by_user_id');
            $t->timestampTz('created_at');
            $t->foreign(['payslip_id', 'organisation_id'], 'hr_payslip_opens_payslip_fk')->references(['id', 'organisation_id'])->on('hr_payslips')->restrictOnDelete();
            $t->foreign(['opened_by_user_id', 'organisation_id'], 'hr_payslip_opens_user_fk')->references(['id', 'organisation_id'])->on('users')->restrictOnDelete();
            $t->index(['payslip_id', 'id'], 'hr_payslip_opens_payslip');
        });

        Schema::create('hr_payroll_exports', function (Blueprint $t): void {
            $t->id();
            $t->uuid('public_id')->unique();
            $t->unsignedBigInteger('organisation_id');
            $t->unsignedBigInteger('run_id');
            $t->string('kind', 12); // bank | accountant
            $t->string('layout', 16); // generic_csv | summary_csv
            $t->unsignedInteger('row_count');
            $t->unsignedBigInteger('exported_by_user_id');
            $t->timestampTz('created_at');
            $t->foreign(['run_id', 'organisation_id'], 'hr_payroll_exports_run_fk')->references(['id', 'organisation_id'])->on('hr_payroll_runs')->restrictOnDelete();
            $t->foreign(['exported_by_user_id', 'organisation_id'], 'hr_payroll_exports_user_fk')->references(['id', 'organisation_id'])->on('users')->restrictOnDelete();
            $t->index(['run_id', 'id'], 'hr_payroll_exports_run');
        });

        DB::statement("ALTER TABLE hr_payroll_exports ADD CONSTRAINT hr_payroll_exports_shape CHECK ((kind = 'bank' AND layout = 'generic_csv') OR (kind = 'accountant' AND layout = 'summary_csv'))");
        DB::statement("ALTER TABLE hr_payslips ADD CONSTRAINT hr_payslips_shape CHECK (snapshot_hash ~ '^[0-9a-f]{64}$')");

        DB::unprepared(<<<'SQL'
CREATE TRIGGER hr_payslips_append BEFORE UPDATE OR DELETE ON hr_payslips FOR EACH ROW EXECUTE FUNCTION guard_hr_records_append_only();
CREATE TRIGGER hr_payslip_opens_append BEFORE UPDATE OR DELETE ON hr_payslip_opens FOR EACH ROW EXECUTE FUNCTION guard_hr_records_append_only();
CREATE TRIGGER hr_payroll_exports_append BEFORE UPDATE OR DELETE ON hr_payroll_exports FOR EACH ROW EXECUTE FUNCTION guard_hr_records_append_only();
CREATE TRIGGER hr_payslips_truncate BEFORE TRUNCATE ON hr_payslips FOR EACH STATEMENT EXECUTE FUNCTION guard_hr_records_append_only();
CREATE TRIGGER hr_payslip_opens_truncate BEFORE TRUNCATE ON hr_payslip_opens FOR EACH STATEMENT EXECUTE FUNCTION guard_hr_records_append_only();
CREATE TRIGGER hr_payroll_exports_truncate BEFORE TRUNCATE ON hr_payroll_exports FOR EACH STATEMENT EXECUTE FUNCTION guard_hr_records_append_only();

-- A payslip belongs to a locked run and to a person in that run, and to that person's own account.
CREATE OR REPLACE FUNCTION guard_hr_payslip_insert() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF NOT EXISTS (SELECT 1 FROM hr_payroll_runs WHERE id = NEW.run_id AND organisation_id = NEW.organisation_id AND status = 'locked') THEN
  RAISE EXCEPTION 'A payslip comes only from a locked payroll run' USING ERRCODE = '23514';
 END IF;
 IF NOT EXISTS (SELECT 1 FROM hr_payroll_run_people WHERE run_id = NEW.run_id AND worker_id = NEW.worker_id) THEN
  RAISE EXCEPTION 'A payslip is only for a person in its run' USING ERRCODE = '23514';
 END IF;
 IF NOT EXISTS (SELECT 1 FROM hr_workers WHERE id = NEW.worker_id AND user_id = NEW.user_id) THEN
  RAISE EXCEPTION 'A payslip belongs to the worker''s own account' USING ERRCODE = '23514';
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER hr_payslips_guard BEFORE INSERT ON hr_payslips FOR EACH ROW EXECUTE FUNCTION guard_hr_payslip_insert();

-- An export comes only from a locked run.
CREATE OR REPLACE FUNCTION guard_hr_payroll_export_insert() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF NOT EXISTS (SELECT 1 FROM hr_payroll_runs WHERE id = NEW.run_id AND organisation_id = NEW.organisation_id AND status = 'locked') THEN
  RAISE EXCEPTION 'An export comes only from a locked payroll run' USING ERRCODE = '23514';
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER hr_payroll_exports_guard BEFORE INSERT ON hr_payroll_exports FOR EACH ROW EXECUTE FUNCTION guard_hr_payroll_export_insert();
SQL);
    }

    public function down(): void
    {
        // HR payroll evidence is retained, following the evidence convention.
    }
};
