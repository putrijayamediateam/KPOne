<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * DSP-1: append-only evidence that the CA confirmed a possible allergy match before saving a
 * medicine line at Dispensary. One row per matched allergy record per save. No medicine name,
 * allergy text or other clinical text is stored here; the rows point at the existing records.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dispensary_allergy_confirmations', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('organisation_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('dispensary_case_id')->constrained('dispensary_cases')->restrictOnDelete();
            $table->foreignId('dispensary_handoff_id')->constrained('dispensary_handoffs')->restrictOnDelete();
            $table->foreignId('dispensary_item_id')->constrained('dispensary_items')->restrictOnDelete();
            $table->foreignId('patient_allergy_record_id')->constrained('patient_allergy_records')->restrictOnDelete();
            $table->unsignedInteger('allergy_profile_lock_version');
            $table->string('action', 10);
            $table->unsignedInteger('item_lock_version');
            $table->foreignId('confirmed_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestampTz('confirmed_at');
            $table->unique(['dispensary_item_id', 'item_lock_version', 'patient_allergy_record_id'], 'dispensary_allergy_confirmation_once');
            $table->index(['dispensary_case_id', 'id'], 'dispensary_allergy_confirmation_case_index');
        });
        DB::statement("ALTER TABLE dispensary_allergy_confirmations ADD CONSTRAINT dispensary_allergy_confirmation_shape CHECK (action IN ('add','edit') AND item_lock_version >= 1)");
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION validate_dispensary_allergy_confirmation_insert() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM dispensary_items i
        JOIN dispensary_handoffs h ON h.id = i.dispensary_handoff_id
        JOIN dispensary_cases c ON c.id = h.dispensary_case_id
        JOIN visits v ON v.id = c.visit_id
        JOIN patient_allergy_profiles p ON p.patient_id = v.patient_id AND p.organisation_id = c.organisation_id
        JOIN patient_allergy_records r ON r.patient_allergy_profile_id = p.id AND r.organisation_id = c.organisation_id
        JOIN users u ON u.id = NEW.confirmed_by_user_id AND u.organisation_id = c.organisation_id
        WHERE i.id = NEW.dispensary_item_id
          AND h.id = NEW.dispensary_handoff_id
          AND c.id = NEW.dispensary_case_id
          AND r.id = NEW.patient_allergy_record_id
          AND r.status = 'active'
          AND c.organisation_id = NEW.organisation_id
          AND c.branch_id = NEW.branch_id
          AND i.organisation_id = NEW.organisation_id
          AND i.branch_id = NEW.branch_id
          AND i.lock_version = NEW.item_lock_version
          AND p.lock_version = NEW.allergy_profile_lock_version
    ) THEN
        RAISE EXCEPTION 'Invalid dispensary allergy confirmation source' USING ERRCODE = '23514';
    END IF;
    RETURN NEW;
END; $$;
CREATE TRIGGER dispensary_allergy_confirmations_source BEFORE INSERT ON dispensary_allergy_confirmations FOR EACH ROW EXECUTE FUNCTION validate_dispensary_allergy_confirmation_insert();
CREATE OR REPLACE FUNCTION refuse_dispensary_allergy_confirmation_mutation() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    RAISE EXCEPTION 'Dispensary allergy confirmation evidence is append-only' USING ERRCODE = 'P0001';
END; $$;
CREATE TRIGGER dispensary_allergy_confirmations_immutable BEFORE UPDATE OR DELETE OR TRUNCATE ON dispensary_allergy_confirmations FOR EACH STATEMENT EXECUTE FUNCTION refuse_dispensary_allergy_confirmation_mutation();
SQL);
    }

    public function down(): void
    {
        // Retain append-only confirmation evidence; no purge or historical backfill.
    }
};
