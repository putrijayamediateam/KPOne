<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mc_controls', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('organisation_id');
            $t->unsignedBigInteger('branch_id');
            $t->unsignedBigInteger('visit_id')->unique();
            $t->unsignedInteger('lock_version')->default(0);
            $t->foreign(['visit_id', 'organisation_id', 'branch_id'])->references(['id', 'organisation_id', 'branch_id'])->on('visits')->restrictOnDelete();
        });
        Schema::create('mc_number_counters', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('organisation_id')->constrained()->restrictOnDelete();
            $t->unsignedBigInteger('branch_id');
            $t->unsignedSmallInteger('year');
            $t->unsignedInteger('last_number');
            $t->unique(['organisation_id', 'branch_id', 'year']);
            $t->foreign(['branch_id', 'organisation_id'])->references(['id', 'organisation_id'])->on('branches')->restrictOnDelete();
        });
        Schema::create('medical_certificates', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('organisation_id');
            $t->unsignedBigInteger('branch_id');
            $t->unsignedBigInteger('visit_id');
            $t->unsignedBigInteger('clinical_encounter_id');
            $t->unsignedBigInteger('issuer_user_id');
            $t->foreignId('template_revision_id')->constrained('mc_template_revisions')->restrictOnDelete();
            $t->string('number', 60)->unique();
            $t->longText('encrypted_snapshot');
            $t->char('ciphertext_sha256', 64);
            $t->timestampTz('issued_at', 6);
            $t->unique(['id', 'organisation_id', 'branch_id', 'visit_id'], 'mc_document_owner');
            $t->foreign(['visit_id', 'organisation_id', 'branch_id'])->references(['id', 'organisation_id', 'branch_id'])->on('visits')->restrictOnDelete();
            $t->foreign(['clinical_encounter_id', 'organisation_id', 'branch_id'])->references(['id', 'organisation_id', 'branch_id'])->on('clinical_encounters')->restrictOnDelete();
            $t->foreign(['issuer_user_id', 'organisation_id'])->references(['id', 'organisation_id'])->on('users')->restrictOnDelete();
        });
        Schema::create('mc_actions', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('organisation_id');
            $t->unsignedBigInteger('branch_id');
            $t->unsignedBigInteger('visit_id');
            $t->unsignedBigInteger('actor_user_id');
            $t->unsignedBigInteger('document_id');
            $t->unsignedBigInteger('replaces_id')->nullable()->unique();
            $t->string('action', 10);
            $t->string('change_reason', 30)->nullable();
            $t->uuid('idempotency_key')->unique();
            $t->char('payload_hash', 64);
            $t->unsignedInteger('control_version');
            $t->timestampTz('created_at', 6);
            $t->unique(['visit_id', 'control_version']);
            $t->foreign(['actor_user_id', 'organisation_id'])->references(['id', 'organisation_id'])->on('users')->restrictOnDelete();
            foreach (['document_id', 'replaces_id'] as $column) {
                $t->foreign([$column, 'organisation_id', 'branch_id', 'visit_id'])->references(['id', 'organisation_id', 'branch_id', 'visit_id'])->on('medical_certificates')->restrictOnDelete();
            }
        });
        DB::statement('ALTER TABLE mc_controls ADD CONSTRAINT mc_control_version CHECK (lock_version >= 0)');
        DB::statement('ALTER TABLE mc_number_counters ADD CONSTRAINT mc_counter_shape CHECK (last_number BETWEEN 1 AND 999999 AND year BETWEEN 2000 AND 9999)');
        DB::statement("ALTER TABLE medical_certificates ADD CONSTRAINT mc_document_shape CHECK (number ~ '^MC/KP/(CHR|SGB|PCG)/[0-9]{4}/[0-9]{6}$' AND length(encrypted_snapshot) > 50 AND ciphertext_sha256 ~ '^[0-9a-f]{64}$')");
        DB::statement("ALTER TABLE mc_actions ADD CONSTRAINT mc_action_shape CHECK (control_version >= 1 AND ((action = 'issue' AND replaces_id IS NULL) OR (action = 'replace' AND replaces_id IS NOT NULL AND replaces_id <> document_id) OR (action = 'void' AND replaces_id = document_id)))");
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION protect_mc_evidence() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN RAISE EXCEPTION 'Medical certificate evidence is immutable'; END; $$;
CREATE TRIGGER mc_document_immutable BEFORE UPDATE OR DELETE ON medical_certificates FOR EACH ROW EXECUTE FUNCTION protect_mc_evidence();
CREATE TRIGGER mc_document_no_truncate BEFORE TRUNCATE ON medical_certificates FOR EACH STATEMENT EXECUTE FUNCTION protect_mc_evidence();
CREATE TRIGGER mc_action_immutable BEFORE UPDATE OR DELETE ON mc_actions FOR EACH ROW EXECUTE FUNCTION protect_mc_evidence();
CREATE TRIGGER mc_action_no_truncate BEFORE TRUNCATE ON mc_actions FOR EACH STATEMENT EXECUTE FUNCTION protect_mc_evidence();
CREATE OR REPLACE FUNCTION guard_mc_source() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF TG_TABLE_NAME = 'medical_certificates' THEN
   IF NOT EXISTS (SELECT 1 FROM clinical_encounters WHERE id = NEW.clinical_encounter_id AND visit_id = NEW.visit_id AND attending_clinician_user_id = NEW.issuer_user_id) THEN RAISE EXCEPTION 'MC Encounter ownership mismatch'; END IF;
 ELSE
   IF NOT EXISTS (SELECT 1 FROM medical_certificates WHERE id = NEW.document_id AND issuer_user_id = NEW.actor_user_id) OR
      (NEW.replaces_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM medical_certificates WHERE id = NEW.replaces_id AND issuer_user_id = NEW.actor_user_id)) THEN RAISE EXCEPTION 'Only original MC issuer may act'; END IF;
 END IF;
 RETURN NEW;
