<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * HR-4a staff files (owner, 2026-11-06: "approve the HR-4 note with the defaults"). A worker record per account (or a
 * locum with no account), employment periods, position history, encrypted personal details, bank details confirmed by
 * a second person, and correction requests. HR never writes accounts, roles or branch assignments. Synthetic data only
 * until the PDPA review. Sensitive values are encrypted by the application; this schema stores ciphertext only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_workers', function (Blueprint $t): void {
            $t->id();
            $t->uuid('public_id')->unique();
            $t->unsignedBigInteger('organisation_id');
            $t->unsignedBigInteger('user_id')->nullable(); // null for a locum, who has no KPOne sign-in
            $t->string('kind', 8); // staff | locum
            $t->string('display_name', 120)->nullable(); // locums only; staff names come from their account
            $t->text('locum_phone_encrypted')->nullable();
            $t->unsignedInteger('lock_version')->default(1);
            $t->unsignedBigInteger('created_by_user_id');
            $t->timestampsTz();
            $t->foreign('organisation_id', 'hr_workers_org_fk')->references('id')->on('organisations')->restrictOnDelete();
            $t->foreign(['user_id', 'organisation_id'], 'hr_workers_user_fk')->references(['id', 'organisation_id'])->on('users')->restrictOnDelete();
            $t->foreign(['created_by_user_id', 'organisation_id'], 'hr_workers_creator_fk')->references(['id', 'organisation_id'])->on('users')->restrictOnDelete();
            $t->unique(['id', 'organisation_id'], 'hr_workers_scope_unique');
        });

        Schema::create('hr_employment_periods', function (Blueprint $t): void {
            $t->id();
            $t->uuid('public_id')->unique();
            $t->unsignedBigInteger('organisation_id');
            $t->unsignedBigInteger('worker_id');
            $t->string('type', 12); // permanent | contract | part_time | locum | intern
            $t->date('starts_on');
            $t->date('probation_ends_on')->nullable();
            $t->date('confirmed_on')->nullable();
            $t->date('ends_on')->nullable();
            $t->string('end_reason', 16)->nullable();
            $t->text('note_encrypted')->nullable();
            $t->unsignedInteger('lock_version')->default(1);
            $t->unsignedBigInteger('created_by_user_id');
            $t->timestampsTz();
            $t->foreign(['worker_id', 'organisation_id'], 'hr_employment_periods_worker_fk')->references(['id', 'organisation_id'])->on('hr_workers')->restrictOnDelete();
            $t->foreign(['created_by_user_id', 'organisation_id'], 'hr_employment_periods_creator_fk')->references(['id', 'organisation_id'])->on('users')->restrictOnDelete();
            $t->index(['worker_id', 'starts_on'], 'hr_employment_periods_worker_start');
        });

        Schema::create('hr_positions', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('organisation_id');
            $t->unsignedBigInteger('worker_id');
            $t->string('title', 120);
            $t->unsignedBigInteger('department_id')->nullable();
            $t->unsignedBigInteger('reports_to_worker_id')->nullable();
            $t->date('from_date');
            $t->unsignedBigInteger('created_by_user_id');
            $t->timestampTz('created_at');
            $t->foreign(['worker_id', 'organisation_id'], 'hr_positions_worker_fk')->references(['id', 'organisation_id'])->on('hr_workers')->restrictOnDelete();
            $t->foreign(['reports_to_worker_id', 'organisation_id'], 'hr_positions_reports_fk')->references(['id', 'organisation_id'])->on('hr_workers')->restrictOnDelete();
            $t->foreign('department_id', 'hr_positions_department_fk')->references('id')->on('departments')->restrictOnDelete();
            $t->foreign(['created_by_user_id', 'organisation_id'], 'hr_positions_creator_fk')->references(['id', 'organisation_id'])->on('users')->restrictOnDelete();
            $t->unique(['worker_id', 'from_date'], 'hr_positions_worker_date_unique');
        });

        Schema::create('hr_personal_details', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('organisation_id');
            $t->unsignedBigInteger('worker_id')->unique();
            $t->text('legal_name_encrypted')->nullable();
            $t->string('preferred_name', 80)->nullable();
            $t->string('identity_kind', 8)->nullable(); // ic | passport
            $t->text('identity_encrypted')->nullable();
            $t->char('identity_hash', 64)->nullable(); // keyed hash, for the duplicate check only
            $t->string('identity_last4', 4)->nullable();
            $t->char('passport_country', 2)->nullable();
            $t->text('date_of_birth_encrypted')->nullable();
            $t->string('nationality', 60)->nullable();
            $t->text('address_encrypted')->nullable();
            $t->text('personal_phone_encrypted')->nullable();
            $t->text('emergency_contact_encrypted')->nullable();
            $t->unsignedInteger('lock_version')->default(1);
            $t->unsignedBigInteger('updated_by_user_id');
            $t->timestampsTz();
            $t->foreign(['worker_id', 'organisation_id'], 'hr_personal_details_worker_fk')->references(['id', 'organisation_id'])->on('hr_workers')->restrictOnDelete();
            $t->foreign(['updated_by_user_id', 'organisation_id'], 'hr_personal_details_updater_fk')->references(['id', 'organisation_id'])->on('users')->restrictOnDelete();
            $t->unique(['organisation_id', 'identity_hash'], 'hr_personal_details_identity_unique');
        });

        Schema::create('hr_bank_details', function (Blueprint $t): void {
            $t->id();
            $t->uuid('public_id')->unique();
            $t->unsignedBigInteger('organisation_id');
            $t->unsignedBigInteger('worker_id');
            $t->text('bank_name_encrypted');
            $t->text('holder_encrypted');
            $t->text('account_encrypted');
            $t->string('account_last4', 4);
            $t->string('status', 12)->default('waiting'); // waiting | confirmed | rejected | withdrawn | replaced
            $t->unsignedBigInteger('entered_by_user_id');
            $t->unsignedBigInteger('decided_by_user_id')->nullable();
            $t->timestampTz('decided_at')->nullable();
            $t->string('decision_reason', 16)->nullable();
            $t->unsignedInteger('lock_version')->default(1);
            $t->timestampsTz();
            $t->foreign(['worker_id', 'organisation_id'], 'hr_bank_details_worker_fk')->references(['id', 'organisation_id'])->on('hr_workers')->restrictOnDelete();
            foreach (['entered_by_user_id', 'decided_by_user_id'] as $column) {
                $t->foreign([$column, 'organisation_id'], "hr_bank_details_{$column}_fk")->references(['id', 'organisation_id'])->on('users')->restrictOnDelete();
            }
        });

        Schema::create('hr_record_corrections', function (Blueprint $t): void {
            $t->id();
            $t->uuid('public_id')->unique();
            $t->unsignedBigInteger('organisation_id');
            $t->unsignedBigInteger('worker_id');
            $t->string('kind', 16); // legal_name | identity | date_of_birth | nationality | bank
            $t->text('new_value_encrypted');
            $t->string('reason_code', 12);
            $t->text('note_encrypted')->nullable();
            $t->string('status', 12)->default('pending'); // pending | applied | rejected | withdrawn
            $t->unsignedBigInteger('asked_by_user_id');
            $t->unsignedBigInteger('decided_by_user_id')->nullable();
            $t->timestampTz('decided_at')->nullable();
            $t->string('decision_reason', 16)->nullable();
            $t->unsignedInteger('lock_version')->default(1);
            $t->timestampsTz();
            $t->foreign(['worker_id', 'organisation_id'], 'hr_record_corrections_worker_fk')->references(['id', 'organisation_id'])->on('hr_workers')->restrictOnDelete();
            foreach (['asked_by_user_id', 'decided_by_user_id'] as $column) {
                $t->foreign([$column, 'organisation_id'], "hr_record_corrections_{$column}_fk")->references(['id', 'organisation_id'])->on('users')->restrictOnDelete();
            }
            $t->index(['organisation_id', 'status'], 'hr_record_corrections_status');
        });

        Schema::create('hr_record_changes', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('organisation_id');
            $t->unsignedBigInteger('worker_id');
            $t->string('area', 16); // worker | employment | position | personal | bank | correction
            $t->jsonb('fields'); // field names only, never values
            $t->string('reason_code', 16)->nullable();
            $t->unsignedBigInteger('changed_by_user_id');
            $t->timestampTz('created_at');
            $t->foreign(['worker_id', 'organisation_id'], 'hr_record_changes_worker_fk')->references(['id', 'organisation_id'])->on('hr_workers')->restrictOnDelete();
            $t->foreign(['changed_by_user_id', 'organisation_id'], 'hr_record_changes_actor_fk')->references(['id', 'organisation_id'])->on('users')->restrictOnDelete();
            $t->index(['worker_id', 'id'], 'hr_record_changes_worker');
        });

        Schema::create('hr_record_retries', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('organisation_id');
            $t->uuid('idempotency_key');
            $t->unsignedBigInteger('actor_user_id');
            $t->string('action', 24);
            $t->string('payload_hash', 64);
            $t->string('result', 64);
            $t->timestampTz('created_at');
            $t->foreign(['actor_user_id', 'organisation_id'], 'hr_record_retries_actor_fk')->references(['id', 'organisation_id'])->on('users')->restrictOnDelete();
            $t->unique(['organisation_id', 'idempotency_key'], 'hr_record_retries_key_unique');
        });

        DB::statement(<<<'SQL'
ALTER TABLE hr_workers ADD CONSTRAINT hr_workers_shape CHECK (
 kind IN ('staff', 'locum')
 AND (kind = 'staff') = (user_id IS NOT NULL)
 AND (kind = 'locum') = (display_name IS NOT NULL)
)
SQL);
        DB::statement('CREATE UNIQUE INDEX hr_workers_one_per_user ON hr_workers (user_id) WHERE user_id IS NOT NULL');
        DB::statement(<<<'SQL'
ALTER TABLE hr_employment_periods ADD CONSTRAINT hr_employment_periods_shape CHECK (
 type IN ('permanent', 'contract', 'part_time', 'locum', 'intern')
 AND (ends_on IS NULL OR ends_on >= starts_on)
 AND (probation_ends_on IS NULL OR probation_ends_on >= starts_on)
 AND (confirmed_on IS NULL OR confirmed_on >= starts_on)
 AND (ends_on IS NULL) = (end_reason IS NULL)
 AND (end_reason IS NULL OR end_reason IN ('resigned', 'contract_ended', 'dismissed', 'retired', 'other'))
)
SQL);
        DB::statement(<<<'SQL'
ALTER TABLE hr_personal_details ADD CONSTRAINT hr_personal_details_shape CHECK (
 (identity_kind IS NULL OR identity_kind IN ('ic', 'passport'))
 AND (identity_kind IS NULL) = (identity_encrypted IS NULL)
 AND (identity_kind IS NULL) = (identity_hash IS NULL)
 AND (identity_kind IS NULL) = (identity_last4 IS NULL)
 AND (identity_kind = 'passport') = (passport_country IS NOT NULL)
)
SQL);
        DB::statement(<<<'SQL'
ALTER TABLE hr_bank_details ADD CONSTRAINT hr_bank_details_shape CHECK (
 status IN ('waiting', 'confirmed', 'rejected', 'withdrawn', 'replaced')
 AND (status IN ('confirmed', 'rejected', 'replaced')) = (decided_by_user_id IS NOT NULL)
 AND (decided_by_user_id IS NULL) = (decided_at IS NULL)
 AND (decided_by_user_id IS NULL OR decided_by_user_id <> entered_by_user_id)
 AND (status = 'rejected') = (decision_reason IS NOT NULL)
 AND (decision_reason IS NULL OR decision_reason IN ('wrong_account', 'wrong_person', 'not_confirmed', 'other'))
 AND account_last4 ~ '^[0-9A-Za-z]{1,4}$'
)
SQL);
        DB::statement("CREATE UNIQUE INDEX hr_bank_details_one_waiting ON hr_bank_details (worker_id) WHERE status = 'waiting'");
        DB::statement("CREATE UNIQUE INDEX hr_bank_details_one_confirmed ON hr_bank_details (worker_id) WHERE status = 'confirmed'");
        DB::statement(<<<'SQL'
ALTER TABLE hr_record_corrections ADD CONSTRAINT hr_record_corrections_shape CHECK (
 kind IN ('legal_name', 'identity', 'date_of_birth', 'nationality', 'bank')
 AND reason_code IN ('wrong', 'changed', 'other')
 AND status IN ('pending', 'applied', 'rejected', 'withdrawn')
 AND (status IN ('applied', 'rejected')) = (decided_by_user_id IS NOT NULL)
 AND (decided_by_user_id IS NULL) = (decided_at IS NULL)
 AND (decided_by_user_id IS NULL OR decided_by_user_id <> asked_by_user_id)
 AND (status = 'rejected') = (decision_reason IS NOT NULL)
 AND (decision_reason IS NULL OR decision_reason IN ('not_confirmed', 'wrong_value', 'other'))
)
SQL);
        DB::statement("CREATE UNIQUE INDEX hr_record_corrections_one_pending ON hr_record_corrections (worker_id, kind) WHERE status = 'pending'");
        DB::statement("ALTER TABLE hr_record_changes ADD CONSTRAINT hr_record_changes_shape CHECK (area IN ('worker', 'employment', 'position', 'personal', 'bank', 'correction') AND jsonb_typeof(fields) = 'array')");

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION guard_hr_records_append_only() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 RAISE EXCEPTION 'HR record evidence is append-only' USING ERRCODE = 'P0001';
END $$;
CREATE TRIGGER hr_positions_append BEFORE UPDATE OR DELETE ON hr_positions FOR EACH ROW EXECUTE FUNCTION guard_hr_records_append_only();
CREATE TRIGGER hr_record_changes_append BEFORE UPDATE OR DELETE ON hr_record_changes FOR EACH ROW EXECUTE FUNCTION guard_hr_records_append_only();
CREATE TRIGGER hr_record_retries_append BEFORE UPDATE OR DELETE ON hr_record_retries FOR EACH ROW EXECUTE FUNCTION guard_hr_records_append_only();
CREATE TRIGGER hr_workers_no_delete BEFORE DELETE ON hr_workers FOR EACH ROW EXECUTE FUNCTION guard_hr_records_append_only();
CREATE TRIGGER hr_employment_periods_no_delete BEFORE DELETE ON hr_employment_periods FOR EACH ROW EXECUTE FUNCTION guard_hr_records_append_only();
CREATE TRIGGER hr_personal_details_no_delete BEFORE DELETE ON hr_personal_details FOR EACH ROW EXECUTE FUNCTION guard_hr_records_append_only();
CREATE TRIGGER hr_bank_details_no_delete BEFORE DELETE ON hr_bank_details FOR EACH ROW EXECUTE FUNCTION guard_hr_records_append_only();
CREATE TRIGGER hr_record_corrections_no_delete BEFORE DELETE ON hr_record_corrections FOR EACH ROW EXECUTE FUNCTION guard_hr_records_append_only();
CREATE TRIGGER hr_workers_truncate BEFORE TRUNCATE ON hr_workers FOR EACH STATEMENT EXECUTE FUNCTION guard_hr_records_append_only();
CREATE TRIGGER hr_employment_periods_truncate BEFORE TRUNCATE ON hr_employment_periods FOR EACH STATEMENT EXECUTE FUNCTION guard_hr_records_append_only();
CREATE TRIGGER hr_positions_truncate BEFORE TRUNCATE ON hr_positions FOR EACH STATEMENT EXECUTE FUNCTION guard_hr_records_append_only();
CREATE TRIGGER hr_personal_details_truncate BEFORE TRUNCATE ON hr_personal_details FOR EACH STATEMENT EXECUTE FUNCTION guard_hr_records_append_only();
CREATE TRIGGER hr_bank_details_truncate BEFORE TRUNCATE ON hr_bank_details FOR EACH STATEMENT EXECUTE FUNCTION guard_hr_records_append_only();
CREATE TRIGGER hr_record_corrections_truncate BEFORE TRUNCATE ON hr_record_corrections FOR EACH STATEMENT EXECUTE FUNCTION guard_hr_records_append_only();
CREATE TRIGGER hr_record_changes_truncate BEFORE TRUNCATE ON hr_record_changes FOR EACH STATEMENT EXECUTE FUNCTION guard_hr_records_append_only();
CREATE TRIGGER hr_record_retries_truncate BEFORE TRUNCATE ON hr_record_retries FOR EACH STATEMENT EXECUTE FUNCTION guard_hr_records_append_only();

