<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE website_content_items DROP CONSTRAINT website_type_only');
        DB::statement("ALTER TABLE website_content_items ADD CONSTRAINT website_type_only CHECK (lock_version > 0 AND ((type = 'branch_public_profile' AND branch_id IS NOT NULL AND archived_at IS NULL) OR (type IN ('doctor_profile','career_posting','service_category','public_service','promotion','activity','faq') AND branch_id IS NULL)))");
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION website_editorial_revision_guard() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE item website_content_items%ROWTYPE; other jsonb; linked text; asset_public text;
BEGIN
 SELECT * INTO STRICT item FROM website_content_items WHERE id=NEW.item_id;
 IF item.type NOT IN ('promotion','activity','faq') THEN RETURN NEW; END IF;
 PERFORM 1 FROM organisations WHERE id=item.organisation_id FOR UPDATE;
 IF item.archived_at IS NOT NULL THEN RAISE EXCEPTION 'Unarchive before editing'; END IF;
 SELECT content INTO other FROM website_content_revisions WHERE item_id=NEW.item_id AND language<>NEW.language ORDER BY revision_number DESC LIMIT 1;
 IF FOUND AND (COALESCE(other->'_branch_ids','[]'::jsonb) IS DISTINCT FROM COALESCE(NEW.content->'_branch_ids','[]'::jsonb) OR lower(other->>'linked_service_public_id') IS DISTINCT FROM lower(NEW.content->>'linked_service_public_id')) THEN RAISE EXCEPTION 'Branches and linked service must match the other language'; END IF;
 IF item.type='faq' THEN
  IF COALESCE(NEW.content->'_branch_ids','[]'::jsonb)<>'[]'::jsonb THEN RAISE EXCEPTION 'FAQs are organisation-wide'; END IF;
  linked:=NEW.content->>'linked_service_public_id';
  IF linked IS NOT NULL AND NOT EXISTS(SELECT 1 FROM website_content_items WHERE public_id::text=lower(linked) AND organisation_id=item.organisation_id AND type='public_service' AND archived_at IS NULL) THEN RAISE EXCEPTION 'Choose an active public service from this organisation'; END IF;
 END IF;
 FOR asset_public IN SELECT value FROM jsonb_array_elements_text(COALESCE(NEW.content->'gallery_public_ids','[]'::jsonb)) UNION SELECT NEW.content->>'cover_public_id' WHERE NULLIF(NEW.content->>'cover_public_id','') IS NOT NULL LOOP
  IF NOT EXISTS(SELECT 1 FROM website_media_assets WHERE public_id::text=lower(asset_public) AND organisation_id=item.organisation_id AND retired_at IS NULL AND (NOT identifiable_person OR btrim(consent_reference)<>'')) THEN RAISE EXCEPTION 'Choose active sanitised media with required consent'; END IF;
 END LOOP;
 RETURN NEW;
END;
$$;
CREATE TRIGGER website_editorial_revision_guard BEFORE INSERT ON website_content_revisions FOR EACH ROW EXECUTE FUNCTION website_editorial_revision_guard();
CREATE OR REPLACE FUNCTION website_faq_branch_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF EXISTS(SELECT 1 FROM website_content_items WHERE id=NEW.item_id AND type='faq') THEN RAISE EXCEPTION 'FAQs are organisation-wide'; END IF;
 RETURN NEW;
END;
$$;
CREATE TRIGGER website_faq_branch_guard BEFORE INSERT OR UPDATE ON website_content_item_branches FOR EACH ROW EXECUTE FUNCTION website_faq_branch_guard();
SQL);
    }

    public function down(): void {} // Retain all drafts and append-only evidence.
};
