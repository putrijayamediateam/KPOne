<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('patient_adult_confirmations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organisation_id')->constrained()->restrictOnDelete();
            $table->foreignId('patient_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('confirmed_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestampTz('confirmed_at');
            $table->string('source', 20);
            $table->unsignedInteger('patient_lock_version');
            $table->uuid('retry_key');
            $table->string('payload_hash', 64);
            $table->unique(['organisation_id', 'confirmed_by_user_id', 'retry_key'], 'patient_adult_confirmation_retry_unique');
            $table->unique(['patient_id', 'patient_lock_version']);
        });
        DB::statement('ALTER TABLE patient_adult_confirmations ADD CONSTRAINT patient_adult_confirmation_tenant_fk FOREIGN KEY (patient_id, organisation_id) REFERENCES patients(id, organisation_id) ON DELETE RESTRICT');
        DB::statement("ALTER TABLE patient_adult_confirmations ADD CONSTRAINT patient_adult_confirmation_shape CHECK (source IN ('staff_create','staff_edit') AND ((source = 'staff_create' AND patient_lock_version = 1) OR (source = 'staff_edit' AND patient_lock_version > 1)))");
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION validate_patient_adult_confirmation_insert() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM patients p JOIN users u ON u.id = NEW.confirmed_by_user_id AND u.organisation_id = p.organisation_id JOIN branches b ON b.id = NEW.branch_id AND b.organisation_id = p.organisation_id WHERE p.id = NEW.patient_id AND p.organisation_id = NEW.organisation_id AND p.date_of_birth IS NULL AND p.lock_version = NEW.patient_lock_version) THEN
        RAISE EXCEPTION 'Invalid adult confirmation source' USING ERRCODE = '23514';
    END IF;
    RETURN NEW;
END; $$;
CREATE TRIGGER patient_adult_confirmations_source BEFORE INSERT ON patient_adult_confirmations FOR EACH ROW EXECUTE FUNCTION validate_patient_adult_confirmation_insert();
CREATE OR REPLACE FUNCTION refuse_patient_adult_confirmation_mutation() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    RAISE EXCEPTION 'Adult confirmation evidence is append-only' USING ERRCODE = 'P0001';
END; $$;
CREATE TRIGGER patient_adult_confirmations_immutable BEFORE UPDATE OR DELETE OR TRUNCATE ON patient_adult_confirmations FOR EACH STATEMENT EXECUTE FUNCTION refuse_patient_adult_confirmation_mutation();
SQL);
    }

    public function down(): void
    {
        // Retain append-only confirmation evidence; no purge or historical backfill.
    }
};
