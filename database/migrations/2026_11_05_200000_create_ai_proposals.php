<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * AG-2: the approval inbox (docs/agents/CONTRACT.md sections 2, 3 and 6). Agents propose, people decide.
 * A decided, withdrawn or expired proposal never changes; `executed` and `execution_failed` are reserved
 * for a later execution step and refused here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_proposals', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('organisation_id')->constrained()->restrictOnDelete();
            $t->foreignId('branch_id')->constrained()->restrictOnDelete();
            $t->foreignId('ai_agent_run_id')->constrained('ai_agent_runs')->restrictOnDelete();
            $t->foreignId('ai_agent_id')->constrained('ai_agents')->restrictOnDelete();
            $t->string('type', 60);
            // Encrypted JSON (Laravel encrypted cast); the hash is over the canonical plaintext JSON.
            $t->text('payload');
            $t->char('payload_hash', 64);
            $t->string('status', 20)->default('pending');
            $t->foreignId('decided_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestampTz('decided_at')->nullable();
            $t->string('decision_reason', 500)->nullable();
            $t->unsignedInteger('lock_version')->default(0);
            $t->string('idempotency_key', 100);
            $t->timestampsTz();
            $t->unique(['organisation_id', 'idempotency_key']);
            $t->index(['organisation_id', 'branch_id', 'status', 'id']);
            $t->index(['ai_agent_id', 'status']);
        });

        DB::unprepared(<<<'SQL'
ALTER TABLE ai_proposals ADD CONSTRAINT ai_proposals_type_check CHECK (type IN ('inventory.restock_suggestion', 'inventory.expiry_warning', 'inventory.transfer_suggestion', 'inventory.slow_moving'));
ALTER TABLE ai_proposals ADD CONSTRAINT ai_proposals_status_check CHECK (status IN ('pending', 'approved', 'rejected', 'withdrawn', 'expired'));
ALTER TABLE ai_proposals ADD CONSTRAINT ai_proposals_hash_check CHECK (payload_hash ~ '^[0-9a-f]{64}$' AND lock_version >= 0);
ALTER TABLE ai_proposals ADD CONSTRAINT ai_proposals_decision_check CHECK (
    (status = 'pending' AND decided_at IS NULL AND decided_by_user_id IS NULL AND decision_reason IS NULL)
    OR (status IN ('approved', 'rejected') AND decided_at IS NOT NULL AND decided_by_user_id IS NOT NULL)
    OR (status IN ('withdrawn', 'expired') AND decided_at IS NOT NULL AND decided_by_user_id IS NULL AND decision_reason IS NULL)
);
ALTER TABLE ai_proposals ADD CONSTRAINT ai_proposals_reject_reason_check CHECK (status <> 'rejected' OR length(btrim(coalesce(decision_reason, ''))) > 0);

CREATE OR REPLACE FUNCTION kp_ai_proposal_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF TG_OP = 'DELETE' THEN
        RAISE EXCEPTION 'Agent proposals are never deleted' USING ERRCODE = 'P0001';
    END IF;
    IF NOT EXISTS (
        SELECT 1 FROM ai_agent_runs r
        JOIN ai_agents a ON a.id = r.ai_agent_id
        JOIN branches b ON b.id = NEW.branch_id
        WHERE r.id = NEW.ai_agent_run_id AND r.ai_agent_id = NEW.ai_agent_id AND (r.branch_id IS NULL OR r.branch_id = NEW.branch_id)
          AND r.organisation_id = NEW.organisation_id AND a.organisation_id = NEW.organisation_id AND b.organisation_id = NEW.organisation_id
    ) THEN
        RAISE EXCEPTION 'Agent proposal scope mismatch' USING ERRCODE = '23514';
    END IF;
    IF NEW.decided_by_user_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM users u WHERE u.id = NEW.decided_by_user_id AND u.organisation_id = NEW.organisation_id) THEN
        RAISE EXCEPTION 'Agent proposal decider mismatch' USING ERRCODE = '23514';
    END IF;
    IF TG_OP = 'UPDATE' THEN
        IF OLD.status <> 'pending' THEN
            RAISE EXCEPTION 'A decided agent proposal cannot change' USING ERRCODE = 'P0001';
        END IF;
        IF NEW.organisation_id IS DISTINCT FROM OLD.organisation_id OR NEW.branch_id IS DISTINCT FROM OLD.branch_id
            OR NEW.ai_agent_run_id IS DISTINCT FROM OLD.ai_agent_run_id OR NEW.ai_agent_id IS DISTINCT FROM OLD.ai_agent_id
            OR NEW.type IS DISTINCT FROM OLD.type OR NEW.payload IS DISTINCT FROM OLD.payload OR NEW.payload_hash IS DISTINCT FROM OLD.payload_hash
            OR NEW.idempotency_key IS DISTINCT FROM OLD.idempotency_key OR NEW.created_at IS DISTINCT FROM OLD.created_at THEN
            RAISE EXCEPTION 'An agent proposal keeps what was proposed' USING ERRCODE = 'P0001';
        END IF;
        IF NEW.lock_version <= OLD.lock_version THEN
            RAISE EXCEPTION 'An agent proposal change must advance its version' USING ERRCODE = 'P0001';
        END IF;
    END IF;
    RETURN NEW;
END $$;

CREATE OR REPLACE FUNCTION kp_ai_proposal_no_truncate() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    RAISE EXCEPTION 'Agent proposals are never deleted' USING ERRCODE = 'P0001';
END $$;

CREATE TRIGGER ai_proposals_guard BEFORE INSERT OR UPDATE OR DELETE ON ai_proposals FOR EACH ROW EXECUTE FUNCTION kp_ai_proposal_guard();
CREATE TRIGGER ai_proposals_no_truncate BEFORE TRUNCATE ON ai_proposals FOR EACH STATEMENT EXECUTE FUNCTION kp_ai_proposal_no_truncate();
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_proposals');
        DB::unprepared('DROP FUNCTION IF EXISTS kp_ai_proposal_guard() CASCADE; DROP FUNCTION IF EXISTS kp_ai_proposal_no_truncate() CASCADE;');
    }
};