END; $$;
CREATE TRIGGER mc_document_source BEFORE INSERT ON medical_certificates FOR EACH ROW EXECUTE FUNCTION guard_mc_source();
CREATE TRIGGER mc_action_source BEFORE INSERT ON mc_actions FOR EACH ROW EXECUTE FUNCTION guard_mc_source();
CREATE OR REPLACE FUNCTION guard_mc_control() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF TG_OP = 'DELETE' THEN RAISE EXCEPTION 'MC metadata is retained'; END IF;
 IF TG_OP = 'UPDATE' THEN
   IF TG_TABLE_NAME = 'mc_controls' THEN
     IF NEW.organisation_id <> OLD.organisation_id OR NEW.branch_id <> OLD.branch_id OR NEW.visit_id <> OLD.visit_id OR NEW.lock_version <> OLD.lock_version + 1 THEN RAISE EXCEPTION 'Invalid MC version transition'; END IF;
   END IF;
   IF TG_TABLE_NAME = 'mc_number_counters' THEN
     IF NEW.organisation_id <> OLD.organisation_id OR NEW.branch_id <> OLD.branch_id OR NEW.year <> OLD.year OR NEW.last_number <> OLD.last_number + 1 THEN RAISE EXCEPTION 'Invalid MC counter transition'; END IF;
   END IF;
 END IF;
 RETURN NEW;
END; $$;
CREATE TRIGGER mc_control_guard BEFORE UPDATE OR DELETE ON mc_controls FOR EACH ROW EXECUTE FUNCTION guard_mc_control();
CREATE TRIGGER mc_counter_guard BEFORE UPDATE OR DELETE ON mc_number_counters FOR EACH ROW EXECUTE FUNCTION guard_mc_control();
CREATE TRIGGER mc_control_no_truncate BEFORE TRUNCATE ON mc_controls FOR EACH STATEMENT EXECUTE FUNCTION protect_mc_evidence();
CREATE TRIGGER mc_counter_no_truncate BEFORE TRUNCATE ON mc_number_counters FOR EACH STATEMENT EXECUTE FUNCTION protect_mc_evidence();
SQL);
    }

    public function down(): void
    {
        foreach (['mc_actions', 'medical_certificates', 'mc_number_counters', 'mc_controls'] as $table) {
            Schema::dropIfExists($table);
        }
        foreach (['guard_mc_control', 'guard_mc_source', 'protect_mc_evidence'] as $function) {
            DB::statement("DROP FUNCTION IF EXISTS {$function}()");
        }
    }
};
