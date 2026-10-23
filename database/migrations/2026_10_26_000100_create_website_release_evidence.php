<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('website_release_counters', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('organisation_id')->constrained()->restrictOnDelete();
            $t->integer('year');
            $t->integer('last_number');
            $t->unique(['organisation_id', 'year']);
        });
        Schema::create('website_releases', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('organisation_id')->constrained()->restrictOnDelete();
            $t->string('number', 30);
            $t->char('manifest_hash', 64);
            $t->integer('manifest_schema_version');
            $t->text('manifest');
            $t->jsonb('omissions');
            $t->foreignId('created_by_user_id')->constrained('users')->restrictOnDelete();
            $t->timestampTz('created_at');
            $t->unique(['organisation_id', 'number']);
            $t->unique(['id', 'organisation_id']);
        });
        Schema::create('website_release_controls', function (Blueprint $t): void {
            $t->foreignId('release_id')->primary()->constrained('website_releases')->restrictOnDelete();
            $t->string('state', 20);
            $t->integer('lock_version');
        });
        Schema::create('website_release_items', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('release_id')->constrained('website_releases')->restrictOnDelete();
            $t->foreignId('source_item_id')->constrained('website_content_items')->restrictOnDelete();
            $t->foreignId('source_revision_id')->constrained('website_content_revisions')->restrictOnDelete();
            $t->string('item_key', 40);
            $t->string('type', 40);
            $t->string('language', 2);
            $t->char('revision_hash', 64);
            $t->char('content_hash', 64);
            $t->jsonb('projection');
            $t->unique(['release_id', 'item_key', 'language']);
        });
        Schema::create('website_release_media', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('release_id')->constrained('website_releases')->restrictOnDelete();
            $t->foreignId('source_asset_id')->constrained('website_media_assets')->restrictOnDelete();
            $t->char('sha256', 64);
            $t->string('filename', 80);
            $t->string('mime', 30);
            $t->integer('bytes');
            $t->integer('width');
            $t->integer('height');
            $t->string('alt_en', 200);
            $t->string('alt_bm', 200);
            $t->text('encrypted_bytes');
            $t->unique(['release_id', 'filename']);
        });
        Schema::create('website_release_actions', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('release_id')->constrained('website_releases')->restrictOnDelete();
            $t->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $t->string('action', 20);
            $t->char('manifest_hash', 64);
            $t->string('reason', 300)->nullable();
            $t->timestampTz('created_at');
        });
        Schema::create('website_release_commands', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('organisation_id')->constrained()->restrictOnDelete();
            $t->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $t->uuid('idempotency_key');
            $t->char('payload_hash', 64);
            $t->foreignId('release_id')->constrained('website_releases')->restrictOnDelete();
            $t->string('operation', 20);
            $t->timestampTz('created_at');
            $t->unique(['organisation_id', 'idempotency_key']);
        });
        foreach (['website_releases', 'website_release_items', 'website_release_media', 'website_release_actions', 'website_release_commands'] as $table) {
            DB::unprepared("CREATE TRIGGER {$table}_immutable BEFORE UPDATE OR DELETE OR TRUNCATE ON {$table} FOR EACH STATEMENT EXECUTE FUNCTION website_append_only()");
        }
        DB::statement("ALTER TABLE website_release_controls ADD CONSTRAINT website_release_state CHECK (state IN ('prepared','approved','refused','withdrawn') AND lock_version>0)");
        DB::statement("ALTER TABLE website_release_actions ADD CONSTRAINT website_release_action CHECK (action IN ('prepared','approved','refused','withdrawn'))");
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION website_release_control_guard() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN
 IF TG_OP <> 'UPDATE' THEN RAISE EXCEPTION 'Release control is retained'; END IF;
 IF NEW.release_id<>OLD.release_id OR NEW.lock_version<>OLD.lock_version+1 OR NOT ((OLD.state='prepared' AND NEW.state IN ('approved','refused')) OR (OLD.state='approved' AND NEW.state='withdrawn')) THEN RAISE EXCEPTION 'Invalid release transition'; END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER website_release_control_guard BEFORE UPDATE OR DELETE ON website_release_controls FOR EACH ROW EXECUTE FUNCTION website_release_control_guard();
CREATE TRIGGER website_release_controls_no_truncate BEFORE TRUNCATE ON website_release_controls FOR EACH STATEMENT EXECUTE FUNCTION website_append_only();
CREATE OR REPLACE FUNCTION website_release_counter_guard() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN
 IF TG_OP<>'UPDATE' THEN RAISE EXCEPTION 'Release counters are retained'; END IF;
 IF NEW.organisation_id<>OLD.organisation_id OR NEW.year<>OLD.year OR NEW.last_number<>OLD.last_number+1 THEN RAISE EXCEPTION 'Release numbers cannot be reused'; END IF; RETURN NEW;
END $$;
CREATE TRIGGER website_release_counter_guard BEFORE UPDATE OR DELETE ON website_release_counters FOR EACH ROW EXECUTE FUNCTION website_release_counter_guard();
CREATE TRIGGER website_release_counters_no_truncate BEFORE TRUNCATE ON website_release_counters FOR EACH STATEMENT EXECUTE FUNCTION website_append_only();
SQL);
    }

    public function down(): void {} // Retain all release evidence and counters.
};
