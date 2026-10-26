<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('website_artifact_attempts', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('organisation_id')->constrained()->restrictOnDelete();
            $t->foreignId('release_id')->constrained('website_releases')->restrictOnDelete();
            $t->foreignId('plan_id')->constrained('website_output_plans')->restrictOnDelete();
            $t->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $t->uuid('idempotency_key');
            $t->char('payload_hash', 64);
            $t->uuid('stage_token');
            $t->string('state', 20);
            $t->integer('lock_version');
            $t->string('failure_code', 30)->nullable();
            $t->timestampTz('lease_until');
            $t->timestampTz('created_at');
            $t->unique(['organisation_id', 'idempotency_key']);
            $t->unique('stage_token');
        });
        Schema::create('website_artifact_counters', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('organisation_id')->constrained()->restrictOnDelete();
            $t->integer('year');
            $t->integer('last_number');
            $t->unique(['organisation_id', 'year']);
        });
        Schema::create('website_artifacts', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('organisation_id')->constrained()->restrictOnDelete();
            $t->foreignId('attempt_id')->unique()->constrained('website_artifact_attempts')->restrictOnDelete();
            $t->foreignId('release_id')->constrained('website_releases')->restrictOnDelete();
            $t->string('release_number', 30);
            $t->char('release_manifest_hash', 64);
            $t->foreignId('plan_id')->constrained('website_output_plans')->restrictOnDelete();
            $t->string('plan_number', 30);
            $t->char('plan_hash', 64);
            $t->string('renderer_version', 40);
            $t->char('renderer_digest', 64);
            $t->string('profile_id', 40);
            $t->char('profile_digest', 64);
            $t->integer('file_count');
            $t->bigInteger('total_bytes');
            $t->text('file_manifest');
            $t->char('file_manifest_hash', 64);
            $t->text('identity_manifest');
            $t->char('artifact_hash', 64);
            $t->string('number', 30);
            $t->foreignId('created_by_user_id')->constrained('users')->restrictOnDelete();
            $t->timestampTz('created_at');
            $t->unique(['organisation_id', 'number']);
        });
        Schema::table('website_artifact_attempts', function (Blueprint $t): void {
            $t->foreignId('artifact_id')->nullable()->unique()->constrained('website_artifacts')->restrictOnDelete();
        });
        Schema::create('website_artifact_files', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('artifact_id')->constrained('website_artifacts')->restrictOnDelete();
            $t->string('path', 180);
            $t->string('mime', 40);
            $t->bigInteger('bytes');
            $t->char('sha256', 64);
            $t->string('storage_kind', 20);
            $t->string('storage_key', 180)->nullable();
            $t->foreignId('release_media_id')->nullable()->constrained('website_release_media')->restrictOnDelete();
            $t->foreignId('plan_asset_id')->nullable()->constrained('website_output_plan_assets')->restrictOnDelete();
            $t->boolean('encrypted');
            $t->unique(['artifact_id', 'path']);
        });
        Schema::create('website_artifact_actions', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('attempt_id')->constrained('website_artifact_attempts')->restrictOnDelete();
            $t->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $t->string('action', 20);
            $t->char('release_hash', 64);
            $t->char('plan_hash', 64);
            $t->char('artifact_hash', 64)->nullable();
            $t->timestampTz('created_at');
        });
        foreach (['website_artifacts', 'website_artifact_files', 'website_artifact_actions'] as $table) {
            DB::unprepared("CREATE TRIGGER {$table}_immutable BEFORE UPDATE OR DELETE OR TRUNCATE ON {$table} FOR EACH STATEMENT EXECUTE FUNCTION website_append_only()");
        }
        DB::unprepared(<<<'SQL'
ALTER TABLE website_artifact_attempts ADD CONSTRAINT artifact_attempt_state CHECK (state IN ('requested','building','built','failed') AND lock_version>0 AND ((state='built')=(artifact_id IS NOT NULL)) AND ((state='failed')=(failure_code IS NOT NULL)) AND (failure_code IS NULL OR failure_code IN ('integrity','approval','version','authority','file_limit','byte_limit','memory_limit','deadline','paths','links','profile','determinism','storage','audit','interrupted','render')));
ALTER TABLE website_artifact_actions ADD CONSTRAINT artifact_action_state CHECK (action IN ('requested','building','built','failed'));
ALTER TABLE website_artifacts ADD CONSTRAINT artifact_bounds CHECK (file_count BETWEEN 1 AND 2000 AND total_bytes BETWEEN 0 AND 262144000 AND profile_id='review-noindex-v1');
ALTER TABLE website_artifact_files ADD CONSTRAINT artifact_file_source CHECK (bytes>=0 AND encrypted AND ((storage_kind='generated' AND storage_key IS NOT NULL AND release_media_id IS NULL AND plan_asset_id IS NULL) OR (storage_kind='release_media' AND storage_key IS NULL AND release_media_id IS NOT NULL AND plan_asset_id IS NULL) OR (storage_kind='plan_asset' AND storage_key IS NULL AND release_media_id IS NULL AND plan_asset_id IS NOT NULL)));
CREATE OR REPLACE FUNCTION website_artifact_attempt_guard() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN
 IF TG_OP='INSERT' THEN IF NEW.state<>'requested' OR NEW.lock_version<>1 THEN RAISE EXCEPTION 'Invalid initial attempt'; END IF; RETURN NEW; END IF;
 IF TG_OP<>'UPDATE' THEN RAISE EXCEPTION 'Artifact attempts are retained'; END IF;
 IF (to_jsonb(NEW)-'state'-'lock_version'-'failure_code'-'artifact_id') IS DISTINCT FROM (to_jsonb(OLD)-'state'-'lock_version'-'failure_code'-'artifact_id') OR NEW.lock_version<>OLD.lock_version+1 OR NOT ((OLD.state='requested' AND NEW.state IN ('building','failed')) OR (OLD.state='building' AND NEW.state IN ('built','failed'))) THEN RAISE EXCEPTION 'Invalid artifact attempt transition'; END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER artifact_attempt_guard BEFORE INSERT OR UPDATE OR DELETE ON website_artifact_attempts FOR EACH ROW EXECUTE FUNCTION website_artifact_attempt_guard();
CREATE TRIGGER artifact_attempt_no_truncate BEFORE TRUNCATE ON website_artifact_attempts FOR EACH STATEMENT EXECUTE FUNCTION website_append_only();
CREATE OR REPLACE FUNCTION website_artifact_counter_guard() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN
 IF TG_OP<>'UPDATE' OR NEW.organisation_id<>OLD.organisation_id OR NEW.year<>OLD.year OR NEW.last_number<>OLD.last_number+1 THEN RAISE EXCEPTION 'Artifact counters are retained and monotonic'; END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER artifact_counter_guard BEFORE UPDATE OR DELETE ON website_artifact_counters FOR EACH ROW EXECUTE FUNCTION website_artifact_counter_guard();
CREATE TRIGGER artifact_counter_no_truncate BEFORE TRUNCATE ON website_artifact_counters FOR EACH STATEMENT EXECUTE FUNCTION website_append_only();
CREATE OR REPLACE FUNCTION website_artifact_insert_guard() RETURNS trigger LANGUAGE plpgsql AS $$ DECLARE r record; p record; a record; rs text; ps text; BEGIN
 SELECT * INTO r FROM website_releases WHERE id=NEW.release_id FOR UPDATE;
 SELECT state INTO rs FROM website_release_controls WHERE release_id=NEW.release_id FOR UPDATE;
 SELECT * INTO p FROM website_output_plans WHERE id=NEW.plan_id FOR UPDATE;
 SELECT state INTO ps FROM website_output_plan_controls WHERE plan_id=NEW.plan_id FOR UPDATE;
 SELECT * INTO a FROM website_artifact_attempts WHERE id=NEW.attempt_id FOR UPDATE;
 IF rs IS DISTINCT FROM 'approved' OR ps IS DISTINCT FROM 'approved' OR r.organisation_id IS DISTINCT FROM NEW.organisation_id OR p.organisation_id IS DISTINCT FROM NEW.organisation_id OR p.release_id IS DISTINCT FROM r.id OR r.number IS DISTINCT FROM NEW.release_number OR r.manifest_hash IS DISTINCT FROM NEW.release_manifest_hash OR p.number IS DISTINCT FROM NEW.plan_number OR p.plan_hash IS DISTINCT FROM NEW.plan_hash OR a.state IS DISTINCT FROM 'building' OR a.release_id IS DISTINCT FROM r.id OR a.plan_id IS DISTINCT FROM p.id OR a.organisation_id IS DISTINCT FROM NEW.organisation_id OR a.actor_user_id IS DISTINCT FROM NEW.created_by_user_id THEN RAISE EXCEPTION 'Artifact requires exact approved sources and attempt'; END IF;
 IF encode(sha256(convert_to(NEW.file_manifest,'UTF8')),'hex')<>NEW.file_manifest_hash OR encode(sha256(convert_to(NEW.identity_manifest,'UTF8')),'hex')<>NEW.artifact_hash OR NEW.identity_manifest::jsonb->'files' IS DISTINCT FROM NEW.file_manifest::jsonb THEN RAISE EXCEPTION 'Invalid artifact identity'; END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER artifact_insert_guard BEFORE INSERT ON website_artifacts FOR EACH ROW EXECUTE FUNCTION website_artifact_insert_guard();
CREATE OR REPLACE FUNCTION website_artifact_file_guard() RETURNS trigger LANGUAGE plpgsql AS $$ DECLARE a record; m record; s text; token uuid; BEGIN
 SELECT * INTO a FROM website_artifacts WHERE id=NEW.artifact_id FOR UPDATE;
 SELECT state INTO s FROM website_artifact_attempts WHERE id=a.attempt_id;
 IF s IS DISTINCT FROM 'building' THEN RAISE EXCEPTION 'Artifact file index is closed'; END IF;
 SELECT stage_token INTO token FROM website_artifact_attempts WHERE id=a.attempt_id;
 IF NEW.storage_kind='generated' AND NEW.storage_key IS DISTINCT FROM 'attempts/'||token||'/'||encode(sha256(convert_to(NEW.path,'UTF8')),'hex')||'.enc' THEN RAISE EXCEPTION 'Wrong generated storage key'; END IF;
 IF NEW.storage_kind='release_media' THEN
 SELECT * INTO m FROM website_release_media WHERE id=NEW.release_media_id;
 IF m.release_id IS DISTINCT FROM a.release_id OR m.sha256 IS DISTINCT FROM NEW.sha256 OR m.bytes IS DISTINCT FROM NEW.bytes OR m.mime IS DISTINCT FROM NEW.mime OR NEW.path IS DISTINCT FROM 'assets/img/'||m.filename THEN RAISE EXCEPTION 'Wrong release media'; END IF;
 ELSIF NEW.storage_kind='plan_asset' THEN
 SELECT * INTO m FROM website_output_plan_assets WHERE id=NEW.plan_asset_id;
 IF m.plan_id IS DISTINCT FROM a.plan_id OR m.sha256 IS DISTINCT FROM NEW.sha256 OR m.bytes IS DISTINCT FROM NEW.bytes OR m.mime IS DISTINCT FROM NEW.mime OR NEW.path IS DISTINCT FROM 'assets/img/'||m.sha256||(CASE m.mime WHEN 'image/jpeg' THEN '.jpg' WHEN 'image/png' THEN '.png' WHEN 'image/webp' THEN '.webp' ELSE '.invalid' END) THEN RAISE EXCEPTION 'Wrong plan logo'; END IF;
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER artifact_file_guard BEFORE INSERT ON website_artifact_files FOR EACH ROW EXECUTE FUNCTION website_artifact_file_guard();
CREATE OR REPLACE FUNCTION website_artifact_complete() RETURNS trigger LANGUAGE plpgsql AS $$ DECLARE a record; t record; files jsonb; n bigint; b bigint; BEGIN
 SELECT * INTO t FROM website_artifact_attempts WHERE id=NEW.id;
 SELECT * INTO a FROM website_artifacts WHERE attempt_id=NEW.id;
 IF t.state='built' THEN
 SELECT count(*),coalesce(sum(bytes),0),jsonb_agg(jsonb_build_object('path',path,'mime',mime,'bytes',bytes,'sha256',sha256) ORDER BY path COLLATE "C") INTO n,b,files FROM website_artifact_files WHERE artifact_id=a.id;
 IF a.id IS DISTINCT FROM t.artifact_id OR n IS DISTINCT FROM a.file_count::bigint OR b IS DISTINCT FROM a.total_bytes OR files IS DISTINCT FROM a.file_manifest::jsonb OR NOT EXISTS(SELECT 1 FROM website_artifact_actions WHERE attempt_id=NEW.id AND action='built' AND artifact_hash=a.artifact_hash) THEN RAISE EXCEPTION 'Incomplete built artifact'; END IF;
 ELSIF a.id IS NOT NULL THEN RAISE EXCEPTION 'Artifact without built attempt'; END IF;
 RETURN NULL;
END $$;
CREATE CONSTRAINT TRIGGER artifact_attempt_complete AFTER INSERT OR UPDATE ON website_artifact_attempts DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION website_artifact_complete();
SQL);
    }

    public function down(): void {} // Retain all successful and failed evidence; no permission is revoked.
};
