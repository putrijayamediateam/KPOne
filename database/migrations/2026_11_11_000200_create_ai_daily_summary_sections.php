<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * AG-8: the stored results of the 07:00 and 14:00 agent runs (contract section 14), so the daily summary page
 * loads without recomputing. One row per organisation, branch, local date, slot and section; Faris's
 * organisation-wide line has no branch. A row comes only from a completed run of the section's own agent and task (one section per run), holds
 * aggregates only (encrypted, with a hash) and never changes or goes away.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_daily_summary_sections', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('organisation_id')->constrained()->restrictOnDelete();
            $t->foreignId('branch_id')->nullable()->constrained()->restrictOnDelete();
            $t->date('local_date');
            $t->string('slot', 4);
            $t->string('section', 10);
            $t->foreignId('ai_agent_run_id')->constrained('ai_agent_runs')->restrictOnDelete();
            $t->text('payload');
            $t->char('payload_hash', 64);
            $t->timestampTz('created_at')->useCurrent();
            $t->index(['organisation_id', 'local_date', 'branch_id']);
        });

        DB::unprepared(<<<'SQL'
ALTER TABLE ai_daily_summary_sections ADD CONSTRAINT ai_daily_summary_sections_slot_check CHECK (slot IN ('0700', '1400'));
ALTER TABLE ai_daily_summary_sections ADD CONSTRAINT ai_daily_summary_sections_section_check CHECK (section IN ('lina', 'hana', 'rizal', 'faris'));
ALTER TABLE ai_daily_summary_sections ADD CONSTRAINT ai_daily_summary_sections_branch_check CHECK ((section = 'faris') = (branch_id IS NULL));
ALTER TABLE ai_daily_summary_sections ADD CONSTRAINT ai_daily_summary_sections_hash_check CHECK (payload_hash ~ '^[0-9a-f]{64}$');
CREATE UNIQUE INDEX ai_daily_summary_sections_branch_unique ON ai_daily_summary_sections (organisation_id, branch_id, local_date, slot, section) WHERE branch_id IS NOT NULL;
CREATE UNIQUE INDEX ai_daily_summary_sections_run_unique ON ai_daily_summary_sections (ai_agent_run_id);
CREATE UNIQUE INDEX ai_daily_summary_sections_org_unique ON ai_daily_summary_sections (organisation_id, local_date, slot, section) WHERE branch_id IS NULL;

CREATE OR REPLACE FUNCTION kp_ai_daily_summary_section_guard() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE
    run_row ai_agent_runs%ROWTYPE;
    agent_code text;
    expected_code text;
BEGIN
    IF TG_OP <> 'INSERT' THEN
        RAISE EXCEPTION 'Daily summary sections never change' USING ERRCODE = 'P0001';
    END IF;
    SELECT * INTO run_row FROM ai_agent_runs WHERE id = NEW.ai_agent_run_id;
    IF run_row.id IS NULL OR run_row.organisation_id <> NEW.organisation_id OR run_row.branch_id IS DISTINCT FROM NEW.branch_id THEN
        RAISE EXCEPTION 'Daily summary section scope mismatch' USING ERRCODE = '23514';
    END IF;
    IF run_row.status <> 'completed' THEN
        RAISE EXCEPTION 'A daily summary section comes from a completed run' USING ERRCODE = '23514';
    END IF;
    SELECT code INTO agent_code FROM ai_agents WHERE id = run_row.ai_agent_id;
    expected_code := CASE NEW.section WHEN 'lina' THEN 'report_assistant' WHEN 'hana' THEN 'inventory_analyst'
        WHEN 'rizal' THEN 'stocktake_officer' WHEN 'faris' THEN 'technical_admin_agent' END;
    IF agent_code IS DISTINCT FROM expected_code THEN
        RAISE EXCEPTION 'A daily summary section comes from its own agent' USING ERRCODE = '23514';
    END IF;
    expected_code := CASE NEW.section WHEN 'lina' THEN 'daily_report' WHEN 'hana' THEN 'inventory_review'
        WHEN 'rizal' THEN 'stock_count_check' WHEN 'faris' THEN 'system_health' END;
    IF run_row.task_type IS DISTINCT FROM expected_code THEN
        RAISE EXCEPTION 'A daily summary section comes from its agent''s own task' USING ERRCODE = '23514';
    END IF;
    RETURN NEW;
END;
$$;
CREATE OR REPLACE FUNCTION kp_ai_daily_summary_section_no_truncate() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    RAISE EXCEPTION 'Daily summary sections never change' USING ERRCODE = 'P0001';
END;
$$;
CREATE TRIGGER ai_daily_summary_sections_guard BEFORE INSERT OR UPDATE OR DELETE ON ai_daily_summary_sections FOR EACH ROW EXECUTE FUNCTION kp_ai_daily_summary_section_guard();
CREATE TRIGGER ai_daily_summary_sections_no_truncate BEFORE TRUNCATE ON ai_daily_summary_sections FOR EACH STATEMENT EXECUTE FUNCTION kp_ai_daily_summary_section_no_truncate();
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_daily_summary_sections');
        DB::unprepared('DROP FUNCTION IF EXISTS kp_ai_daily_summary_section_guard() CASCADE; DROP FUNCTION IF EXISTS kp_ai_daily_summary_section_no_truncate() CASCADE;');
    }
};
