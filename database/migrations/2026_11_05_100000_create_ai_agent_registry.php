<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * AG-1: agent registry and activity log (docs/agents/CONTRACT.md sections 2 and 3).
 * Agents are never deleted (disabled instead); run steps and agent messages are append-only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_agents', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('organisation_id')->constrained()->restrictOnDelete();
            $t->string('code', 60);
            $t->string('display_name', 80);
            $t->string('job_title', 120);
            $t->foreignId('department_id')->nullable()->constrained()->restrictOnDelete();
            $t->string('kind', 20);
            $t->string('avatar_key', 60)->nullable();
            $t->boolean('is_enabled')->default(true);
            $t->unsignedInteger('lock_version')->default(0);
            $t->timestampsTz();
            $t->unique(['organisation_id', 'code']);
        });

        Schema::create('ai_agent_tools', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('ai_agent_id')->constrained('ai_agents')->restrictOnDelete();
            $t->string('tool_code', 80);
            $t->timestampsTz();
            $t->unique(['ai_agent_id', 'tool_code']);
        });

        Schema::create('ai_agent_runs', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('organisation_id')->constrained()->restrictOnDelete();
            $t->foreignId('branch_id')->nullable()->constrained()->restrictOnDelete();
            $t->foreignId('ai_agent_id')->constrained('ai_agents')->restrictOnDelete();
            $t->foreignId('requested_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $t->string('task_type', 60);
            $t->string('status', 30);
            $t->timestampTz('started_at')->nullable();
            $t->timestampTz('finished_at')->nullable();
            $t->string('last_step_summary', 200)->nullable();
            $t->string('error_code', 60)->nullable();
            $t->string('idempotency_key', 100)->nullable();
            $t->timestampsTz();
            $t->unique(['organisation_id', 'idempotency_key']);
            $t->index(['ai_agent_id', 'id']);
            $t->index(['organisation_id', 'status']);
        });

        Schema::create('ai_agent_run_steps', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('ai_agent_run_id')->constrained('ai_agent_runs')->restrictOnDelete();
            $t->unsignedInteger('sequence');
            $t->string('kind', 20);
            $t->string('tool_code', 80)->nullable();
            $t->string('summary', 200);
            $t->timestampTz('created_at');
            $t->unique(['ai_agent_run_id', 'sequence']);
        });

        Schema::create('ai_agent_messages', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('ai_agent_run_id')->constrained('ai_agent_runs')->restrictOnDelete();
            $t->foreignId('from_agent_id')->constrained('ai_agents')->restrictOnDelete();
            $t->foreignId('to_agent_id')->constrained('ai_agents')->restrictOnDelete();
            // Encrypted by the application (Laravel encrypted cast); plaintext at most 2,000 characters.
            $t->text('body');
            $t->timestampTz('created_at');
            $t->index(['ai_agent_run_id', 'id']);
        });

        DB::unprepared(<<<'SQL'
ALTER TABLE ai_agents ADD CONSTRAINT ai_agents_code_check CHECK (code ~ '^[a-z][a-z0-9_]{1,59}$');
ALTER TABLE ai_agents ADD CONSTRAINT ai_agents_kind_check CHECK (kind IN ('build', 'runtime'));
ALTER TABLE ai_agents ADD CONSTRAINT ai_agents_ai_label_check CHECK (position('(AI agent)' IN job_title) > 0);
ALTER TABLE ai_agents ADD CONSTRAINT ai_agents_lock_version_check CHECK (lock_version >= 0);
ALTER TABLE ai_agents ADD CONSTRAINT ai_agents_text_check CHECK (length(btrim(display_name)) > 0 AND length(btrim(job_title)) > 0);
ALTER TABLE ai_agent_tools ADD CONSTRAINT ai_agent_tools_code_check CHECK (tool_code ~ '^[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*)+$');
ALTER TABLE ai_agent_runs ADD CONSTRAINT ai_agent_runs_status_check CHECK (status IN ('queued', 'working', 'awaiting_approval', 'completed', 'failed', 'cancelled'));
ALTER TABLE ai_agent_runs ADD CONSTRAINT ai_agent_runs_task_type_check CHECK (task_type ~ '^[a-z][a-z0-9_]{1,59}$');
ALTER TABLE ai_agent_runs ADD CONSTRAINT ai_agent_runs_error_code_check CHECK (error_code IS NULL OR error_code ~ '^[a-z][a-z0-9_.]{0,59}$');
ALTER TABLE ai_agent_runs ADD CONSTRAINT ai_agent_runs_lifecycle_check CHECK (
    (status = 'queued' AND started_at IS NULL AND finished_at IS NULL)
    OR (status IN ('working', 'awaiting_approval') AND started_at IS NOT NULL AND finished_at IS NULL)
    OR (status IN ('completed', 'failed') AND started_at IS NOT NULL AND finished_at IS NOT NULL)
    OR (status = 'cancelled' AND finished_at IS NOT NULL)
);
ALTER TABLE ai_agent_runs ADD CONSTRAINT ai_agent_runs_time_order_check CHECK (finished_at IS NULL OR started_at IS NULL OR finished_at >= started_at);
ALTER TABLE ai_agent_runs ADD CONSTRAINT ai_agent_runs_error_check CHECK ((status = 'failed') = (error_code IS NOT NULL));
ALTER TABLE ai_agent_run_steps ADD CONSTRAINT ai_agent_run_steps_kind_check CHECK (kind IN ('data_read', 'decision', 'note') AND sequence > 0 AND length(btrim(summary)) > 0);
ALTER TABLE ai_agent_run_steps ADD CONSTRAINT ai_agent_run_steps_tool_check CHECK ((kind = 'data_read') = (tool_code IS NOT NULL));

CREATE OR REPLACE FUNCTION kp_ai_append_only() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    RAISE EXCEPTION 'Agent activity evidence is append-only' USING ERRCODE = 'P0001';
END $$;

CREATE OR REPLACE FUNCTION kp_ai_agent_never_deleted() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    RAISE EXCEPTION 'Agents are never deleted; disable them instead' USING ERRCODE = 'P0001';
END $$;

CREATE OR REPLACE FUNCTION kp_ai_agent_identity() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF NEW.organisation_id IS DISTINCT FROM OLD.organisation_id OR NEW.code IS DISTINCT FROM OLD.code THEN
        RAISE EXCEPTION 'An agent keeps its organisation and code' USING ERRCODE = 'P0001';
    END IF;
    RETURN NEW;
END $$;

CREATE OR REPLACE FUNCTION kp_ai_agent_run_scope() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM ai_agents a WHERE a.id = NEW.ai_agent_id AND a.organisation_id = NEW.organisation_id) THEN
        RAISE EXCEPTION 'Agent run organisation mismatch' USING ERRCODE = '23514';
    END IF;
    IF NEW.branch_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM branches b WHERE b.id = NEW.branch_id AND b.organisation_id = NEW.organisation_id) THEN
        RAISE EXCEPTION 'Agent run branch mismatch' USING ERRCODE = '23514';
    END IF;
    IF NEW.requested_by_user_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM users u WHERE u.id = NEW.requested_by_user_id AND u.organisation_id = NEW.organisation_id) THEN
        RAISE EXCEPTION 'Agent run requester mismatch' USING ERRCODE = '23514';
    END IF;
    IF TG_OP = 'UPDATE' THEN
        IF NEW.organisation_id IS DISTINCT FROM OLD.organisation_id OR NEW.branch_id IS DISTINCT FROM OLD.branch_id
            OR NEW.ai_agent_id IS DISTINCT FROM OLD.ai_agent_id OR NEW.requested_by_user_id IS DISTINCT FROM OLD.requested_by_user_id
            OR NEW.task_type IS DISTINCT FROM OLD.task_type OR NEW.idempotency_key IS DISTINCT FROM OLD.idempotency_key THEN
            RAISE EXCEPTION 'An agent run keeps its identity' USING ERRCODE = 'P0001';
        END IF;
        IF OLD.status IN ('completed', 'failed', 'cancelled') THEN
            RAISE EXCEPTION 'A finished agent run cannot change' USING ERRCODE = 'P0001';
        END IF;
    END IF;
    RETURN NEW;
