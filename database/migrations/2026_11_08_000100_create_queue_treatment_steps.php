<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Q-2 treatment progress (owner decisions 2026-11-05). Operational board evidence only: a controlled step code
 * per Serving consultation, never clinical text, billing evidence or a TV field. Steps and their events are
 * append-oriented; PostgreSQL refuses deletes, truncates, invalid transitions and out-of-scope inserts.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('queue_treatment_steps')) {
            return;
        }
        Schema::create('queue_treatment_steps', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('organisation_id')->constrained()->restrictOnDelete();
            $t->foreignId('branch_id')->constrained()->restrictOnDelete();
            $t->foreignId('queue_entry_id')->constrained()->restrictOnDelete();
            $t->string('step_code', 40);
            $t->string('state', 20)->default('started');
            $t->foreignId('started_by_user_id')->constrained('users')->restrictOnDelete();
            $t->timestampTz('started_at');
            $t->foreignId('done_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestampTz('done_at')->nullable();
            $t->string('cancel_reason_code', 40)->nullable();
            $t->timestampTz('closed_at')->nullable();
            $t->unsignedInteger('lock_version')->default(1);
            $t->timestampsTz();
            $t->index(['organisation_id', 'branch_id', 'queue_entry_id']);
        });
        Schema::create('queue_treatment_step_events', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('treatment_step_id')->constrained('queue_treatment_steps')->restrictOnDelete();
            $t->string('event_type', 20);
            $t->foreignId('actor_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $t->foreignId('doctor_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $t->string('reason_code', 40)->nullable();
            $t->unsignedInteger('record_version');
            $t->timestampTz('occurred_at');
            $t->unique(['treatment_step_id', 'record_version']);
            $t->index(['doctor_user_id', 'id']);
        });
        Schema::create('queue_treatment_step_commands', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('organisation_id')->constrained()->restrictOnDelete();
            $t->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $t->uuid('retry_key');
            $t->string('payload_hash', 64);
            $t->json('result');
            $t->timestampTz('occurred_at');
            $t->unique(['organisation_id', 'actor_user_id', 'retry_key']);
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
ALTER TABLE queue_treatment_steps ADD CONSTRAINT treatment_step_code_check CHECK (step_code IN ('nebuliser','wound_dressing','injection_observation','iv_drip','observation_after_medicine','ecg','blood_test_poc','other_procedure'));
ALTER TABLE queue_treatment_steps ADD CONSTRAINT treatment_step_state_check CHECK (
 state IN ('started','done','cancelled','acknowledged','system_closed') AND lock_version > 0
 AND ((state IN ('started','done')) = (closed_at IS NULL))
 AND ((done_at IS NULL) = (done_by_user_id IS NULL))
 AND (state NOT IN ('done','acknowledged') OR done_at IS NOT NULL)
 AND ((state = 'cancelled') = (cancel_reason_code IS NOT NULL))
 AND (cancel_reason_code IS NULL OR cancel_reason_code IN ('wrong_patient','wrong_step','marked_too_early')));
CREATE UNIQUE INDEX treatment_step_one_open ON queue_treatment_steps(queue_entry_id) WHERE closed_at IS NULL;
ALTER TABLE queue_treatment_step_events ADD CONSTRAINT treatment_step_event_check CHECK (
 event_type IN ('started','done','cancelled','acknowledged','system_closed') AND record_version > 0
 AND ((event_type = 'cancelled') = (reason_code IS NOT NULL)));
CREATE OR REPLACE FUNCTION kp_treatment_step_immutable() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RAISE EXCEPTION 'Treatment progress evidence is append-only' USING ERRCODE='P0001'; END $$;
CREATE OR REPLACE FUNCTION kp_treatment_step_scope() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE q record; v record;
BEGIN
 SELECT * INTO q FROM queue_entries WHERE id=NEW.queue_entry_id FOR UPDATE;
 SELECT * INTO v FROM visits WHERE id=q.visit_id;
 IF q.id IS NULL OR q.organisation_id<>NEW.organisation_id OR q.branch_id<>NEW.branch_id OR v.visit_type<>'consultation' OR v.status<>'registered' OR q.status<>'serving' OR NEW.state<>'started' OR NEW.lock_version<>1 THEN
 RAISE EXCEPTION 'Treatment progress requires an owned Serving consultation' USING ERRCODE='23514'; END IF;
 IF NOT EXISTS (SELECT 1 FROM users WHERE id=NEW.started_by_user_id AND organisation_id=NEW.organisation_id) THEN RAISE EXCEPTION 'Invalid treatment progress actor' USING ERRCODE='23514'; END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER treatment_step_scope BEFORE INSERT ON queue_treatment_steps FOR EACH ROW EXECUTE FUNCTION kp_treatment_step_scope();
CREATE OR REPLACE FUNCTION kp_treatment_step_update() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN
 IF NOT ((OLD.state='started' AND NEW.state IN ('done','cancelled','system_closed'))
      OR (OLD.state='done' AND NEW.state IN ('cancelled','acknowledged','system_closed')))
 OR NEW.lock_version<>OLD.lock_version+1
 OR (OLD.state='done' AND (NEW.done_at,NEW.done_by_user_id) IS DISTINCT FROM (OLD.done_at,OLD.done_by_user_id))
 OR (OLD.state='started' AND NEW.state<>'done' AND NEW.done_at IS NOT NULL)
 OR (NEW.id,NEW.organisation_id,NEW.branch_id,NEW.queue_entry_id,NEW.step_code,NEW.started_by_user_id,NEW.started_at,NEW.created_at) IS DISTINCT FROM (OLD.id,OLD.organisation_id,OLD.branch_id,OLD.queue_entry_id,OLD.step_code,OLD.started_by_user_id,OLD.started_at,OLD.created_at) THEN
 RAISE EXCEPTION 'Invalid treatment progress transition' USING ERRCODE='23514'; END IF; RETURN NEW; END $$;
CREATE TRIGGER treatment_step_update BEFORE UPDATE ON queue_treatment_steps FOR EACH ROW EXECUTE FUNCTION kp_treatment_step_update();
CREATE TRIGGER treatment_step_delete BEFORE DELETE ON queue_treatment_steps FOR EACH ROW EXECUTE FUNCTION kp_treatment_step_immutable();
CREATE TRIGGER treatment_step_truncate BEFORE TRUNCATE ON queue_treatment_steps FOR EACH STATEMENT EXECUTE FUNCTION kp_treatment_step_immutable();
CREATE OR REPLACE FUNCTION kp_treatment_step_departure() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE s record; stamp timestamptz := clock_timestamp();
BEGIN
 IF OLD.status='serving' AND NEW.status<>'serving' THEN
  FOR s IN SELECT * FROM queue_treatment_steps WHERE queue_entry_id=NEW.id AND closed_at IS NULL FOR UPDATE LOOP
   UPDATE queue_treatment_steps SET state='system_closed',closed_at=stamp,updated_at=stamp,lock_version=lock_version+1 WHERE id=s.id;
   INSERT INTO queue_treatment_step_events(treatment_step_id,event_type,actor_user_id,record_version,occurred_at) VALUES(s.id,'system_closed',NEW.updated_by_user_id,s.lock_version+1,stamp);
   INSERT INTO system_events(event_id,organisation_id,branch_id,name,source,severity,payload,occurred_at,created_at) VALUES(gen_random_uuid(),s.organisation_id,s.branch_id,'queue.treatment_step.system_closed','queue','info',json_build_object('treatment_step_id',s.id,'queue_entry_id',NEW.id),stamp,stamp);
   INSERT INTO audit_logs(request_id,organisation_id,branch_id,actor_user_id,event,subject_type,subject_id,metadata,occurred_at) VALUES(gen_random_uuid(),s.organisation_id,s.branch_id,NEW.updated_by_user_id,'queue.treatment_step.system_closed','queue_treatment_step',s.id,json_build_object('queue_entry_id',NEW.id,'step_code',s.step_code,'record_version',s.lock_version+1),stamp);
  END LOOP;
 END IF; RETURN NEW;
END $$;
CREATE TRIGGER treatment_step_departure AFTER UPDATE OF status ON queue_entries FOR EACH ROW EXECUTE FUNCTION kp_treatment_step_departure();
CREATE OR REPLACE FUNCTION kp_treatment_step_consistency() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE s record;
BEGIN
 SELECT * INTO s FROM queue_treatment_steps WHERE id=NEW.id;
 IF NOT EXISTS (SELECT 1 FROM queue_treatment_step_events e WHERE e.treatment_step_id=s.id AND e.record_version=s.lock_version AND e.event_type=s.state) THEN
 RAISE EXCEPTION 'Treatment progress transition requires its immutable event' USING ERRCODE='23514'; END IF;
 RETURN NULL;
END $$;
CREATE CONSTRAINT TRIGGER treatment_step_consistency AFTER INSERT OR UPDATE ON queue_treatment_steps DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION kp_treatment_step_consistency();
SQL);
        foreach (['queue_treatment_step_events', 'queue_treatment_step_commands'] as $table) {
            DB::unprepared("CREATE TRIGGER {$table}_immutable BEFORE UPDATE OR DELETE ON {$table} FOR EACH ROW EXECUTE FUNCTION kp_treatment_step_immutable(); CREATE TRIGGER {$table}_truncate BEFORE TRUNCATE ON {$table} FOR EACH STATEMENT EXECUTE FUNCTION kp_treatment_step_immutable();");
        }
    }

    public function down(): void {} // Evidence is retained.
};
