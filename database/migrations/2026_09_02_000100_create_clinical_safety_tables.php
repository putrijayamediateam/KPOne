<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('patient_allergy_profiles', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organisation_id');
            $table->unsignedBigInteger('patient_id');
            $table->string('status', 32);
            $table->timestamp('reviewed_at')->nullable();
            $table->unsignedBigInteger('reviewed_by_user_id')->nullable();
            $table->unsignedBigInteger('updated_by_user_id');
            $table->unsignedInteger('lock_version')->default(1);
            $table->timestamps();

            $table->foreign(
                ['patient_id', 'organisation_id'],
                'allergy_profiles_patient_organisation_fk',
            )->references(['id', 'organisation_id'])->on('patients')->restrictOnDelete();
            $table->foreign(
                ['reviewed_by_user_id', 'organisation_id'],
                'allergy_profiles_reviewer_organisation_fk',
            )->references(['id', 'organisation_id'])->on('users')->restrictOnDelete();
            $table->foreign(
                ['updated_by_user_id', 'organisation_id'],
                'allergy_profiles_updated_by_organisation_fk',
            )->references(['id', 'organisation_id'])->on('users')->restrictOnDelete();

            $table->unique('patient_id');
            $table->unique(
                ['id', 'organisation_id'],
                'allergy_profiles_id_organisation_unique',
            );
        });

        Schema::create('patient_allergy_profile_versions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organisation_id');
            $table->unsignedBigInteger('patient_allergy_profile_id');
            $table->unsignedInteger('version');
            $table->string('resulting_status', 32);
            $table->timestamp('changed_at');
            $table->unsignedBigInteger('changed_by_user_id');
            $table->timestamps();

            $table->foreign(
                ['patient_allergy_profile_id', 'organisation_id'],
                'allergy_profile_versions_profile_organisation_fk',
            )->references(['id', 'organisation_id'])->on('patient_allergy_profiles')->restrictOnDelete();
            $table->foreign(
                ['changed_by_user_id', 'organisation_id'],
                'allergy_profile_versions_actor_organisation_fk',
            )->references(['id', 'organisation_id'])->on('users')->restrictOnDelete();

            $table->unique(
                ['patient_allergy_profile_id', 'organisation_id', 'version'],
                'allergy_profile_versions_profile_version_unique',
            );
        });

        Schema::create('patient_allergy_records', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id');
            $table->unsignedBigInteger('organisation_id');
            $table->unsignedBigInteger('patient_allergy_profile_id');
            $table->string('allergen_text', 500);
            $table->string('category', 32)->nullable();
            $table->string('reaction_text', 1000)->nullable();
            $table->string('severity', 32)->nullable();
            $table->string('status', 32);
            $table->timestamp('recorded_at');
            $table->unsignedBigInteger('recorded_by_user_id');
            $table->unsignedBigInteger('updated_by_user_id');
            $table->timestamp('entered_in_error_at')->nullable();
            $table->unsignedBigInteger('entered_in_error_by_user_id')->nullable();
            $table->timestamps();

            $table->foreign(
                ['patient_allergy_profile_id', 'organisation_id'],
                'allergy_records_profile_organisation_fk',
            )->references(['id', 'organisation_id'])->on('patient_allergy_profiles')->restrictOnDelete();
            $table->foreign(
                ['recorded_by_user_id', 'organisation_id'],
                'allergy_records_recorded_by_organisation_fk',
            )->references(['id', 'organisation_id'])->on('users')->restrictOnDelete();
            $table->foreign(
                ['updated_by_user_id', 'organisation_id'],
                'allergy_records_updated_by_organisation_fk',
            )->references(['id', 'organisation_id'])->on('users')->restrictOnDelete();
            $table->foreign(
                ['entered_in_error_by_user_id', 'organisation_id'],
                'allergy_records_error_by_organisation_fk',
            )->references(['id', 'organisation_id'])->on('users')->restrictOnDelete();

            $table->unique('public_id');
            $table->index(
                ['patient_allergy_profile_id', 'status', 'id'],
                'allergy_records_profile_status_index',
            );
        });

        Schema::create('clinical_encounter_allergy_reviews', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organisation_id');
            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('clinical_encounter_id');
            $table->unsignedBigInteger('patient_allergy_profile_id');
            $table->unsignedInteger('allergy_profile_lock_version_reviewed');
            $table->unsignedBigInteger('reviewed_by_user_id');
            $table->timestamp('reviewed_at');
            $table->timestamps();

            $table->foreign(
                ['clinical_encounter_id', 'organisation_id', 'branch_id'],
                'encounter_allergy_reviews_encounter_tenant_fk',
            )->references(['id', 'organisation_id', 'branch_id'])->on('clinical_encounters')->restrictOnDelete();
            $table->foreign(
                ['patient_allergy_profile_id', 'organisation_id'],
                'encounter_allergy_reviews_profile_tenant_fk',
            )->references(['id', 'organisation_id'])->on('patient_allergy_profiles')->restrictOnDelete();
            $table->foreign(
                ['patient_allergy_profile_id', 'organisation_id', 'allergy_profile_lock_version_reviewed'],
                'encounter_allergy_reviews_profile_version_fk',
            )->references(['patient_allergy_profile_id', 'organisation_id', 'version'])
                ->on('patient_allergy_profile_versions')->restrictOnDelete();
            $table->foreign(
                ['reviewed_by_user_id', 'organisation_id'],
                'encounter_allergy_reviews_reviewer_tenant_fk',
            )->references(['id', 'organisation_id'])->on('users')->restrictOnDelete();

            $table->unique('clinical_encounter_id');
        });

        Schema::create('patient_problem_records', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id');
            $table->unsignedBigInteger('organisation_id');
            $table->unsignedBigInteger('patient_id');
            $table->string('condition_text', 500);
            $table->string('condition_code', 50)->nullable();
            $table->string('code_system', 50)->nullable();
            $table->string('status', 32);
            $table->date('onset_date')->nullable();
            $table->date('resolved_date')->nullable();
            $table->unsignedBigInteger('recorded_by_user_id');
            $table->unsignedBigInteger('updated_by_user_id');
            $table->timestamp('resolved_at')->nullable();
            $table->unsignedBigInteger('resolved_by_user_id')->nullable();
            $table->timestamp('entered_in_error_at')->nullable();
            $table->unsignedBigInteger('entered_in_error_by_user_id')->nullable();
            $table->unsignedInteger('lock_version')->default(1);
            $table->timestamps();

            $table->foreign(
                ['patient_id', 'organisation_id'],
                'problem_records_patient_organisation_fk',
            )->references(['id', 'organisation_id'])->on('patients')->restrictOnDelete();
            $table->foreign(
                ['recorded_by_user_id', 'organisation_id'],
                'problem_records_recorded_by_organisation_fk',
            )->references(['id', 'organisation_id'])->on('users')->restrictOnDelete();
            $table->foreign(
                ['updated_by_user_id', 'organisation_id'],
                'problem_records_updated_by_organisation_fk',
            )->references(['id', 'organisation_id'])->on('users')->restrictOnDelete();
            $table->foreign(
                ['resolved_by_user_id', 'organisation_id'],
                'problem_records_resolved_by_organisation_fk',
            )->references(['id', 'organisation_id'])->on('users')->restrictOnDelete();
            $table->foreign(
                ['entered_in_error_by_user_id', 'organisation_id'],
                'problem_records_error_by_organisation_fk',
            )->references(['id', 'organisation_id'])->on('users')->restrictOnDelete();

            $table->unique('public_id');
            $table->index(
                ['patient_id', 'status', 'id'],
                'problem_records_patient_status_index',
            );
        });

        $driver = DB::connection()->getDriverName();
        if ($driver === 'pgsql') {
            $this->addPostgresConstraints();
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::unprepared('DROP FUNCTION IF EXISTS kpone_validate_encounter_allergy_review_patient() CASCADE');
            DB::unprepared('DROP FUNCTION IF EXISTS kpone_validate_allergy_profile_consistency() CASCADE');
        }

        Schema::dropIfExists('patient_problem_records');
        Schema::dropIfExists('clinical_encounter_allergy_reviews');
        Schema::dropIfExists('patient_allergy_records');
        Schema::dropIfExists('patient_allergy_profile_versions');
        Schema::dropIfExists('patient_allergy_profiles');
    }

    private function addPostgresConstraints(): void
    {
        DB::statement("ALTER TABLE patient_allergy_profiles ADD CONSTRAINT allergy_profiles_status_check CHECK (status IN ('unknown', 'no_known_allergies', 'has_allergies'))");
        DB::statement('ALTER TABLE patient_allergy_profiles ADD CONSTRAINT allergy_profiles_version_positive CHECK (lock_version > 0)');
        DB::statement("ALTER TABLE patient_allergy_profiles ADD CONSTRAINT allergy_profiles_provenance_check CHECK ((status = 'unknown' AND reviewed_at IS NULL AND reviewed_by_user_id IS NULL) OR (status <> 'unknown' AND reviewed_at IS NOT NULL AND reviewed_by_user_id IS NOT NULL))");
        DB::statement("ALTER TABLE patient_allergy_profile_versions ADD CONSTRAINT allergy_profile_versions_status_check CHECK (resulting_status IN ('unknown', 'no_known_allergies', 'has_allergies'))");
        DB::statement('ALTER TABLE patient_allergy_profile_versions ADD CONSTRAINT allergy_profile_versions_positive CHECK (version > 0)');
        DB::statement("ALTER TABLE patient_allergy_records ADD CONSTRAINT allergy_records_status_check CHECK (status IN ('active', 'entered_in_error'))");
        DB::statement("ALTER TABLE patient_allergy_records ADD CONSTRAINT allergy_records_category_check CHECK (category IS NULL OR category IN ('medication', 'food', 'environmental', 'other'))");
        DB::statement("ALTER TABLE patient_allergy_records ADD CONSTRAINT allergy_records_severity_check CHECK (severity IS NULL OR severity IN ('mild', 'moderate', 'severe'))");
        DB::statement("ALTER TABLE patient_allergy_records ADD CONSTRAINT allergy_records_error_provenance_check CHECK ((status = 'active' AND entered_in_error_at IS NULL AND entered_in_error_by_user_id IS NULL) OR (status = 'entered_in_error' AND entered_in_error_at IS NOT NULL AND entered_in_error_by_user_id IS NOT NULL))");
        DB::statement('ALTER TABLE clinical_encounter_allergy_reviews ADD CONSTRAINT encounter_allergy_reviews_version_positive CHECK (allergy_profile_lock_version_reviewed > 0)');
        DB::statement("ALTER TABLE patient_problem_records ADD CONSTRAINT problem_records_status_check CHECK (status IN ('active', 'resolved', 'entered_in_error'))");
        DB::statement('ALTER TABLE patient_problem_records ADD CONSTRAINT problem_records_version_positive CHECK (lock_version > 0)');
        DB::statement('ALTER TABLE patient_problem_records ADD CONSTRAINT problem_records_code_pair_check CHECK ((condition_code IS NULL) = (code_system IS NULL))');
        DB::statement("ALTER TABLE patient_problem_records ADD CONSTRAINT problem_records_provenance_check CHECK ((status = 'active' AND resolved_at IS NULL AND resolved_by_user_id IS NULL AND resolved_date IS NULL AND entered_in_error_at IS NULL AND entered_in_error_by_user_id IS NULL) OR (status = 'resolved' AND resolved_at IS NOT NULL AND resolved_by_user_id IS NOT NULL AND entered_in_error_at IS NULL AND entered_in_error_by_user_id IS NULL) OR (status = 'entered_in_error' AND entered_in_error_at IS NOT NULL AND entered_in_error_by_user_id IS NOT NULL AND ((resolved_at IS NULL AND resolved_by_user_id IS NULL AND resolved_date IS NULL) OR (resolved_at IS NOT NULL AND resolved_by_user_id IS NOT NULL))))");
        DB::statement('ALTER TABLE patient_problem_records ADD CONSTRAINT problem_records_dates_check CHECK (onset_date IS NULL OR resolved_date IS NULL OR onset_date <= resolved_date)');

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION kpone_validate_allergy_profile_consistency()
            RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE
                target_profile_id bigint;
                old_profile_id bigint;
                new_profile_id bigint;
                profile_status varchar;
                active_count bigint;
            BEGIN
                IF TG_TABLE_NAME = 'patient_allergy_profiles' THEN
                    new_profile_id := NEW.id;
                ELSIF TG_OP = 'DELETE' THEN
                    old_profile_id := OLD.patient_allergy_profile_id;
                ELSIF TG_OP = 'INSERT' THEN
                    new_profile_id := NEW.patient_allergy_profile_id;
                ELSE
                    old_profile_id := OLD.patient_allergy_profile_id;
                    new_profile_id := NEW.patient_allergy_profile_id;
                END IF;

                FOR target_profile_id IN
                    SELECT candidate.profile_id
                    FROM (VALUES (old_profile_id), (new_profile_id)) AS candidate(profile_id)
                    WHERE candidate.profile_id IS NOT NULL
                    GROUP BY candidate.profile_id
                    ORDER BY candidate.profile_id
                LOOP
                    SELECT status INTO profile_status
                    FROM patient_allergy_profiles
                    WHERE id = target_profile_id
                    FOR UPDATE;

                    IF FOUND THEN
                        SELECT count(*) INTO active_count
                        FROM patient_allergy_records
                        WHERE patient_allergy_profile_id = target_profile_id
                          AND status = 'active';

                        IF (profile_status = 'has_allergies' AND active_count = 0)
                            OR (profile_status IN ('unknown', 'no_known_allergies') AND active_count <> 0) THEN
                            RAISE EXCEPTION 'Patient Allergy Profile status is inconsistent with active Allergy Records.';
                        END IF;
                    END IF;
                END LOOP;

                RETURN NULL;
            END;
            $$;

            CREATE CONSTRAINT TRIGGER allergy_profiles_consistency_trigger
            AFTER INSERT OR UPDATE ON patient_allergy_profiles
            DEFERRABLE INITIALLY DEFERRED
            FOR EACH ROW EXECUTE FUNCTION kpone_validate_allergy_profile_consistency();

            CREATE CONSTRAINT TRIGGER allergy_records_consistency_trigger
            AFTER INSERT OR UPDATE OR DELETE ON patient_allergy_records
            DEFERRABLE INITIALLY DEFERRED
            FOR EACH ROW EXECUTE FUNCTION kpone_validate_allergy_profile_consistency();
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION kpone_validate_encounter_allergy_review_patient()
            RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE
                encounter_patient_id bigint;
                profile_patient_id bigint;
            BEGIN
                SELECT visits.patient_id INTO encounter_patient_id
                FROM clinical_encounters
                INNER JOIN visits ON visits.id = clinical_encounters.visit_id
                WHERE clinical_encounters.id = NEW.clinical_encounter_id;

                SELECT patient_id INTO profile_patient_id
                FROM patient_allergy_profiles
                WHERE id = NEW.patient_allergy_profile_id;

                IF encounter_patient_id IS NULL OR profile_patient_id IS NULL
                    OR encounter_patient_id <> profile_patient_id THEN
                    RAISE EXCEPTION 'Encounter Allergy Review Patient does not match Allergy Profile Patient.';
                END IF;

                RETURN NULL;
            END;
            $$;

            CREATE CONSTRAINT TRIGGER encounter_allergy_reviews_patient_trigger
            AFTER INSERT OR UPDATE ON clinical_encounter_allergy_reviews
            DEFERRABLE INITIALLY DEFERRED
            FOR EACH ROW EXECUTE FUNCTION kpone_validate_encounter_allergy_review_patient();
        SQL);
    }
};