END $$;

CREATE OR REPLACE FUNCTION kp_ai_agent_message_scope() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM ai_agent_runs r
        JOIN ai_agents f ON f.id = NEW.from_agent_id AND f.id = r.ai_agent_id
        JOIN ai_agents t ON t.id = NEW.to_agent_id AND t.organisation_id = r.organisation_id
        WHERE r.id = NEW.ai_agent_run_id
    ) THEN
        RAISE EXCEPTION 'Agent message must come from the run''s agent to an agent of its organisation' USING ERRCODE = '23514';
    END IF;
    RETURN NEW;
END $$;

CREATE OR REPLACE FUNCTION kp_ai_agent_run_open() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM ai_agent_runs r WHERE r.id = NEW.ai_agent_run_id AND r.status IN ('working', 'awaiting_approval')) THEN
        RAISE EXCEPTION 'Steps and messages need a started, unfinished run' USING ERRCODE = 'P0001';
    END IF;
    RETURN NEW;
END $$;

CREATE TRIGGER ai_agents_never_deleted BEFORE DELETE ON ai_agents FOR EACH ROW EXECUTE FUNCTION kp_ai_agent_never_deleted();
CREATE TRIGGER ai_agents_never_truncated BEFORE TRUNCATE ON ai_agents FOR EACH STATEMENT EXECUTE FUNCTION kp_ai_agent_never_deleted();
CREATE TRIGGER ai_agents_identity BEFORE UPDATE ON ai_agents FOR EACH ROW EXECUTE FUNCTION kp_ai_agent_identity();
CREATE TRIGGER ai_agent_runs_scope BEFORE INSERT OR UPDATE ON ai_agent_runs FOR EACH ROW EXECUTE FUNCTION kp_ai_agent_run_scope();
CREATE TRIGGER ai_agent_runs_no_delete BEFORE DELETE ON ai_agent_runs FOR EACH ROW EXECUTE FUNCTION kp_ai_append_only();
CREATE TRIGGER ai_agent_runs_no_truncate BEFORE TRUNCATE ON ai_agent_runs FOR EACH STATEMENT EXECUTE FUNCTION kp_ai_append_only();
CREATE TRIGGER ai_agent_messages_scope BEFORE INSERT ON ai_agent_messages FOR EACH ROW EXECUTE FUNCTION kp_ai_agent_message_scope();
CREATE TRIGGER ai_agent_messages_open_run BEFORE INSERT ON ai_agent_messages FOR EACH ROW EXECUTE FUNCTION kp_ai_agent_run_open();
CREATE TRIGGER ai_agent_run_steps_open_run BEFORE INSERT ON ai_agent_run_steps FOR EACH ROW EXECUTE FUNCTION kp_ai_agent_run_open();
SQL);

        foreach (['ai_agent_run_steps', 'ai_agent_messages'] as $table) {
            DB::unprepared("CREATE TRIGGER {$table}_append_only BEFORE UPDATE OR DELETE ON {$table} FOR EACH ROW EXECUTE FUNCTION kp_ai_append_only(); CREATE TRIGGER {$table}_no_truncate BEFORE TRUNCATE ON {$table} FOR EACH STATEMENT EXECUTE FUNCTION kp_ai_append_only();");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_agent_messages');
        Schema::dropIfExists('ai_agent_run_steps');
        Schema::dropIfExists('ai_agent_runs');
        Schema::dropIfExists('ai_agent_tools');
        Schema::dropIfExists('ai_agents');
        DB::unprepared('DROP FUNCTION IF EXISTS kp_ai_append_only() CASCADE; DROP FUNCTION IF EXISTS kp_ai_agent_never_deleted() CASCADE; DROP FUNCTION IF EXISTS kp_ai_agent_identity() CASCADE; DROP FUNCTION IF EXISTS kp_ai_agent_run_scope() CASCADE; DROP FUNCTION IF EXISTS kp_ai_agent_message_scope() CASCADE; DROP FUNCTION IF EXISTS kp_ai_agent_run_open() CASCADE;');
    }
};
