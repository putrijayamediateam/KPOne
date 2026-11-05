<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * REG-3 (owner decisions 2026-11-05, docs/teams/patients-registration/REG-3-DESIGN.md): two-person patient merge by
 * link. Nothing is re-pointed: a merged-away patient only gains a pointer to the kept patient, written in step with
 * an append-only merge event. Unmerge clears the pointer through the same two-person request flow.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('patient_merge_requests', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('organisation_id')->constrained()->restrictOnDelete();
            $table->string('kind', 10);
            $table->foreignId('kept_patient_id')->constrained('patients')->restrictOnDelete();
            $table->foreignId('merged_patient_id')->constrained('patients')->restrictOnDelete();
            $table->string('reason_code', 40);
            $table->string('status', 12);
            $table->foreignId('proposed_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestampTz('proposed_at');
            $table->foreignId('decided_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('decided_at')->nullable();
            $table->unsignedInteger('kept_lock_version');
            $table->unsignedInteger('merged_lock_version');
            $table->uuid('idempotency_key');
            $table->string('payload_hash', 64);
            $table->unsignedInteger('lock_version')->default(1);
            $table->timestampsTz();
            $table->unique(['organisation_id', 'proposed_by_user_id', 'idempotency_key'], 'patient_merge_request_retry_unique');
        });
        DB::statement('ALTER TABLE patient_merge_requests ADD CONSTRAINT patient_merge_request_kept_tenant_fk FOREIGN KEY (kept_patient_id, organisation_id) REFERENCES patients(id, organisation_id) ON DELETE RESTRICT');
        DB::statement('ALTER TABLE patient_merge_requests ADD CONSTRAINT patient_merge_request_merged_tenant_fk FOREIGN KEY (merged_patient_id, organisation_id) REFERENCES patients(id, organisation_id) ON DELETE RESTRICT');
        DB::statement(<<<'SQL'
ALTER TABLE patient_merge_requests ADD CONSTRAINT patient_merge_request_shape CHECK (
    kind IN ('merge', 'unmerge')
    AND kept_patient_id <> merged_patient_id
    AND reason_code IN ('same_person_registered_twice', 'wrong_identifier_typed', 'other_explained_in_person', 'merged_in_error')
    AND status IN ('proposed', 'approved', 'rejected', 'withdrawn')
    AND lock_version > 0
    AND ((status = 'proposed') = (decided_by_user_id IS NULL))
    AND ((decided_by_user_id IS NULL) = (decided_at IS NULL))
    AND (status NOT IN ('approved', 'rejected') OR decided_by_user_id <> proposed_by_user_id)
    AND (status <> 'withdrawn' OR decided_by_user_id = proposed_by_user_id)
)
SQL);
        DB::statement("CREATE UNIQUE INDEX patient_merge_requests_one_open ON patient_merge_requests (merged_patient_id) WHERE status = 'proposed'");

        Schema::create('patient_merges', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organisation_id')->constrained()->restrictOnDelete();
            $table->foreignId('patient_merge_request_id')->unique()->constrained('patient_merge_requests')->restrictOnDelete();
            $table->string('event', 10);
            $table->foreignId('kept_patient_id')->constrained('patients')->restrictOnDelete();
            $table->foreignId('merged_patient_id')->constrained('patients')->restrictOnDelete();
            $table->foreignId('proposed_by_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestampTz('occurred_at');
            $table->index(['merged_patient_id', 'id']);
        });
        DB::statement("ALTER TABLE patient_merges ADD CONSTRAINT patient_merge_event_shape CHECK (event IN ('merged', 'unmerged') AND kept_patient_id <> merged_patient_id AND approved_by_user_id <> proposed_by_user_id)");

        Schema::table('patients', function (Blueprint $table): void {
            $table->unsignedBigInteger('merged_into_patient_id')->nullable();
            $table->timestampTz('merged_at')->nullable();
            $table->index('merged_into_patient_id');
        });
        DB::statement('ALTER TABLE patients ADD CONSTRAINT patients_merged_into_tenant_fk FOREIGN KEY (merged_into_patient_id, organisation_id) REFERENCES patients(id, organisation_id) ON DELETE RESTRICT');
        DB::statement('ALTER TABLE patients ADD CONSTRAINT patients_merge_shape CHECK ((merged_into_patient_id IS NULL) = (merged_at IS NULL) AND (merged_into_patient_id IS NULL OR merged_into_patient_id <> id))');

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION kpone_patient_merge_request_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF TG_OP <> 'UPDATE' THEN
        RAISE EXCEPTION 'Patient merge requests are retained' USING ERRCODE = 'P0001';
    END IF;
    IF OLD.status <> 'proposed' OR NEW.status = 'proposed' THEN
        RAISE EXCEPTION 'Patient merge request decisions are final' USING ERRCODE = '23514';
    END IF;
    IF (to_jsonb(NEW) - ARRAY['status', 'decided_by_user_id', 'decided_at', 'lock_version', 'updated_at'])
        IS DISTINCT FROM (to_jsonb(OLD) - ARRAY['status', 'decided_by_user_id', 'decided_at', 'lock_version', 'updated_at']) THEN
        RAISE EXCEPTION 'Patient merge request facts are immutable' USING ERRCODE = '23514';
    END IF;
    RETURN NEW;
END; $$;
CREATE TRIGGER patient_merge_requests_guard BEFORE UPDATE OR DELETE ON patient_merge_requests FOR EACH ROW EXECUTE FUNCTION kpone_patient_merge_request_guard();
CREATE TRIGGER patient_merge_requests_no_truncate BEFORE TRUNCATE ON patient_merge_requests FOR EACH STATEMENT EXECUTE FUNCTION kpone_patient_merge_request_guard();

CREATE OR REPLACE FUNCTION kpone_patient_merge_event_insert() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE r patient_merge_requests%ROWTYPE;
BEGIN
    SELECT * INTO r FROM patient_merge_requests WHERE id = NEW.patient_merge_request_id;
    IF r.status <> 'approved'
        OR r.organisation_id <> NEW.organisation_id
        OR r.kept_patient_id <> NEW.kept_patient_id
        OR r.merged_patient_id <> NEW.merged_patient_id
        OR r.proposed_by_user_id <> NEW.proposed_by_user_id
        OR r.decided_by_user_id <> NEW.approved_by_user_id
        OR (r.kind = 'merge') <> (NEW.event = 'merged') THEN
        RAISE EXCEPTION 'Patient merge event must match its approved request' USING ERRCODE = '23514';
    END IF;
    RETURN NEW;
END; $$;
CREATE TRIGGER patient_merges_source BEFORE INSERT ON patient_merges FOR EACH ROW EXECUTE FUNCTION kpone_patient_merge_event_insert();

CREATE OR REPLACE FUNCTION kpone_patient_merge_event_retained() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    RAISE EXCEPTION 'Patient merge events are append-only' USING ERRCODE = 'P0001';
END; $$;
CREATE TRIGGER patient_merges_immutable BEFORE UPDATE OR DELETE OR TRUNCATE ON patient_merges FOR EACH STATEMENT EXECUTE FUNCTION kpone_patient_merge_event_retained();

CREATE OR REPLACE FUNCTION kpone_patient_merge_pointer() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE latest patient_merges%ROWTYPE;
BEGIN
    IF NEW.merged_into_patient_id IS NOT DISTINCT FROM OLD.merged_into_patient_id THEN
        IF NEW.merged_at IS DISTINCT FROM OLD.merged_at THEN
            RAISE EXCEPTION 'The merge time of a patient cannot be changed' USING ERRCODE = '23514';
        END IF;
        RETURN NEW;
    END IF;
    SELECT * INTO latest FROM patient_merges WHERE merged_patient_id = NEW.id ORDER BY id DESC LIMIT 1;
    IF NEW.merged_into_patient_id IS NULL THEN
        IF latest.id IS NULL OR latest.event <> 'unmerged' OR latest.kept_patient_id <> OLD.merged_into_patient_id THEN
            RAISE EXCEPTION 'A patient can only be unmerged through an approved unmerge' USING ERRCODE = '23514';
        END IF;
    ELSE
        IF OLD.merged_into_patient_id IS NOT NULL
            OR latest.id IS NULL OR latest.event <> 'merged' OR latest.kept_patient_id <> NEW.merged_into_patient_id THEN
            RAISE EXCEPTION 'A patient can only be merged through an approved merge' USING ERRCODE = '23514';
        END IF;
        IF EXISTS (SELECT 1 FROM patients WHERE id = NEW.merged_into_patient_id AND merged_into_patient_id IS NOT NULL)
            OR EXISTS (SELECT 1 FROM patients WHERE merged_into_patient_id = NEW.id) THEN
            RAISE EXCEPTION 'Patient merges cannot form chains' USING ERRCODE = '23514';
        END IF;
    END IF;
    RETURN NEW;
END; $$;
CREATE TRIGGER patients_merge_pointer BEFORE UPDATE OF merged_into_patient_id, merged_at ON patients FOR EACH ROW EXECUTE FUNCTION kpone_patient_merge_pointer();
SQL);
    }

    public function down(): void
    {
        // Merge evidence is retained: a merge is reversed only through an approved unmerge, never by rollback.
        if (DB::table('patient_merge_requests')->exists()) {
            throw new RuntimeException('Patient merge evidence exists; rolling back this migration is refused.');
        }
        DB::unprepared(<<<'SQL'
DROP TRIGGER IF EXISTS patients_merge_pointer ON patients;
ALTER TABLE patients DROP CONSTRAINT IF EXISTS patients_merge_shape;
ALTER TABLE patients DROP CONSTRAINT IF EXISTS patients_merged_into_tenant_fk;
SQL);
        Schema::table('patients', function (Blueprint $table): void {
            $table->dropIndex(['merged_into_patient_id']);
            $table->dropColumn(['merged_into_patient_id', 'merged_at']);
        });
        Schema::drop('patient_merges');
        Schema::drop('patient_merge_requests');
        DB::unprepared(<<<'SQL'
DROP FUNCTION IF EXISTS kpone_patient_merge_pointer();
DROP FUNCTION IF EXISTS kpone_patient_merge_event_retained();
DROP FUNCTION IF EXISTS kpone_patient_merge_event_insert();
DROP FUNCTION IF EXISTS kpone_patient_merge_request_guard();
SQL);
    }
};
