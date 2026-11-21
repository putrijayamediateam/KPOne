<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * DSP-23 (owner decision 14 of 20-21 Nov 2026; answers 10 = A, 11 = A, 12 = A, 13 = B): a per-branch switch makes the
 * attending doctor check the prepared medicine before the case completes. Consultation cases only.
 *
 * - branch_dispensary_settings: one row per branch; a missing row counts as off.
 * - dispensary_cases: the new status awaiting_doctor_check, and doctor_check_required frozen when the CA first sends
 *   the case (it stays for the life of the case).
 * - dispensary_doctor_checks: one append-only row per Verify or Send back. The insert trigger repeats the tenancy, the
 *   waiting status, the attending-doctor rule and the supervisor rule (never the person who sent the case).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('branch_dispensary_settings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organisation_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->unique()->constrained()->restrictOnDelete();
            $table->boolean('doctor_check_required')->default(false);
            $table->unsignedInteger('lock_version')->default(1);
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->uuid('last_retry_key')->nullable();
            $table->timestamps();
        });

        Schema::table('dispensary_cases', function (Blueprint $table): void {
            $table->boolean('doctor_check_required')->nullable();
            $table->timestampTz('sent_for_check_at')->nullable();
            $table->foreignId('sent_for_check_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->uuid('sent_for_check_retry_key')->nullable();
        });

        Schema::create('dispensary_doctor_checks', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('organisation_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('dispensary_case_id')->constrained('dispensary_cases')->restrictOnDelete();
            $table->foreignId('dispensary_handoff_id')->constrained('dispensary_handoffs')->restrictOnDelete();
            $table->unsignedInteger('case_lock_version');
            $table->foreignId('checked_by_user_id')->constrained('users')->restrictOnDelete();
            $table->string('checked_as', 16);
            $table->string('outcome', 16);
            $table->string('reason', 40)->nullable();
            $table->text('note_encrypted')->nullable();
            $table->string('override_reason', 40)->nullable();
            $table->text('override_note_encrypted')->nullable();
            $table->timestampTz('checked_at');
            $table->uuid('retry_key');
            $table->string('payload_hash', 64);
            $table->unique(['organisation_id', 'checked_by_user_id', 'retry_key'], 'dispensary_doctor_check_retry_once');
            $table->index(['dispensary_case_id', 'id'], 'dispensary_doctor_check_case_index');
        });

        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE dispensary_cases DROP CONSTRAINT dispensary_cases_status_check');
        DB::statement("ALTER TABLE dispensary_cases ADD CONSTRAINT dispensary_cases_status_check CHECK (status IN ('pending', 'dispensing', 'awaiting_doctor_check', 'returned_to_doctor', 'completed'))");
        DB::statement("ALTER TABLE dispensary_cases ADD CONSTRAINT dispensary_cases_doctor_check_shape CHECK (
            (status <> 'awaiting_doctor_check' OR (doctor_check_required IS TRUE AND case_type = 'consultation' AND sent_for_check_at IS NOT NULL AND sent_for_check_by_user_id IS NOT NULL))
            AND (doctor_check_required IS NOT TRUE OR case_type = 'consultation')
        )");
        DB::statement("ALTER TABLE dispensary_doctor_checks ADD CONSTRAINT dispensary_doctor_check_shape CHECK (
            case_lock_version > 0
            AND checked_as IN ('doctor', 'supervisor')
            AND outcome IN ('verified', 'sent_back')
            AND ((outcome = 'sent_back') = (reason IS NOT NULL))
            AND (reason IS NULL OR reason IN ('wrong_medicine', 'wrong_quantity', 'wrong_batch', 'label_problem', 'other'))
            AND ((reason = 'other') IS NOT TRUE OR note_encrypted IS NOT NULL)
            AND (reason IS NOT NULL OR note_encrypted IS NULL)
            AND (override_reason IS NULL OR override_reason IN ('doctor_off_shift', 'doctor_emergency', 'other'))
            AND (checked_as = 'supervisor' OR override_reason IS NULL)
            AND (checked_as = 'doctor' OR outcome = 'sent_back' OR override_reason IS NOT NULL)
            AND ((override_reason = 'other') IS NOT TRUE OR override_note_encrypted IS NOT NULL)
            AND (override_reason IS NOT NULL OR override_note_encrypted IS NULL)
        )");
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION validate_dispensary_doctor_check_insert() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE
    c dispensary_cases%ROWTYPE;
    attending bigint;
BEGIN
    SELECT * INTO c FROM dispensary_cases WHERE id = NEW.dispensary_case_id;
    IF c.id IS NULL
        OR c.organisation_id <> NEW.organisation_id
        OR c.branch_id <> NEW.branch_id
        OR c.status <> 'awaiting_doctor_check'
        OR c.lock_version <> NEW.case_lock_version
        OR NOT EXISTS (SELECT 1 FROM dispensary_handoffs h WHERE h.id = NEW.dispensary_handoff_id AND h.dispensary_case_id = c.id AND h.status = 'open')
        OR NOT EXISTS (SELECT 1 FROM users u WHERE u.id = NEW.checked_by_user_id AND u.organisation_id = c.organisation_id) THEN
        RAISE EXCEPTION 'Invalid dispensary doctor check source' USING ERRCODE = '23514';
    END IF;
    SELECT e.attending_clinician_user_id INTO attending FROM clinical_encounters e WHERE e.id = c.clinical_encounter_id;
    IF NEW.checked_as = 'doctor' AND (attending IS NULL OR attending <> NEW.checked_by_user_id) THEN
        RAISE EXCEPTION 'Only the attending clinician may check this case' USING ERRCODE = '23514';
    END IF;
    IF NEW.checked_as = 'supervisor' AND (NEW.checked_by_user_id = c.sent_for_check_by_user_id OR NEW.checked_by_user_id = c.current_handler_user_id) THEN
        RAISE EXCEPTION 'A supervisor may not check a case they prepared' USING ERRCODE = '23514';
    END IF;
    RETURN NEW;
END; $$;
CREATE TRIGGER dispensary_doctor_checks_source BEFORE INSERT ON dispensary_doctor_checks FOR EACH ROW EXECUTE FUNCTION validate_dispensary_doctor_check_insert();
CREATE OR REPLACE FUNCTION refuse_dispensary_doctor_check_mutation() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    RAISE EXCEPTION 'Dispensary doctor checks are append-only' USING ERRCODE = 'P0001';
END; $$;
CREATE TRIGGER dispensary_doctor_checks_immutable BEFORE UPDATE OR DELETE OR TRUNCATE ON dispensary_doctor_checks FOR EACH STATEMENT EXECUTE FUNCTION refuse_dispensary_doctor_check_mutation();
SQL);
    }

    public function down(): void
    {
        // Retain the append-only check evidence and the frozen case flags; no purge.
    }
};
