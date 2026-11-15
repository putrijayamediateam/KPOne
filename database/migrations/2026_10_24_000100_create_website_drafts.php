<?php

use App\Domain\Organisation\Website\WebsiteDraftSeeds;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('website_media_assets', function (Blueprint $t): void {
            $t->id();
            $t->uuid('public_id')->unique();
            $t->foreignId('organisation_id')->constrained()->restrictOnDelete();
            $t->string('path')->unique();
            $t->string('mime', 30);
            $t->char('sha256', 64);
            $t->unsignedInteger('bytes');
            $t->unsignedInteger('width');
            $t->unsignedInteger('height');
            $t->string('alt_en', 200);
            $t->string('alt_bm', 200);
            $t->string('rights_reference', 200);
            $t->boolean('identifiable_person');
            $t->string('consent_reference', 200);
            $t->foreignId('uploaded_by_user_id')->constrained('users')->restrictOnDelete();
            $t->timestampTz('retired_at')->nullable();
            $t->unsignedInteger('lock_version')->default(1);
            $t->timestampTz('created_at');
            $t->unique(['id', 'organisation_id']);
        });
        Schema::create('website_content_items', function (Blueprint $t): void {
            $t->id();
            $t->uuid('public_id')->unique();
            $t->foreignId('organisation_id')->constrained()->restrictOnDelete();
            $t->unsignedBigInteger('branch_id')->unique();
            $t->foreign(['branch_id', 'organisation_id'])->references(['id', 'organisation_id'])->on('branches')->restrictOnDelete();
            $t->string('type', 40);
            $t->string('slug', 100);
            $t->unsignedInteger('lock_version')->default(1);
            $t->timestampTz('created_at');
            $t->unique(['organisation_id', 'type', 'slug']);
            $t->unique(['id', 'organisation_id']);
        });
        Schema::create('website_content_revisions', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('organisation_id');
            $t->unsignedBigInteger('item_id');
            $t->foreign(['item_id', 'organisation_id'])->references(['id', 'organisation_id'])->on('website_content_items')->restrictOnDelete();
            $t->string('language', 2);
            $t->unsignedInteger('revision_number');
            $t->jsonb('content');
            $t->unsignedBigInteger('hero_asset_id')->nullable();
            $t->foreign(['hero_asset_id', 'organisation_id'])->references(['id', 'organisation_id'])->on('website_media_assets')->restrictOnDelete();
            $t->boolean('is_seed_copy')->default(false);
            $t->foreignId('created_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestampTz('created_at');
            $t->unique(['item_id', 'language', 'revision_number']);
        });
        Schema::create('website_draft_commands', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('organisation_id')->constrained()->restrictOnDelete();
            $t->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $t->uuid('idempotency_key');
            $t->string('operation', 20);
            $t->char('payload_hash', 64);
            $t->unsignedBigInteger('result_id');
            $t->timestampTz('created_at');
            $t->unique(['organisation_id', 'idempotency_key']);
        });
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE website_content_items ADD CONSTRAINT website_type_only CHECK (type = 'branch_public_profile' AND lock_version > 0)");
            DB::statement("ALTER TABLE website_content_revisions ADD CONSTRAINT website_language_revision CHECK (language IN ('en','bm') AND revision_number > 0)");
            DB::unprepared("CREATE OR REPLACE FUNCTION website_append_only() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN RAISE EXCEPTION 'Website records are append-only'; END; \$\$");
            foreach (['website_content_revisions', 'website_draft_commands'] as $table) {
                DB::unprepared("CREATE TRIGGER {$table}_immutable BEFORE UPDATE OR DELETE OR TRUNCATE ON {$table} FOR EACH STATEMENT EXECUTE FUNCTION website_append_only()");
            }
            DB::unprepared("CREATE OR REPLACE FUNCTION website_asset_guard() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN IF TG_OP <> 'UPDATE' THEN RAISE EXCEPTION 'Website assets are retained'; END IF; IF (to_jsonb(NEW) - 'retired_at' - 'lock_version') IS DISTINCT FROM (to_jsonb(OLD) - 'retired_at' - 'lock_version') OR OLD.retired_at IS NOT NULL OR NEW.retired_at IS NULL OR NEW.lock_version <> OLD.lock_version + 1 THEN RAISE EXCEPTION 'Only asset retirement is permitted'; END IF; RETURN NEW; END; \$\$");
            DB::unprepared('CREATE TRIGGER website_asset_guard BEFORE UPDATE OR DELETE ON website_media_assets FOR EACH ROW EXECUTE FUNCTION website_asset_guard()');
            DB::unprepared('CREATE TRIGGER website_assets_no_truncate BEFORE TRUNCATE ON website_media_assets FOR EACH STATEMENT EXECUTE FUNCTION website_append_only()');
        }
        WebsiteDraftSeeds::seed();
    }

    public function down(): void {} // Retain drafts and referenced media.
};
