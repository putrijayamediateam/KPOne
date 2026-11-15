<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clinician_professional_profiles', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('organisation_id');
            $t->unsignedBigInteger('user_id')->unique();
            $t->foreignId('staff_profile_id')->unique()->constrained('staff_profiles')->restrictOnDelete();
            $t->string('professional_name', 200);
            $t->string('mmc_number', 30);
            $t->boolean('verified')->default(false);
            $t->unsignedBigInteger('verified_by_user_id')->nullable();
            $t->timestampTz('verified_at')->nullable();
            $t->unsignedBigInteger('updated_by_user_id');
            $t->unsignedInteger('lock_version');
            $t->timestampsTz();
            $t->unique(['organisation_id', 'mmc_number']);
            foreach (['user_id', 'verified_by_user_id', 'updated_by_user_id'] as $column) {
                $t->foreign([$column, 'organisation_id'])->references(['id', 'organisation_id'])->on('users')->restrictOnDelete();
            }
        });
        Schema::create('clinician_credential_commands', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('organisation_id');
            $t->unsignedBigInteger('actor_user_id');
            $t->unsignedBigInteger('subject_user_id');
            $t->uuid('idempotency_key')->unique();
            $t->char('payload_hash', 64);
            $t->timestampTz('created_at');
            foreach (['actor_user_id', 'subject_user_id'] as $column) {
                $t->foreign([$column, 'organisation_id'])->references(['id', 'organisation_id'])->on('users')->restrictOnDelete();
            }
        });
        Schema::create('mc_template_revisions', function (Blueprint $t): void {
            $t->id();
            $t->string('language', 2);
            $t->unsignedInteger('revision');
            $t->string('title', 100);
            $t->text('wording');
            $t->jsonb('merge_fields');
            $t->string('renderer_version', 40);
            $t->boolean('production_approved')->default(false);
            $t->timestampTz('created_at');
            $t->unique(['language', 'revision']);
        });
        $fields = ['patient_name', 'identification', 'examination_date', 'issue_date', 'start_date', 'end_date', 'days_number', 'days_words', 'doctor_name', 'mmc_number', 'mc_number'];
        foreach ([
            'en' => ['Medical Certificate', 'This is to certify that {{patient_name}} ({{identification}}) was examined on {{examination_date}} and is medically unfit for work for {{days_number}} ({{days_words}}) days, from {{start_date}} to {{end_date}}, inclusive.'],
            'bm' => ['Sijil Cuti Sakit', 'Dengan ini disahkan bahawa {{patient_name}} ({{identification}}) telah diperiksa pada {{examination_date}} dan tidak sihat untuk bekerja selama {{days_number}} ({{days_words}}) hari, dari {{start_date}} hingga {{end_date}}, termasuk kedua-dua tarikh tersebut.'],
        ] as $language => [$title, $wording]) {
            DB::table('mc_template_revisions')->insert(['language' => $language, 'revision' => 1, 'title' => $title, 'wording' => $wording, 'merge_fields' => json_encode($fields, JSON_THROW_ON_ERROR), 'renderer_version' => 'mc-a4-v1', 'production_approved' => false, 'created_at' => now()->utc()->format('Y-m-d H:i:s.uP')]);
        }
        DB::statement("ALTER TABLE clinician_professional_profiles ADD CONSTRAINT clinician_profile_shape CHECK (lock_version >= 1 AND length(trim(professional_name)) > 0 AND mmc_number ~ '^[0-9]{1,30}$' AND ((verified AND verified_at IS NOT NULL AND verified_by_user_id IS NOT NULL) OR (NOT verified AND verified_at IS NULL AND verified_by_user_id IS NULL)))");
        DB::statement("ALTER TABLE mc_template_revisions ADD CONSTRAINT mc_template_shape CHECK (language IN ('en','bm') AND revision >= 1 AND char_length(wording) BETWEEN 1 AND 2000 AND wording !~ '[<>]' AND renderer_version = 'mc-a4-v1' AND NOT production_approved)");
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION protect_doc02a_evidence() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN RAISE EXCEPTION 'DOC-02a evidence is append-only'; END; $$;
CREATE TRIGGER mc_template_immutable BEFORE UPDATE OR DELETE ON mc_template_revisions FOR EACH ROW EXECUTE FUNCTION protect_doc02a_evidence();
CREATE TRIGGER credential_command_immutable BEFORE UPDATE OR DELETE ON clinician_credential_commands FOR EACH ROW EXECUTE FUNCTION protect_doc02a_evidence();
CREATE TRIGGER mc_template_no_truncate BEFORE TRUNCATE ON mc_template_revisions FOR EACH STATEMENT EXECUTE FUNCTION protect_doc02a_evidence();
CREATE TRIGGER credential_command_no_truncate BEFORE TRUNCATE ON clinician_credential_commands FOR EACH STATEMENT EXECUTE FUNCTION protect_doc02a_evidence();
CREATE OR REPLACE FUNCTION guard_clinician_profile() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF TG_OP = 'DELETE' THEN RAISE EXCEPTION 'Clinician profiles are retained'; END IF;
 IF NOT EXISTS (SELECT 1 FROM staff_profiles WHERE id = NEW.staff_profile_id AND user_id = NEW.user_id) THEN RAISE EXCEPTION 'Clinician staff linkage mismatch'; END IF;
 IF TG_OP = 'UPDATE' AND (NEW.organisation_id <> OLD.organisation_id OR NEW.user_id <> OLD.user_id OR NEW.staff_profile_id <> OLD.staff_profile_id OR NEW.lock_version <> OLD.lock_version + 1 OR NEW.created_at IS DISTINCT FROM OLD.created_at) THEN RAISE EXCEPTION 'Invalid clinician profile transition'; END IF;
 RETURN NEW;
END; $$;
CREATE TRIGGER clinician_profile_guard BEFORE INSERT OR UPDATE OR DELETE ON clinician_professional_profiles FOR EACH ROW EXECUTE FUNCTION guard_clinician_profile();
CREATE TRIGGER clinician_profile_no_truncate BEFORE TRUNCATE ON clinician_professional_profiles FOR EACH STATEMENT EXECUTE FUNCTION protect_doc02a_evidence();
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('mc_template_revisions');
        Schema::dropIfExists('clinician_credential_commands');
        Schema::dropIfExists('clinician_professional_profiles');
        DB::statement('DROP FUNCTION IF EXISTS protect_doc02a_evidence()');
        DB::statement('DROP FUNCTION IF EXISTS guard_clinician_profile()');
    }
};
