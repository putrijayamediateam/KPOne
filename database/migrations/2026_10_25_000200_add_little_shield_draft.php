<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE website_content_items DROP CONSTRAINT website_type_only');
        DB::statement("ALTER TABLE website_content_items ADD CONSTRAINT website_type_only CHECK (lock_version > 0 AND ((type = 'branch_public_profile' AND branch_id IS NOT NULL AND archived_at IS NULL) OR (type IN ('doctor_profile','career_posting','service_category','public_service','promotion','activity','faq') AND branch_id IS NULL) OR (type = 'little_shield' AND branch_id IS NULL AND archived_at IS NULL)))");
        DB::statement("CREATE UNIQUE INDEX website_little_shield_singleton ON website_content_items(organisation_id) WHERE type='little_shield'");
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION website_little_shield_revision_guard() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE item website_content_items%ROWTYPE; asset_public text;
BEGIN
 SELECT * INTO STRICT item FROM website_content_items WHERE id=NEW.item_id;
 IF item.type<>'little_shield' THEN RETURN NEW; END IF;
 PERFORM 1 FROM organisations WHERE id=item.organisation_id FOR UPDATE;
 FOR asset_public IN SELECT value FROM jsonb_array_elements_text(COALESCE(NEW.content->'gallery_public_ids','[]'::jsonb)) UNION SELECT NEW.content->>'cover_public_id' WHERE NULLIF(NEW.content->>'cover_public_id','') IS NOT NULL LOOP
  IF NOT website_is_reference_copy(NEW) AND NOT EXISTS(SELECT 1 FROM website_media_assets WHERE public_id::text=lower(asset_public) AND organisation_id=item.organisation_id AND retired_at IS NULL AND (NOT identifiable_person OR btrim(consent_reference)<>'')) THEN RAISE EXCEPTION 'Choose active sanitised media with required consent'; END IF;
 END LOOP;
 RETURN NEW;
END;
$$;
CREATE TRIGGER website_little_shield_revision_guard BEFORE INSERT ON website_content_revisions FOR EACH ROW EXECUTE FUNCTION website_little_shield_revision_guard();
SQL);
    }

    public function down(): void {} // Retain draft evidence and protections.
};
