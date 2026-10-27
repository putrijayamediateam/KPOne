<?php

use App\Domain\Queue\Readiness\CallHoldPermissions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('queue_call_holds')) {
            $this->grantPermissions();

            return;
        }
        Schema::create('queue_call_holds', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('organisation_id')->constrained()->restrictOnDelete();
            $t->foreignId('branch_id')->constrained()->restrictOnDelete();
            $t->foreignId('queue_entry_id')->constrained()->restrictOnDelete();
            $t->foreignId('set_by_user_id')->constrained('users')->restrictOnDelete();
            $t->string('reason_code', 50);
            $t->string('state', 20)->default('active');
            $t->unsignedInteger('lock_version')->default(1);
            $t->timestampTz('closed_at')->nullable();
            $t->timestampsTz();
            $t->index(['organisation_id', 'branch_id', 'queue_entry_id']);
        });
        Schema::create('queue_call_hold_events', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('call_hold_id')->constrained('queue_call_holds')->restrictOnDelete();
            $t->string('event_type', 20);
            $t->foreignId('actor_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $t->foreignId('doctor_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $t->unsignedInteger('record_version');
            $t->timestampTz('occurred_at');
            $t->unique(['call_hold_id', 'record_version']);
            $t->index(['doctor_user_id', 'id']);
        });
        Schema::create('queue_call_hold_overrides', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('call_hold_id')->unique()->constrained('queue_call_holds')->restrictOnDelete();
            $t->foreignId('queue_call_id')->unique()->constrained('queue_calls')->restrictOnDelete();
            $t->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $t->string('reason_code', 40);
            $t->timestampTz('occurred_at');
        });
        Schema::create('queue_call_hold_commands', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('organisation_id')->constrained()->restrictOnDelete();
            $t->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $t->uuid('retry_key');
            $t->string('payload_hash', 64);
            $t->json('result');
            $t->timestampTz('occurred_at');
            $t->unique(['organisation_id', 'actor_user_id', 'retry_key']);
        });
        DB::unprepared(<<<'SQL'
ALTER TABLE queue_call_holds ADD CONSTRAINT call_hold_reason_check CHECK (reason_code IN ('awaiting_panel_confirmation','awaiting_patient_details','awaiting_payment_or_deposit','other_administrative'));
ALTER TABLE queue_call_holds ADD CONSTRAINT call_hold_state_check CHECK (state IN ('active','cleared','system_closed','overridden') AND lock_version > 0 AND ((state='active') = (closed_at IS NULL)));
CREATE UNIQUE INDEX call_hold_one_active ON queue_call_holds(queue_entry_id) WHERE closed_at IS NULL;
ALTER TABLE queue_call_hold_events ADD CONSTRAINT call_hold_event_check CHECK (event_type IN ('set','cleared','system_closed','overridden') AND record_version > 0);
ALTER TABLE queue_call_hold_overrides ADD CONSTRAINT call_hold_override_reason CHECK (reason_code IN ('patient_is_urgent','issue_resolved_verbally','patient_at_door'));
CREATE OR REPLACE FUNCTION kp_call_hold_immutable() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RAISE EXCEPTION 'Call readiness evidence is append-only' USING ERRCODE='P0001'; END $$;
CREATE OR REPLACE FUNCTION kp_call_hold_scope() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE q record; v record;
BEGIN
 SELECT * INTO q FROM queue_entries WHERE id=NEW.queue_entry_id FOR UPDATE;
 SELECT * INTO v FROM visits WHERE id=q.visit_id;
 IF q.id IS NULL OR q.organisation_id<>NEW.organisation_id OR q.branch_id<>NEW.branch_id OR v.visit_type<>'consultation' OR v.status<>'registered' OR q.status<>'waiting' THEN
 RAISE EXCEPTION 'Readiness requires an owned waiting consultation' USING ERRCODE='23514'; END IF;
 IF NOT EXISTS (SELECT 1 FROM users WHERE id=NEW.set_by_user_id AND organisation_id=NEW.organisation_id) THEN RAISE EXCEPTION 'Invalid readiness actor' USING ERRCODE='23514'; END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER call_hold_scope BEFORE INSERT ON queue_call_holds FOR EACH ROW EXECUTE FUNCTION kp_call_hold_scope();
CREATE OR REPLACE FUNCTION kp_call_hold_update() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN
 IF OLD.state<>'active' OR NEW.state='active' OR NEW.lock_version<>OLD.lock_version+1 OR NEW.closed_at IS NULL
 OR (NEW.id,NEW.organisation_id,NEW.branch_id,NEW.queue_entry_id,NEW.set_by_user_id,NEW.reason_code,NEW.created_at) IS DISTINCT FROM (OLD.id,OLD.organisation_id,OLD.branch_id,OLD.queue_entry_id,OLD.set_by_user_id,OLD.reason_code,OLD.created_at) THEN
 RAISE EXCEPTION 'Invalid readiness transition' USING ERRCODE='23514'; END IF; RETURN NEW; END $$;
CREATE TRIGGER call_hold_update BEFORE UPDATE ON queue_call_holds FOR EACH ROW EXECUTE FUNCTION kp_call_hold_update();
CREATE TRIGGER call_hold_delete BEFORE DELETE ON queue_call_holds FOR EACH ROW EXECUTE FUNCTION kp_call_hold_immutable();
CREATE TRIGGER call_hold_truncate BEFORE TRUNCATE ON queue_call_holds FOR EACH STATEMENT EXECUTE FUNCTION kp_call_hold_immutable();
CREATE OR REPLACE FUNCTION kp_call_override_witness() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE f record; c record; q record; v record;
BEGIN
 SELECT * INTO f FROM queue_call_holds WHERE id=NEW.call_hold_id;
 SELECT * INTO c FROM queue_calls WHERE id=NEW.queue_call_id;
 SELECT * INTO q FROM queue_entries WHERE id=f.queue_entry_id;
 SELECT * INTO v FROM visits WHERE id=q.visit_id;
 IF f.state<>'overridden' OR c.queue_entry_id IS DISTINCT FROM f.queue_entry_id OR c.is_recall OR c.service<>'consultation' OR c.called_by_user_id<>NEW.actor_user_id OR q.status<>'serving' OR q.called_at IS DISTINCT FROM c.called_at OR v.assigned_doctor_user_id IS DISTINCT FROM NEW.actor_user_id THEN
 RAISE EXCEPTION 'Override requires its attending doctor Serving transition' USING ERRCODE='23514'; END IF; RETURN NEW;
END $$;
CREATE TRIGGER call_override_witness BEFORE INSERT ON queue_call_hold_overrides FOR EACH ROW EXECUTE FUNCTION kp_call_override_witness();
CREATE OR REPLACE FUNCTION kp_call_hold_departure() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE f record; stamp timestamptz := clock_timestamp();
BEGIN
 IF OLD.status='waiting' AND NEW.status<>'waiting' THEN
  FOR f IN SELECT * FROM queue_call_holds WHERE queue_entry_id=NEW.id AND closed_at IS NULL FOR UPDATE LOOP
   UPDATE queue_call_holds SET state='system_closed',closed_at=stamp,updated_at=stamp,lock_version=lock_version+1 WHERE id=f.id;
   INSERT INTO queue_call_hold_events(call_hold_id,event_type,actor_user_id,record_version,occurred_at) VALUES(f.id,'system_closed',NEW.updated_by_user_id,f.lock_version+1,stamp);
   INSERT INTO system_events(event_id,organisation_id,branch_id,name,source,severity,payload,occurred_at,created_at) VALUES(gen_random_uuid(),f.organisation_id,f.branch_id,'queue.call_hold.system_closed','queue','info',json_build_object('call_hold_id',f.id,'queue_entry_id',NEW.id),stamp,stamp);
   INSERT INTO audit_logs(request_id,organisation_id,branch_id,actor_user_id,event,subject_type,subject_id,metadata,occurred_at) VALUES(gen_random_uuid(),f.organisation_id,f.branch_id,NEW.updated_by_user_id,'queue.call_hold.system_closed','queue_call_hold',f.id,json_build_object('queue_entry_id',NEW.id,'reason_code',f.reason_code,'record_version',f.lock_version+1),stamp);
  END LOOP;
 END IF; RETURN NEW;
END $$;
CREATE TRIGGER call_hold_departure AFTER UPDATE OF status ON queue_entries FOR EACH ROW EXECUTE FUNCTION kp_call_hold_departure();
CREATE OR REPLACE FUNCTION kp_call_hold_consistency() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE f record;
BEGIN
 SELECT * INTO f FROM queue_call_holds WHERE id=NEW.id;
 IF NOT EXISTS (SELECT 1 FROM queue_call_hold_events e WHERE e.call_hold_id=f.id AND e.record_version=f.lock_version AND e.event_type=CASE WHEN f.state='active' THEN 'set' ELSE f.state END) THEN
 RAISE EXCEPTION 'Readiness transition requires its immutable event' USING ERRCODE='23514'; END IF;
 IF f.state='overridden' AND NOT EXISTS (SELECT 1 FROM queue_call_hold_overrides o WHERE o.call_hold_id=f.id) THEN
 RAISE EXCEPTION 'Override requires its immutable Serving witness' USING ERRCODE='23514'; END IF;
 RETURN NULL;
END $$;
CREATE CONSTRAINT TRIGGER call_hold_consistency AFTER INSERT OR UPDATE ON queue_call_holds DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION kp_call_hold_consistency();
SQL);
        foreach (['queue_call_hold_events', 'queue_call_hold_overrides', 'queue_call_hold_commands'] as $table) {
            DB::unprepared("CREATE TRIGGER {$table}_immutable BEFORE UPDATE OR DELETE ON {$table} FOR EACH ROW EXECUTE FUNCTION kp_call_hold_immutable(); CREATE TRIGGER {$table}_truncate BEFORE TRUNCATE ON {$table} FOR EACH STATEMENT EXECUTE FUNCTION kp_call_hold_immutable();");
        }
        $this->grantPermissions();
    }

    private function grantPermissions(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        foreach (CallHoldPermissions::grants() as $name => $holders) {
            $permission = Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
            foreach ($holders as $holder) {
                $role = Role::where('name', $holder)->where('guard_name', 'web')->first();
                if (! $role) {
                    Log::warning('Call readiness permission role missing', ['role' => $holder, 'permission' => $name]);

                    continue;
                }
                if (! $role->permissions()->whereKey($permission->id)->exists()) {
                    $role->givePermissionTo($permission);
                }
            }
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void {} // Evidence and additive grants are retained.
};
