<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * DSP-6 (owner decisions 2026-11-05): append-only log of drug label prints. One row per medicine line each time
 * "Print current labels" passes the fresh check (KPOne cannot see the printer, so a cancelled print dialog still
 * counts). Who, which line, when and the print number only; no label content and no snapshot. The trigger re-checks
 * the print number sequence, relying on the domain's case row lock (DrugLabelService::recordPrint) to serialise writers.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dispensary_label_prints', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('organisation_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('dispensary_case_id')->constrained('dispensary_cases')->restrictOnDelete();
            $table->foreignId('dispensary_item_id')->constrained('dispensary_items')->restrictOnDelete();
            $table->unsignedInteger('print_number');
            $table->foreignId('printed_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestampTz('printed_at');
            $table->unique(['dispensary_item_id', 'print_number'], 'dispensary_label_print_once');
            $table->index(['dispensary_case_id', 'id'], 'dispensary_label_print_case_index');
        });
        DB::statement('ALTER TABLE dispensary_label_prints ADD CONSTRAINT dispensary_label_print_number CHECK (print_number >= 1)');
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION validate_dispensary_label_print_insert() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM dispensary_items i
        JOIN dispensary_handoffs h ON h.id = i.dispensary_handoff_id
        JOIN dispensary_cases c ON c.id = h.dispensary_case_id
        JOIN users u ON u.id = NEW.printed_by_user_id AND u.organisation_id = c.organisation_id
        WHERE i.id = NEW.dispensary_item_id
          AND c.id = NEW.dispensary_case_id
          AND c.organisation_id = NEW.organisation_id
          AND c.branch_id = NEW.branch_id
          AND i.organisation_id = NEW.organisation_id
    ) OR NEW.print_number <> 1 + COALESCE((SELECT MAX(p.print_number) FROM dispensary_label_prints p WHERE p.dispensary_item_id = NEW.dispensary_item_id), 0) THEN
        RAISE EXCEPTION 'Invalid dispensary label print source' USING ERRCODE = '23514';
    END IF;
    RETURN NEW;
END; $$;
CREATE TRIGGER dispensary_label_prints_source BEFORE INSERT ON dispensary_label_prints FOR EACH ROW EXECUTE FUNCTION validate_dispensary_label_print_insert();
CREATE OR REPLACE FUNCTION refuse_dispensary_label_print_mutation() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    RAISE EXCEPTION 'Dispensary label print log is append-only' USING ERRCODE = 'P0001';
END; $$;
CREATE TRIGGER dispensary_label_prints_immutable BEFORE UPDATE OR DELETE OR TRUNCATE ON dispensary_label_prints FOR EACH STATEMENT EXECUTE FUNCTION refuse_dispensary_label_print_mutation();
SQL);
    }

    public function down(): void
    {
        // Retain the append-only print log; no purge.
    }
};
