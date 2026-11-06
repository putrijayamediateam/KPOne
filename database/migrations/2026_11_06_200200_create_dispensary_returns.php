<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * DSP-7 (owner decisions 2026-11-05, TEAM.md decision 20): a CA or CA Supervisor records medicine a patient brought
 * back after a completed dispensary case, per medicine line. Recording moves no stock; a different person checks it
 * later (dispensary_return_checks, a separate migration after STK-11). Append-only. The optional "Other" note is
 * encrypted and never audited, reported or printed. The insert trigger repeats the source and quantity rules; the
 * domain's case row lock (DispensaryReturnService::record) serialises writers on one case.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dispensary_returns', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('organisation_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('dispensary_case_id')->constrained('dispensary_cases')->restrictOnDelete();
            $table->foreignId('dispensary_item_id')->constrained('dispensary_items')->restrictOnDelete();
            $table->decimal('quantity', 14, 3);
            $table->string('reason', 40);
            $table->text('note_encrypted')->nullable();
            $table->string('condition', 40);
            $table->foreignId('recorded_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestampTz('recorded_at');
            $table->uuid('retry_key');
            $table->string('payload_hash', 64);
            $table->unique(['organisation_id', 'recorded_by_user_id', 'retry_key'], 'dispensary_return_retry_once');
            $table->index(['dispensary_case_id', 'id'], 'dispensary_return_case_index');
            $table->index(['dispensary_item_id', 'id'], 'dispensary_return_item_index');
        });
        DB::statement("ALTER TABLE dispensary_returns ADD CONSTRAINT dispensary_return_shape CHECK (
            quantity > 0
            AND reason IN ('not_used', 'wrong_medicine_or_strength', 'patient_reaction', 'dispensing_error', 'other')
            AND condition IN ('sealed_in_date', 'opened_or_damaged', 'cannot_tell')
            AND ((reason = 'other') = (note_encrypted IS NOT NULL))
        )");
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION dispensary_return_open_quantity(item_id bigint) RETURNS numeric LANGUAGE sql STABLE AS $$
    SELECT COALESCE(SUM(r.quantity), 0) FROM dispensary_returns r WHERE r.dispensary_item_id = item_id
$$;
CREATE OR REPLACE FUNCTION validate_dispensary_return_insert() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE
    dispensed numeric;
BEGIN
    SELECT i.quantity_dispensed INTO dispensed
    FROM dispensary_items i
    JOIN dispensary_handoffs h ON h.id = i.dispensary_handoff_id AND h.status = 'completed'
    JOIN dispensary_cases c ON c.id = h.dispensary_case_id AND c.status = 'completed'
    JOIN users u ON u.id = NEW.recorded_by_user_id AND u.organisation_id = c.organisation_id
    WHERE i.id = NEW.dispensary_item_id
      AND c.id = NEW.dispensary_case_id
      AND c.organisation_id = NEW.organisation_id
      AND c.branch_id = NEW.branch_id
      AND i.organisation_id = NEW.organisation_id
      AND i.status IN ('dispensed', 'partial');
    IF dispensed IS NULL OR dispensed <= 0 THEN
        RAISE EXCEPTION 'Invalid dispensary return source' USING ERRCODE = '23514';
    END IF;
    IF NEW.quantity > dispensed - dispensary_return_open_quantity(NEW.dispensary_item_id) THEN
        RAISE EXCEPTION 'Dispensary return exceeds the quantity still returnable' USING ERRCODE = '23514';
    END IF;
    RETURN NEW;
END; $$;
CREATE TRIGGER dispensary_returns_source BEFORE INSERT ON dispensary_returns FOR EACH ROW EXECUTE FUNCTION validate_dispensary_return_insert();
CREATE OR REPLACE FUNCTION refuse_dispensary_return_mutation() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    RAISE EXCEPTION 'Dispensary returns are append-only' USING ERRCODE = 'P0001';
END; $$;
CREATE TRIGGER dispensary_returns_immutable BEFORE UPDATE OR DELETE OR TRUNCATE ON dispensary_returns FOR EACH STATEMENT EXECUTE FUNCTION refuse_dispensary_return_mutation();
SQL);
    }

    public function down(): void
    {
        // Retain the append-only return evidence; no purge.
    }
};