-- A worker's identity never changes: same organisation, same account (or none), same kind.
CREATE OR REPLACE FUNCTION guard_hr_worker_update() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF NEW.id <> OLD.id OR NEW.public_id <> OLD.public_id OR NEW.organisation_id <> OLD.organisation_id OR NEW.user_id IS DISTINCT FROM OLD.user_id
   OR NEW.kind <> OLD.kind OR NEW.created_at IS DISTINCT FROM OLD.created_at THEN
  RAISE EXCEPTION 'HR worker identity is immutable' USING ERRCODE = 'P0001';
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER hr_workers_guard BEFORE UPDATE ON hr_workers FOR EACH ROW EXECUTE FUNCTION guard_hr_worker_update();

-- Employment periods of one worker never overlap (an open period runs to the end of time), and a period keeps its worker.
CREATE OR REPLACE FUNCTION guard_hr_employment_period() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF TG_OP = 'UPDATE' AND (NEW.id <> OLD.id OR NEW.public_id <> OLD.public_id OR NEW.organisation_id <> OLD.organisation_id OR NEW.worker_id <> OLD.worker_id
   OR NEW.created_at IS DISTINCT FROM OLD.created_at) THEN
  RAISE EXCEPTION 'An employment period keeps its worker' USING ERRCODE = 'P0001';
 END IF;
 IF EXISTS (SELECT 1 FROM hr_employment_periods p WHERE p.worker_id = NEW.worker_id AND p.id <> NEW.id
   AND daterange(p.starts_on, p.ends_on, '[]') && daterange(NEW.starts_on, NEW.ends_on, '[]')) THEN
  RAISE EXCEPTION 'Overlapping employment periods' USING ERRCODE = '23P01';
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER hr_employment_periods_guard BEFORE INSERT OR UPDATE ON hr_employment_periods FOR EACH ROW EXECUTE FUNCTION guard_hr_employment_period();

