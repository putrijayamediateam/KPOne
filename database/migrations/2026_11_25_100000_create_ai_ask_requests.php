<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * CHAT-2 (docs/agents/CHAT-2-ASK-THROUGH-CLAUDE-DESIGN.md section 4): questions asked in the KPOne panel and answered through the
 * Director's own Claude. The question and answer are encrypted by the model. A request leaves `pending` exactly once (answered,
 * expired or cancelled); only the retention purge may then empty the text, once; rows are never deleted or truncated.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_ask_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organisation_id')->constrained('organisations');
            $table->foreignId('user_id')->constrained('users');
            $table->string('request_code', 12)->unique();
            $table->string('request_key', 64)->nullable();
            $table->text('question')->nullable();
            $table->text('answer')->nullable();
            $table->unsignedSmallInteger('question_chars');
            $table->string('status', 12);
            $table->timestampTz('created_at');
            $table->timestampTz('expires_at');
            $table->timestampTz('answered_at')->nullable();
            $table->timestampTz('purged_at')->nullable();
            $table->unique(['user_id', 'request_key']);
            $table->index(['user_id', 'status']);
            $table->index('created_at');
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        DB::unprepared(<<<'SQL'
ALTER TABLE ai_ask_requests ADD CONSTRAINT ai_ask_requests_status_check CHECK (status IN ('pending', 'answered', 'expired', 'cancelled'));
ALTER TABLE ai_ask_requests ADD CONSTRAINT ai_ask_requests_answer_shape CHECK (
    (status = 'answered') = (answered_at IS NOT NULL)
    AND (answer IS NULL OR status = 'answered')
);
CREATE OR REPLACE FUNCTION kpone_ai_ask_request_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF TG_OP = 'DELETE' THEN
        RAISE EXCEPTION 'Ask requests are never deleted.' USING ERRCODE = 'P0001';
    END IF;
    IF (NEW.id, NEW.organisation_id, NEW.user_id, NEW.request_code, NEW.request_key, NEW.question_chars, NEW.created_at, NEW.expires_at)
       IS DISTINCT FROM (OLD.id, OLD.organisation_id, OLD.user_id, OLD.request_code, OLD.request_key, OLD.question_chars, OLD.created_at, OLD.expires_at) THEN
        RAISE EXCEPTION 'An ask request keeps who asked, when and how long it is open.' USING ERRCODE = 'P0001';
    END IF;
    IF OLD.purged_at IS NOT NULL THEN
        RAISE EXCEPTION 'A purged ask request cannot change.' USING ERRCODE = 'P0001';
    END IF;
    IF NEW.purged_at IS NOT NULL THEN
        -- The retention purge: only the text goes, once; the status and answer time stay.
        IF NEW.question IS NOT NULL OR NEW.answer IS NOT NULL OR NEW.status IS DISTINCT FROM OLD.status OR NEW.answered_at IS DISTINCT FROM OLD.answered_at THEN
            RAISE EXCEPTION 'The retention purge only empties the text.' USING ERRCODE = 'P0001';
        END IF;
        RETURN NEW;
    END IF;
    IF OLD.status <> 'pending' THEN
        RAISE EXCEPTION 'A closed ask request cannot change.' USING ERRCODE = 'P0001';
    END IF;
    IF NEW.status NOT IN ('answered', 'expired', 'cancelled') OR NEW.question IS DISTINCT FROM OLD.question THEN
        RAISE EXCEPTION 'A pending ask request can only be answered, expire or be cancelled.' USING ERRCODE = 'P0001';
    END IF;
    IF NEW.status = 'answered' AND (NEW.answer IS NULL OR NEW.answered_at IS NULL) THEN
        RAISE EXCEPTION 'An answered ask request needs its answer.' USING ERRCODE = 'P0001';
    END IF;
    IF NEW.status <> 'answered' AND NEW.answer IS NOT NULL THEN
        RAISE EXCEPTION 'Only an answered ask request holds an answer.' USING ERRCODE = 'P0001';
    END IF;
    RETURN NEW;
END $$;
DROP TRIGGER IF EXISTS ai_ask_requests_guard ON ai_ask_requests;
CREATE TRIGGER ai_ask_requests_guard BEFORE UPDATE OR DELETE ON ai_ask_requests FOR EACH ROW EXECUTE FUNCTION kpone_ai_ask_request_guard();
CREATE OR REPLACE FUNCTION kpone_ai_ask_truncate_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    RAISE EXCEPTION 'Ask requests are never truncated.' USING ERRCODE = 'P0001';
END $$;
DROP TRIGGER IF EXISTS ai_ask_requests_truncate_guard ON ai_ask_requests;
CREATE TRIGGER ai_ask_requests_truncate_guard BEFORE TRUNCATE ON ai_ask_requests FOR EACH STATEMENT EXECUTE FUNCTION kpone_ai_ask_truncate_guard();
SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Ask requests are evidence and are not rolled back.');
    }
};
