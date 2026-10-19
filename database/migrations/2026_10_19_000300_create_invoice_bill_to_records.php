<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['invoice_bill_to_controls', 'invoice_bill_to_revisions', 'invoice_bill_to_commands'] as $name) {
            Schema::create($name, function (Blueprint $t) use ($name) {
                $t->id();
                $t->unsignedBigInteger('organisation_id');
                $t->unsignedBigInteger('branch_id');
                $t->unsignedBigInteger('invoice_id');
                $t->foreign(['invoice_id', 'organisation_id', 'branch_id'], $name.'_source_fk')->references(['id', 'organisation_id', 'branch_id'])->on('invoices')->restrictOnDelete();
                if ($name === 'invoice_bill_to_controls') {
                    $t->unsignedInteger('lock_version')->default(0);
                    $t->unique('invoice_id');
                } else {
                    $t->unsignedBigInteger('actor_user_id');
                    $t->foreign(['actor_user_id', 'organisation_id'], $name.'_actor_fk')->references(['id', 'organisation_id'])->on('users')->restrictOnDelete();
                    $t->timestampTz('created_at');
                    $t->unsignedInteger('revision_version');
                    if ($name === 'invoice_bill_to_revisions') {
                        $t->uuid('public_id')->unique();
                        $t->string('kind', 12);
                        $t->text('recipient')->nullable();
                        $t->unique(['id', 'organisation_id', 'branch_id', 'invoice_id'], 'invoice_bill_to_revision_owner');
                        $t->unique(['invoice_id', 'revision_version']);
                        $t->index(['organisation_id', 'branch_id', 'invoice_id', 'id'], 'invoice_bill_to_history_index');
                    } else {
                        $t->uuid('idempotency_key');
                        $t->string('payload_hash', 64);
                        $t->unsignedBigInteger('revision_id');
                        $t->unique(['organisation_id', 'idempotency_key']);
                    }
                }
            });
        }
        Schema::table('invoice_bill_to_commands', function (Blueprint $t) {
            $t->foreign(['revision_id', 'organisation_id', 'branch_id', 'invoice_id'], 'bill_to_command_owner')->references(['id', 'organisation_id', 'branch_id', 'invoice_id'])->on('invoice_bill_to_revisions')->restrictOnDelete();
        });
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE invoice_bill_to_revisions ADD CONSTRAINT invoice_bill_to_shape CHECK (revision_version > 0 AND ((kind IN ('set','changed') AND recipient IS NOT NULL) OR (kind='cleared' AND recipient IS NULL)))");
            DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION kpone_invoice_bill_to_retained() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN RAISE EXCEPTION 'Bill to evidence is immutable and retained' USING ERRCODE='23514'; END $$;
CREATE TRIGGER invoice_bill_to_revisions_retained BEFORE UPDATE OR DELETE ON invoice_bill_to_revisions FOR EACH ROW EXECUTE FUNCTION kpone_invoice_bill_to_retained();
CREATE TRIGGER invoice_bill_to_commands_retained BEFORE UPDATE OR DELETE ON invoice_bill_to_commands FOR EACH ROW EXECUTE FUNCTION kpone_invoice_bill_to_retained();
CREATE TRIGGER invoice_bill_to_stream_retained BEFORE DELETE ON invoice_bill_to_controls FOR EACH ROW EXECUTE FUNCTION kpone_invoice_bill_to_retained();
CREATE OR REPLACE FUNCTION kpone_invoice_bill_to_stream_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF (NEW.id,NEW.organisation_id,NEW.branch_id,NEW.invoice_id) IS DISTINCT FROM (OLD.id,OLD.organisation_id,OLD.branch_id,OLD.invoice_id) OR NEW.lock_version<>OLD.lock_version+1 THEN RAISE EXCEPTION 'Invalid Bill to stream transition' USING ERRCODE='23514'; END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER invoice_bill_to_stream_guard BEFORE UPDATE ON invoice_bill_to_controls FOR EACH ROW EXECUTE FUNCTION kpone_invoice_bill_to_stream_guard();
CREATE OR REPLACE FUNCTION kpone_invoice_bill_to_source() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE source_status text; current_version integer; prior_recipient text;
BEGIN
 SELECT status INTO source_status FROM invoices WHERE id=NEW.invoice_id AND organisation_id=NEW.organisation_id AND branch_id=NEW.branch_id FOR UPDATE;
 IF source_status IS DISTINCT FROM 'finalized' THEN RAISE EXCEPTION 'Bill to requires a finalized non-void invoice' USING ERRCODE='23514'; END IF;
 SELECT lock_version INTO current_version FROM invoice_bill_to_controls WHERE invoice_id=NEW.invoice_id AND organisation_id=NEW.organisation_id AND branch_id=NEW.branch_id;
 IF current_version IS DISTINCT FROM NEW.revision_version THEN RAISE EXCEPTION 'Bill to revision version mismatch' USING ERRCODE='23514'; END IF;
 SELECT recipient INTO prior_recipient FROM invoice_bill_to_revisions WHERE invoice_id=NEW.invoice_id ORDER BY revision_version DESC LIMIT 1;
 IF (NEW.kind='set' AND prior_recipient IS NOT NULL) OR (NEW.kind IN ('changed','cleared') AND prior_recipient IS NULL) THEN RAISE EXCEPTION 'Invalid Bill to transition' USING ERRCODE='23514'; END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER invoice_bill_to_source BEFORE INSERT ON invoice_bill_to_revisions FOR EACH ROW EXECUTE FUNCTION kpone_invoice_bill_to_source();
SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_bill_to_commands');
        Schema::dropIfExists('invoice_bill_to_revisions');
        Schema::dropIfExists('invoice_bill_to_controls');
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP FUNCTION IF EXISTS kpone_invoice_bill_to_source()');
            DB::statement('DROP FUNCTION IF EXISTS kpone_invoice_bill_to_stream_guard()');
            DB::statement('DROP FUNCTION IF EXISTS kpone_invoice_bill_to_retained()');
        }
    }
};