-- Personal details keep their worker.
CREATE OR REPLACE FUNCTION guard_hr_personal_details() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF NEW.id <> OLD.id OR NEW.organisation_id <> OLD.organisation_id OR NEW.worker_id <> OLD.worker_id OR NEW.created_at IS DISTINCT FROM OLD.created_at THEN
  RAISE EXCEPTION 'Personal details keep their worker' USING ERRCODE = 'P0001';
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER hr_personal_details_guard BEFORE UPDATE ON hr_personal_details FOR EACH ROW EXECUTE FUNCTION guard_hr_personal_details();

-- Bank details: entered as waiting; the decider is neither the person who entered them nor the worker; what was entered
-- never changes; waiting moves once (confirmed, rejected, withdrawn) and a confirmed account moves only to replaced.
CREATE OR REPLACE FUNCTION guard_hr_bank_details() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF TG_OP = 'INSERT' THEN
  IF NEW.status <> 'waiting' THEN RAISE EXCEPTION 'Bank details start as waiting' USING ERRCODE = '23514'; END IF;
  RETURN NEW;
 END IF;
 IF NEW.id <> OLD.id OR NEW.public_id <> OLD.public_id OR NEW.organisation_id <> OLD.organisation_id OR NEW.worker_id <> OLD.worker_id
   OR NEW.bank_name_encrypted <> OLD.bank_name_encrypted OR NEW.holder_encrypted <> OLD.holder_encrypted OR NEW.account_encrypted <> OLD.account_encrypted
   OR NEW.account_last4 <> OLD.account_last4 OR NEW.entered_by_user_id <> OLD.entered_by_user_id OR NEW.created_at IS DISTINCT FROM OLD.created_at THEN
  RAISE EXCEPTION 'Bank details are decided as entered' USING ERRCODE = 'P0001';
 END IF;
 IF NOT ((OLD.status = 'waiting' AND NEW.status IN ('confirmed', 'rejected', 'withdrawn')) OR (OLD.status = 'confirmed' AND NEW.status = 'replaced')) THEN
  RAISE EXCEPTION 'Bank details move only once' USING ERRCODE = 'P0001';
 END IF;
 IF OLD.status = 'confirmed' AND (NEW.decided_by_user_id IS DISTINCT FROM OLD.decided_by_user_id OR NEW.decided_at IS DISTINCT FROM OLD.decided_at) THEN
  RAISE EXCEPTION 'A replaced account keeps its confirmation' USING ERRCODE = 'P0001';
 END IF;
 IF NEW.decided_by_user_id IS NOT NULL AND NEW.decided_by_user_id = (SELECT user_id FROM hr_workers WHERE id = NEW.worker_id) THEN
  RAISE EXCEPTION 'Nobody confirms their own bank details' USING ERRCODE = '23514';
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER hr_bank_details_guard BEFORE INSERT OR UPDATE ON hr_bank_details FOR EACH ROW EXECUTE FUNCTION guard_hr_bank_details();

