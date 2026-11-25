<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * STK-60 (owner decision 19, TEAM.md decision 47; built to the STK-47-60 design note): the organisation's weekly store
 * request day, ISO weekday 1 (Monday) to 7 (Sunday). An organisation with no row uses Thursday. Every saved change also
 * appends one version row (who, when, the day, the retry key); versions are append-only. It only reminds and warns: no
 * stock request or movement reads it to refuse anything. No new permission.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_store_settings', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('organisation_id')->unique()->constrained()->restrictOnDelete();
            $t->unsignedSmallInteger('request_weekday');
            $t->unsignedInteger('lock_version')->default(1);
            $t->foreignId('updated_by_user_id')->constrained('users')->restrictOnDelete();
            $t->timestampsTz();
        });
        DB::statement('ALTER TABLE inventory_store_settings ADD CONSTRAINT inventory_store_settings_shape CHECK (request_weekday BETWEEN 1 AND 7 AND lock_version >= 1)');

        Schema::create('inventory_store_setting_versions', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('organisation_id')->constrained()->restrictOnDelete();
            $t->foreignId('setting_id')->constrained('inventory_store_settings')->restrictOnDelete();
            $t->unsignedInteger('version');
            $t->unsignedSmallInteger('request_weekday');
            $t->foreignId('changed_by_user_id')->constrained('users')->restrictOnDelete();
            $t->uuid('idempotency_key');
            $t->string('payload_hash', 64);
            $t->timestampTz('created_at');
            $t->unique(['setting_id', 'version'], 'store_setting_versions_version_unique');
            $t->unique(['organisation_id', 'idempotency_key'], 'store_setting_versions_retry_unique');
        });
        DB::statement('ALTER TABLE inventory_store_setting_versions ADD CONSTRAINT store_setting_versions_shape CHECK (request_weekday BETWEEN 1 AND 7 AND version >= 1)');
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION guard_inventory_store_setting_version() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE s inventory_store_settings%ROWTYPE;
BEGIN
 IF TG_OP<>'INSERT' THEN RAISE EXCEPTION 'Store setting versions are append-only' USING ERRCODE='P0001'; END IF;
 SELECT * INTO s FROM inventory_store_settings WHERE id=NEW.setting_id;
 IF s.id IS NULL OR s.organisation_id<>NEW.organisation_id OR s.lock_version<>NEW.version OR s.request_weekday<>NEW.request_weekday
    OR NOT EXISTS (SELECT 1 FROM users u WHERE u.id=NEW.changed_by_user_id AND u.organisation_id=NEW.organisation_id) THEN
  RAISE EXCEPTION 'A store setting version must match the saved setting' USING ERRCODE='23514';
 END IF;
 RETURN NEW;
END; $$;
CREATE TRIGGER inventory_store_setting_versions_append BEFORE INSERT OR UPDATE OR DELETE ON inventory_store_setting_versions FOR EACH ROW EXECUTE FUNCTION guard_inventory_store_setting_version();
CREATE TRIGGER inventory_store_setting_versions_truncate BEFORE TRUNCATE ON inventory_store_setting_versions FOR EACH STATEMENT EXECUTE FUNCTION guard_inventory_store_setting_version();
SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS inventory_store_setting_versions_truncate ON inventory_store_setting_versions; DROP TRIGGER IF EXISTS inventory_store_setting_versions_append ON inventory_store_setting_versions;');
        Schema::dropIfExists('inventory_store_setting_versions');
        Schema::dropIfExists('inventory_store_settings');
        DB::unprepared('DROP FUNCTION IF EXISTS guard_inventory_store_setting_version()');
    }
};
