<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * CHAT-1 (docs/agents/AI-CHATBOX-DESIGN.md section 6): the chatbox history. Titles, message text and figures are encrypted by the
 * models. Messages are append-only: PostgreSQL refuses update (except the retention purge, which only empties the encrypted text
 * and figures once), delete and truncate. A thread can be archived by its owner and never deleted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_chat_threads', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organisation_id')->constrained('organisations');
            $table->foreignId('user_id')->constrained('users');
            $table->string('surface', 40);
            $table->text('title');
            $table->boolean('is_archived')->default(false);
            $table->timestampTz('created_at');
            $table->timestampTz('last_used_at');
            $table->index(['user_id', 'last_used_at']);
        });
        Schema::create('ai_chat_messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('thread_id')->constrained('ai_chat_threads');
            $table->foreignId('organisation_id')->constrained('organisations');
            $table->foreignId('user_id')->constrained('users');
            $table->string('role', 12);
            $table->text('body')->nullable();
            $table->text('figures')->nullable();
            $table->jsonb('tools')->nullable();
            $table->unsignedInteger('tokens_in')->default(0);
            $table->unsignedInteger('tokens_out')->default(0);
            $table->unsignedInteger('cost_sen')->default(0);
            $table->timestampTz('created_at');
            $table->timestampTz('purged_at')->nullable();
            $table->index(['thread_id', 'id']);
            $table->index('created_at');
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        DB::unprepared(<<<'SQL'
ALTER TABLE ai_chat_threads ADD CONSTRAINT ai_chat_threads_surface_check CHECK (surface IN ('director_overview'));
ALTER TABLE ai_chat_messages ADD CONSTRAINT ai_chat_messages_role_check CHECK (role IN ('user', 'assistant'));
CREATE OR REPLACE FUNCTION kpone_ai_chat_message_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF TG_OP = 'DELETE' THEN
        RAISE EXCEPTION 'AI chat messages are append-only.' USING ERRCODE = 'P0001';
    END IF;
    IF OLD.purged_at IS NOT NULL OR NEW.purged_at IS NULL OR NEW.body IS NOT NULL OR NEW.figures IS NOT NULL
        OR (NEW.id, NEW.thread_id, NEW.organisation_id, NEW.user_id, NEW.role, NEW.tools, NEW.tokens_in, NEW.tokens_out, NEW.cost_sen, NEW.created_at)
           IS DISTINCT FROM (OLD.id, OLD.thread_id, OLD.organisation_id, OLD.user_id, OLD.role, OLD.tools, OLD.tokens_in, OLD.tokens_out, OLD.cost_sen, OLD.created_at) THEN
        RAISE EXCEPTION 'AI chat messages are append-only; only the retention purge may empty the text once.' USING ERRCODE = 'P0001';
    END IF;
    RETURN NEW;
END $$;
DROP TRIGGER IF EXISTS ai_chat_messages_guard ON ai_chat_messages;
CREATE TRIGGER ai_chat_messages_guard BEFORE UPDATE OR DELETE ON ai_chat_messages FOR EACH ROW EXECUTE FUNCTION kpone_ai_chat_message_guard();
CREATE OR REPLACE FUNCTION kpone_ai_chat_truncate_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    RAISE EXCEPTION 'AI chat history is never truncated.' USING ERRCODE = 'P0001';
END $$;
DROP TRIGGER IF EXISTS ai_chat_messages_truncate_guard ON ai_chat_messages;
CREATE TRIGGER ai_chat_messages_truncate_guard BEFORE TRUNCATE ON ai_chat_messages FOR EACH STATEMENT EXECUTE FUNCTION kpone_ai_chat_truncate_guard();
DROP TRIGGER IF EXISTS ai_chat_threads_truncate_guard ON ai_chat_threads;
CREATE TRIGGER ai_chat_threads_truncate_guard BEFORE TRUNCATE ON ai_chat_threads FOR EACH STATEMENT EXECUTE FUNCTION kpone_ai_chat_truncate_guard();
CREATE OR REPLACE FUNCTION kpone_ai_chat_thread_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF TG_OP = 'DELETE' THEN
        RAISE EXCEPTION 'AI chat threads are never deleted.' USING ERRCODE = 'P0001';
    END IF;
    IF (NEW.id, NEW.organisation_id, NEW.user_id, NEW.surface, NEW.title, NEW.created_at)
       IS DISTINCT FROM (OLD.id, OLD.organisation_id, OLD.user_id, OLD.surface, OLD.title, OLD.created_at) THEN
        RAISE EXCEPTION 'Only last used time and archived state of a chat thread can change.' USING ERRCODE = 'P0001';
    END IF;
    RETURN NEW;
END $$;
DROP TRIGGER IF EXISTS ai_chat_threads_guard ON ai_chat_threads;
CREATE TRIGGER ai_chat_threads_guard BEFORE UPDATE OR DELETE ON ai_chat_threads FOR EACH ROW EXECUTE FUNCTION kpone_ai_chat_thread_guard();
SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('AI chat history is evidence and is not rolled back.');
    }
};