-- Corrections: asked as pending, decided once as asked, never by the worker.
CREATE OR REPLACE FUNCTION guard_hr_record_correction() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF TG_OP = 'INSERT' THEN
  IF NEW.status <> 'pending' THEN RAISE EXCEPTION 'A correction starts as pending' USING ERRCODE = '23514'; END IF;
  RETURN NEW;
 END IF;
 IF OLD.status <> 'pending' THEN RAISE EXCEPTION 'A decided correction never changes' USING ERRCODE = 'P0001'; END IF;
 IF NEW.id <> OLD.id OR NEW.public_id <> OLD.public_id OR NEW.organisation_id <> OLD.organisation_id OR NEW.worker_id <> OLD.worker_id
   OR NEW.kind <> OLD.kind OR NEW.new_value_encrypted <> OLD.new_value_encrypted OR NEW.reason_code <> OLD.reason_code
   OR NEW.note_encrypted IS DISTINCT FROM OLD.note_encrypted OR NEW.asked_by_user_id <> OLD.asked_by_user_id THEN
  RAISE EXCEPTION 'A correction is decided as asked' USING ERRCODE = 'P0001';
 END IF;
 IF NEW.decided_by_user_id IS NOT NULL AND NEW.decided_by_user_id = (SELECT user_id FROM hr_workers WHERE id = NEW.worker_id) THEN
  RAISE EXCEPTION 'Nobody decides a correction to their own file' USING ERRCODE = '23514';
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER hr_record_corrections_guard BEFORE INSERT OR UPDATE ON hr_record_corrections FOR EACH ROW EXECUTE FUNCTION guard_hr_record_correction();
SQL);
    }

    public function down(): void
    {
        // HR record evidence is retained, following the evidence convention.
    }
};
