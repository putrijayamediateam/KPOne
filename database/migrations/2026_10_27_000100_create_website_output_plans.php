<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('website_output_plan_counters', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('organisation_id')->constrained()->restrictOnDelete();
            $t->integer('year');
            $t->integer('last_number');
            $t->unique(['organisation_id', 'year']);
        });
        Schema::create('website_output_plans', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('organisation_id')->constrained()->restrictOnDelete();
            $t->foreignId('release_id')->constrained('website_releases')->restrictOnDelete();
            $t->string('release_number', 30);
            $t->char('release_manifest_hash', 64);
            $t->string('number', 30);
            $t->integer('schema_version');
            $t->text('content');
            $t->char('plan_hash', 64);
            $t->foreignId('created_by_user_id')->constrained('users')->restrictOnDelete();
            $t->timestampTz('created_at');
            $t->unique(['organisation_id', 'number']);
        });
        Schema::create('website_output_plan_controls', function (Blueprint $t): void {
            $t->foreignId('plan_id')->primary()->constrained('website_output_plans')->restrictOnDelete();
            $t->string('state', 20);
            $t->integer('lock_version');
        });
        Schema::create('website_output_plan_assets', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('plan_id')->unique()->constrained('website_output_plans')->restrictOnDelete();
            $t->foreignId('source_asset_id')->constrained('website_media_assets')->restrictOnDelete();
            $t->char('sha256', 64);
            $t->string('mime', 30);
            $t->integer('bytes');
            $t->integer('width');
            $t->integer('height');
            $t->string('alt_en', 200);
            $t->string('alt_bm', 200);
            $t->text('encrypted_bytes');
        });
        Schema::create('website_output_plan_actions', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('plan_id')->constrained('website_output_plans')->restrictOnDelete();
            $t->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $t->string('action', 20);
            $t->char('plan_hash', 64);
            $t->string('reason', 300)->nullable();
            $t->timestampTz('created_at');
        });
        Schema::create('website_output_plan_commands', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('organisation_id')->constrained()->restrictOnDelete();
            $t->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $t->uuid('idempotency_key');
            $t->char('payload_hash', 64);
            $t->foreignId('plan_id')->constrained('website_output_plans')->restrictOnDelete();
            $t->string('operation', 20);
            $t->timestampTz('created_at');
            $t->unique(['organisation_id', 'idempotency_key']);
        });
        foreach (['website_output_plans', 'website_output_plan_assets', 'website_output_plan_actions', 'website_output_plan_commands'] as $table) {
            DB::unprepared("CREATE TRIGGER {$table}_immutable BEFORE UPDATE OR DELETE OR TRUNCATE ON {$table} FOR EACH STATEMENT EXECUTE FUNCTION website_append_only()");
        }
        DB::statement("ALTER TABLE website_output_plan_controls ADD CONSTRAINT output_plan_state CHECK (state IN ('prepared','approved','refused','withdrawn') AND lock_version>0)");
        DB::statement("ALTER TABLE website_output_plan_actions ADD CONSTRAINT output_plan_action CHECK (action IN ('prepared','approved','refused','withdrawn'))");
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION website_output_plan_control_guard() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN
 IF TG_OP<>'UPDATE' THEN RAISE EXCEPTION 'Output plan control is retained'; END IF;
 IF NEW.plan_id<>OLD.plan_id OR NEW.lock_version<>OLD.lock_version+1 OR NOT ((OLD.state='prepared' AND NEW.state IN ('approved','refused')) OR (OLD.state='approved' AND NEW.state='withdrawn')) THEN RAISE EXCEPTION 'Invalid output plan transition'; END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER output_plan_control_guard BEFORE UPDATE OR DELETE ON website_output_plan_controls FOR EACH ROW EXECUTE FUNCTION website_output_plan_control_guard();
CREATE TRIGGER output_plan_control_no_truncate BEFORE TRUNCATE ON website_output_plan_controls FOR EACH STATEMENT EXECUTE FUNCTION website_append_only();
CREATE OR REPLACE FUNCTION website_output_plan_counter_guard() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN
 IF TG_OP<>'UPDATE' THEN RAISE EXCEPTION 'Output plan counters are retained'; END IF;
 IF NEW.organisation_id<>OLD.organisation_id OR NEW.year<>OLD.year OR NEW.last_number<>OLD.last_number+1 THEN RAISE EXCEPTION 'Output plan numbers cannot be reused'; END IF; RETURN NEW;
