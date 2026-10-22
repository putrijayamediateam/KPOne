<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE website_content_items DROP CONSTRAINT website_type_only');
        DB::statement('ALTER TABLE website_content_items DROP CONSTRAINT website_content_items_branch_id_unique');
        DB::statement('ALTER TABLE website_content_items ALTER COLUMN branch_id DROP NOT NULL');
        Schema::table('website_content_items', function (Blueprint $t): void {
            $t->timestampTz('archived_at')->nullable();
        });
        DB::statement("ALTER TABLE website_content_items ADD CONSTRAINT website_type_only CHECK (lock_version > 0 AND ((type = 'branch_public_profile' AND branch_id IS NOT NULL AND archived_at IS NULL) OR (type IN ('doctor_profile','career_posting') AND branch_id IS NULL)))");
        DB::statement("CREATE UNIQUE INDEX website_branch_profile_unique ON website_content_items (branch_id) WHERE type = 'branch_public_profile'");
        Schema::create('website_content_item_branches', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('organisation_id');
            $t->unsignedBigInteger('item_id');
            $t->unsignedBigInteger('branch_id');
            $t->foreign(['item_id', 'organisation_id'])->references(['id', 'organisation_id'])->on('website_content_items')->restrictOnDelete();
            $t->foreign(['branch_id', 'organisation_id'])->references(['id', 'organisation_id'])->on('branches')->restrictOnDelete();
            $t->unique(['item_id', 'branch_id']);
        });
        // Branch-profile saves are deliberately left unchanged; all item identities are immutable.
        DB::unprepared("CREATE OR REPLACE FUNCTION website_item_identity_guard() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN IF TG_OP <> 'UPDATE' THEN RAISE EXCEPTION 'Website items are retained'; END IF; IF (to_jsonb(NEW) - 'lock_version' - 'archived_at') IS DISTINCT FROM (to_jsonb(OLD) - 'lock_version' - 'archived_at') OR (NEW.type <> 'branch_public_profile' AND NEW.lock_version <> OLD.lock_version + 1) THEN RAISE EXCEPTION 'Website item identity is immutable and version must advance'; END IF; RETURN NEW; END; \$\$");
        DB::unprepared('CREATE TRIGGER website_item_identity_guard BEFORE UPDATE OR DELETE ON website_content_items FOR EACH ROW EXECUTE FUNCTION website_item_identity_guard()');
        DB::unprepared('CREATE TRIGGER website_items_no_truncate BEFORE TRUNCATE ON website_content_items FOR EACH STATEMENT EXECUTE FUNCTION website_append_only()');
    }

    public function down(): void {} // Retain content and all evidence.
};
