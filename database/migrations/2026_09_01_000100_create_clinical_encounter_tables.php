<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clinical_encounters', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organisation_id');
            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('visit_id');
            $table->unsignedBigInteger('attending_clinician_user_id');
            $table->string('status', 32);
            $table->text('clinical_note')->nullable();
            $table->timestamp('started_at');
            $table->unsignedBigInteger('updated_by_user_id');
            $table->unsignedInteger('lock_version')->default(1);
            $table->timestamps();

            $table->foreign(
                ['visit_id', 'organisation_id', 'branch_id'],
                'clinical_encounters_visit_organisation_branch_fk',
            )->references(['id', 'organisation_id', 'branch_id'])->on('visits')->restrictOnDelete();
            $table->foreign(
                ['attending_clinician_user_id', 'organisation_id'],
                'clinical_encounters_clinician_organisation_fk',
            )->references(['id', 'organisation_id'])->on('users')->restrictOnDelete();
            $table->foreign(
                ['updated_by_user_id', 'organisation_id'],
                'clinical_encounters_updated_by_organisation_fk',
            )->references(['id', 'organisation_id'])->on('users')->restrictOnDelete();

            $table->unique('visit_id');
            $table->unique(
                ['id', 'organisation_id', 'branch_id'],
                'clinical_encounters_id_organisation_branch_unique',
            );
            $table->index(
                ['organisation_id', 'branch_id', 'status', 'started_at'],
                'clinical_encounters_branch_status_index',
            );
        });

        Schema::create('encounter_vital_observations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organisation_id');
            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('clinical_encounter_id');
            $table->timestamp('observed_at')->nullable();
            $table->unsignedSmallInteger('systolic_bp')->nullable();
            $table->unsignedSmallInteger('diastolic_bp')->nullable();
            $table->unsignedSmallInteger('pulse_bpm')->nullable();
            $table->decimal('temperature_celsius', 5, 2)->nullable();
            $table->decimal('spo2_percent', 5, 2)->nullable();
            $table->decimal('weight_kg', 7, 2)->nullable();
            $table->decimal('height_cm', 6, 2)->nullable();
            $table->unsignedBigInteger('recorded_by_user_id');
            $table->unsignedBigInteger('updated_by_user_id');
            $table->timestamps();

            $table->foreign(
                ['clinical_encounter_id', 'organisation_id', 'branch_id'],
                'encounter_vitals_encounter_organisation_branch_fk',
            )->references(['id', 'organisation_id', 'branch_id'])->on('clinical_encounters')->restrictOnDelete();
            $table->foreign(
                ['recorded_by_user_id', 'organisation_id'],
                'encounter_vitals_recorded_by_organisation_fk',
            )->references(['id', 'organisation_id'])->on('users')->restrictOnDelete();
            $table->foreign(
                ['updated_by_user_id', 'organisation_id'],
                'encounter_vitals_updated_by_organisation_fk',
            )->references(['id', 'organisation_id'])->on('users')->restrictOnDelete();

            $table->unique('clinical_encounter_id');
        });

        Schema::create('encounter_diagnoses', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organisation_id');
            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('clinical_encounter_id');
            $table->string('diagnosis_text', 500);
            $table->string('diagnosis_code', 50)->nullable();
            $table->string('code_system', 50)->nullable();
            $table->boolean('is_primary')->default(false);
            $table->unsignedSmallInteger('position');
            $table->unsignedBigInteger('recorded_by_user_id');
            $table->unsignedBigInteger('updated_by_user_id');
            $table->timestamps();

            $table->foreign(
                ['clinical_encounter_id', 'organisation_id', 'branch_id'],
                'encounter_diagnoses_encounter_organisation_branch_fk',
            )->references(['id', 'organisation_id', 'branch_id'])->on('clinical_encounters')->restrictOnDelete();
            $table->foreign(
                ['recorded_by_user_id', 'organisation_id'],
                'encounter_diagnoses_recorded_by_organisation_fk',
            )->references(['id', 'organisation_id'])->on('users')->restrictOnDelete();
            $table->foreign(
                ['updated_by_user_id', 'organisation_id'],
                'encounter_diagnoses_updated_by_organisation_fk',
            )->references(['id', 'organisation_id'])->on('users')->restrictOnDelete();

            $table->unique(
                ['clinical_encounter_id', 'position'],
                'encounter_diagnoses_position_unique',
            );
        });

        $driver = DB::connection()->getDriverName();
        if ($driver === 'pgsql') {
            DB::statement("ALTER TABLE clinical_encounters ADD CONSTRAINT clinical_encounters_status_check CHECK (status IN ('in_progress'))");
            DB::statement('ALTER TABLE clinical_encounters ADD CONSTRAINT clinical_encounters_lock_version_positive CHECK (lock_version > 0)');
            DB::statement('ALTER TABLE encounter_vital_observations ADD CONSTRAINT encounter_vitals_bp_pair_check CHECK ((systolic_bp IS NULL) = (diastolic_bp IS NULL))');
            DB::statement('ALTER TABLE encounter_vital_observations ADD CONSTRAINT encounter_vitals_positive_check CHECK ((systolic_bp IS NULL OR systolic_bp > 0) AND (diastolic_bp IS NULL OR diastolic_bp > 0) AND (pulse_bpm IS NULL OR pulse_bpm > 0) AND (temperature_celsius IS NULL OR temperature_celsius > 0) AND (weight_kg IS NULL OR weight_kg > 0) AND (height_cm IS NULL OR height_cm > 0))');
            DB::statement('ALTER TABLE encounter_vital_observations ADD CONSTRAINT encounter_vitals_spo2_check CHECK (spo2_percent IS NULL OR (spo2_percent >= 0 AND spo2_percent <= 100))');
            DB::statement('ALTER TABLE encounter_diagnoses ADD CONSTRAINT encounter_diagnoses_code_pair_check CHECK ((diagnosis_code IS NULL) = (code_system IS NULL))');
            DB::statement('ALTER TABLE encounter_diagnoses ADD CONSTRAINT encounter_diagnoses_position_positive CHECK (position > 0)');
            DB::statement('CREATE UNIQUE INDEX encounter_diagnoses_one_primary_unique ON encounter_diagnoses (clinical_encounter_id) WHERE is_primary = true');
        } elseif ($driver === 'sqlite') {
            DB::statement('CREATE UNIQUE INDEX encounter_diagnoses_one_primary_unique ON encounter_diagnoses (clinical_encounter_id) WHERE is_primary = 1');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('encounter_diagnoses');
        Schema::dropIfExists('encounter_vital_observations');
        Schema::dropIfExists('clinical_encounters');
    }
};
