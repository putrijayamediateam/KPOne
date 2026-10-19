<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['invoice_note_streams', 'invoice_note_events', 'invoice_note_commands'] as $name) {
            Schema::create($name, function (Blueprint $t) use ($name) {
                $t->id();
                $t->unsignedBigInteger('organisation_id');
                $t->unsignedBigInteger('branch_id');
                $t->unsignedBigInteger('invoice_id');
                $t->foreign(['invoice_id', 'organisation_id', 'branch_id'], $name.'_source_fk')->references(['id', 'organisation_id', 'branch_id'])->on('invoices')->restrictOnDelete();
                if ($name === 'invoice_note_streams') {
                    $t->unsignedInteger('lock_version')->default(0);
                    $t->unique('invoice_id');
                } else {
                    $t->unsignedBigInteger('actor_user_id');
                    $t->foreign(['actor_user_id', 'organisation_id'], $name.'_actor_fk')->references(['id', 'organisation_id'])->on('users')->restrictOnDelete();
                    $t->timestampTz('created_at');
                    $t->unsignedInteger('stream_version');
                    if ($name === 'invoice_note_events') {
                        $t->uuid('public_id')->unique();
                        $t->string('kind', 12);
                        $t->text('body')->nullable();
                        $t->string('reason_code', 24)->nullable();
                        $t->unsignedBigInteger('target_id')->nullable()->unique();
                        $t->unique(['id', 'organisation_id', 'branch_id', 'invoice_id'], 'invoice_note_event_owner');
                        $t->unique(['invoice_id', 'stream_version']);
                        $t->index(['organisation_id', 'branch_id', 'invoice_id', 'id'], 'invoice_note_history_index');
                    } else {
                        $t->uuid('idempotency_key');
                        $t->string('payload_hash', 64);
                        $t->unsignedBigInteger('event_id');
                        $t->unique(['organisation_id', 'idempotency_key']);
                    }
                }
            });
        }
        Schema::table('invoice_note_events', function (Blueprint $t) {
            $t->foreign(['target_id', 'organisation_id', 'branch_id', 'invoice_id'], 'invoice_note_target_owner')->references(['id', 'organisation_id', 'branch_id', 'invoice_id'])->on('invoice_note_events')->restrictOnDelete();
        });
        Schema::table('invoice_note_commands', function (Blueprint $t) {
            $t->foreign(['event_id', 'organisation_id', 'branch_id', 'invoice_id'], 'invoice_note_command_owner')->references(['id', 'organisation_id', 'branch_id', 'invoice_id'])->on('invoice_note_events')->restrictOnDelete();
        });
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE invoice_note_events ADD CONSTRAINT invoice_note_shape CHECK (stream_version > 0 AND ((kind='added' AND target_id IS NULL AND body IS NOT NULL AND reason_code IS NULL) OR (kind IN ('retracted','corrected') AND target_id IS NOT NULL AND target_id<>id AND reason_code IN ('duplicate','entered_in_error','replaced') AND ((kind='retracted' AND body IS NULL) OR (kind='corrected' AND body IS NOT NULL)))))");
            DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION kpone_invoice_note_retained() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN RAISE EXCEPTION 'Invoice note evidence is immutable and retained' USING ERRCODE='23514'; END $$;
CREATE TRIGGER invoice_note_events_retained BEFORE UPDATE OR DELETE ON invoice_note_events FOR EACH ROW EXECUTE FUNCTION kpone_invoice_note_retained();
CREATE TRIGGER invoice_note_commands_retained BEFORE UPDATE OR DELETE ON invoice_note_commands FOR EACH ROW EXECUTE FUNCTION kpone_invoice_note_retained();
CREATE TRIGGER invoice_note_stream_retained BEFORE DELETE ON invoice_note_streams FOR EACH ROW EXECUTE FUNCTION kpone_invoice_note_retained();
CREATE OR REPLACE FUNCTION kpone_invoice_note_stream_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF (NEW.id,NEW.organisation_id,NEW.branch_id,NEW.invoice_id) IS DISTINCT FROM (OLD.id,OLD.organisation_id,OLD.branch_id,OLD.invoice_id) OR NEW.lock_version<>OLD.lock_version+1 THEN RAISE EXCEPTION 'Invalid invoice note stream transition' USING ERRCODE='23514'; END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER invoice_note_stream_guard BEFORE UPDATE ON invoice_note_streams FOR EACH ROW EXECUTE FUNCTION kpone_invoice_note_stream_guard();
CREATE OR REPLACE FUNCTION kpone_invoice_note_source() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE source_status text; current_version integer; target_kind text;
BEGIN
 SELECT status INTO source_status FROM invoices WHERE id=NEW.invoice_id AND organisation_id=NEW.organisation_id AND branch_id=NEW.branch_id FOR UPDATE;
 IF source_status IS DISTINCT FROM 'finalized' THEN RAISE EXCEPTION 'Notes require a finalized non-void invoice' USING ERRCODE='23514'; END IF;
 SELECT lock_version INTO current_version FROM invoice_note_streams WHERE invoice_id=NEW.invoice_id;
 IF current_version IS DISTINCT FROM NEW.stream_version THEN RAISE EXCEPTION 'Note stream version mismatch' USING ERRCODE='23514'; END IF;
 IF NEW.target_id IS NOT NULL THEN
   SELECT kind INTO target_kind FROM invoice_note_events WHERE id=NEW.target_id AND organisation_id=NEW.organisation_id AND branch_id=NEW.branch_id AND invoice_id=NEW.invoice_id;
   IF target_kind IS NULL OR target_kind NOT IN ('added','corrected') THEN RAISE EXCEPTION 'Invalid note target' USING ERRCODE='23514'; END IF;
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER invoice_note_source BEFORE INSERT ON invoice_note_events FOR EACH ROW EXECUTE FUNCTION kpone_invoice_note_source();
SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_note_commands');
        Schema::dropIfExists('invoice_note_events');
        Schema::dropIfExists('invoice_note_streams');
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP FUNCTION IF EXISTS kpone_invoice_note_source()');
            DB::statement('DROP FUNCTION IF EXISTS kpone_invoice_note_stream_guard()');
            DB::statement('DROP FUNCTION IF EXISTS kpone_invoice_note_retained()');
        }
    }
};