END $$;
CREATE TRIGGER output_plan_counter_guard BEFORE UPDATE OR DELETE ON website_output_plan_counters FOR EACH ROW EXECUTE FUNCTION website_output_plan_counter_guard();
CREATE TRIGGER output_plan_counter_no_truncate BEFORE TRUNCATE ON website_output_plan_counters FOR EACH STATEMENT EXECUTE FUNCTION website_append_only();
CREATE OR REPLACE FUNCTION website_output_plan_insert_guard() RETURNS trigger LANGUAGE plpgsql AS $$ DECLARE r record; s text; BEGIN
 SELECT * INTO r FROM website_releases WHERE id=NEW.release_id FOR UPDATE;
 SELECT state INTO s FROM website_release_controls WHERE release_id=NEW.release_id FOR UPDATE;
 IF s IS DISTINCT FROM 'approved' OR r.organisation_id IS DISTINCT FROM NEW.organisation_id OR r.number IS DISTINCT FROM NEW.release_number OR r.manifest_hash IS DISTINCT FROM NEW.release_manifest_hash THEN RAISE EXCEPTION 'Output plan needs its exact approved release'; END IF;
 IF NEW.schema_version<>1 OR encode(sha256(convert_to(NEW.content,'UTF8')),'hex')<>NEW.plan_hash OR NEW.content::jsonb->'release'->>'number' IS DISTINCT FROM NEW.release_number OR NEW.content::jsonb->'release'->>'manifest_hash' IS DISTINCT FROM NEW.release_manifest_hash THEN RAISE EXCEPTION 'Invalid output plan binding or hash'; END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER output_plan_insert_guard BEFORE INSERT ON website_output_plans FOR EACH ROW EXECUTE FUNCTION website_output_plan_insert_guard();
CREATE OR REPLACE FUNCTION website_output_plan_complete() RETURNS trigger LANGUAGE plpgsql AS $$ DECLARE logo jsonb; a record; BEGIN
 IF NOT EXISTS(SELECT 1 FROM website_output_plan_controls WHERE plan_id=NEW.id AND state='prepared' AND lock_version=1) OR NOT EXISTS(SELECT 1 FROM website_output_plan_actions WHERE plan_id=NEW.id AND action='prepared' AND plan_hash=NEW.plan_hash AND actor_user_id=NEW.created_by_user_id) OR NOT EXISTS(SELECT 1 FROM website_output_plan_commands WHERE plan_id=NEW.id AND operation='prepare' AND organisation_id=NEW.organisation_id AND actor_user_id=NEW.created_by_user_id) THEN RAISE EXCEPTION 'Incomplete output plan evidence'; END IF;
 logo:=NEW.content::jsonb->'logo';
 SELECT * INTO a FROM website_output_plan_assets WHERE plan_id=NEW.id;
 IF logo='null'::jsonb THEN IF FOUND THEN RAISE EXCEPTION 'Unexpected logo'; END IF;
 ELSE IF NOT FOUND OR a.sha256 IS DISTINCT FROM logo->>'sha256' OR a.mime IS DISTINCT FROM logo->>'mime' OR a.bytes IS DISTINCT FROM (logo->>'bytes')::integer OR a.width IS DISTINCT FROM (logo->>'width')::integer OR a.height IS DISTINCT FROM (logo->>'height')::integer OR a.alt_en IS DISTINCT FROM logo->>'alt_en' OR a.alt_bm IS DISTINCT FROM logo->>'alt_bm' THEN RAISE EXCEPTION 'Incomplete logo evidence'; END IF; END IF;
 RETURN NULL;
END $$;
CREATE CONSTRAINT TRIGGER output_plan_complete AFTER INSERT ON website_output_plans DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION website_output_plan_complete();
CREATE OR REPLACE FUNCTION website_output_plan_child_guard() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN
 IF TG_TABLE_NAME='website_output_plan_assets' AND EXISTS(SELECT 1 FROM website_output_plan_actions WHERE plan_id=NEW.plan_id AND action='prepared') THEN RAISE EXCEPTION 'Logo capture is closed'; END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER output_plan_asset_insert BEFORE INSERT ON website_output_plan_assets FOR EACH ROW EXECUTE FUNCTION website_output_plan_child_guard();
SQL);
    }

    public function down(): void {} // Retain output plan evidence and counters.
};
