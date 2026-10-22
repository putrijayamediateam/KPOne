<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE website_content_items DROP CONSTRAINT website_type_only');
        DB::statement("ALTER TABLE website_content_items ADD CONSTRAINT website_type_only CHECK (lock_version > 0 AND ((type = 'branch_public_profile' AND branch_id IS NOT NULL AND archived_at IS NULL) OR (type IN ('doctor_profile','career_posting','service_category','public_service') AND branch_id IS NULL)))");
        // Parent references live in each immutable language revision, not a second mutable hierarchy.
        DB::statement("CREATE INDEX website_revision_parent_lookup ON website_content_revisions ((lower(content->>'parent_public_id')), item_id, language, revision_number DESC) WHERE jsonb_exists(content, 'parent_public_id')");
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION website_service_has_children(parent_public text, categories_only boolean DEFAULT false) RETURNS boolean LANGUAGE sql AS $$
 SELECT EXISTS (
  SELECT 1 FROM (
   SELECT DISTINCT ON (r.item_id,r.language) r.item_id,r.content FROM website_content_revisions r
   JOIN website_content_items i ON i.id=r.item_id
   WHERE i.type IN ('service_category','public_service') AND i.archived_at IS NULL
   AND (NOT categories_only OR i.type='service_category')
   ORDER BY r.item_id,r.language,r.revision_number DESC
  ) current_revisions WHERE lower(content->>'parent_public_id')=lower(parent_public)
 );
$$;
CREATE OR REPLACE FUNCTION website_service_parent_check(child_id bigint, parent_public text) RETURNS void LANGUAGE plpgsql AS $$
DECLARE child website_content_items%ROWTYPE; parent website_content_items%ROWTYPE;
BEGIN
 SELECT * INTO STRICT child FROM website_content_items WHERE id=child_id;
 IF parent_public IS NULL OR parent_public='' THEN RETURN; END IF;
 SELECT * INTO parent FROM website_content_items WHERE public_id::text=lower(parent_public) AND organisation_id=child.organisation_id AND type='service_category' AND archived_at IS NULL;
 IF NOT FOUND OR parent.id=child.id THEN RAISE EXCEPTION 'Choose an active category from this organisation'; END IF;
 IF child.type='service_category' AND (
  website_service_has_children(child.public_id::text,true) OR EXISTS (
   SELECT 1 FROM (SELECT DISTINCT ON(language) content FROM website_content_revisions WHERE item_id=parent.id ORDER BY language,revision_number DESC) latest WHERE NULLIF(content->>'parent_public_id','') IS NOT NULL
  )) THEN RAISE EXCEPTION 'Only two category levels are allowed'; END IF;
END;
$$;
CREATE OR REPLACE FUNCTION website_service_revision_guard() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE item website_content_items%ROWTYPE; asset_public text; asset website_media_assets%ROWTYPE;
BEGIN
 SELECT * INTO STRICT item FROM website_content_items WHERE id=NEW.item_id;
 IF item.type NOT IN ('service_category','public_service') THEN RETURN NEW; END IF;
 PERFORM 1 FROM organisations WHERE id=item.organisation_id FOR UPDATE;
 IF item.archived_at IS NOT NULL THEN RAISE EXCEPTION 'Unarchive before editing'; END IF;
 PERFORM website_service_parent_check(item.id,NEW.content->>'parent_public_id');
 FOR asset_public IN SELECT value FROM jsonb_array_elements_text(COALESCE(NEW.content->'gallery_public_ids','[]'::jsonb)) UNION SELECT NEW.content->>'cover_public_id' WHERE NULLIF(NEW.content->>'cover_public_id','') IS NOT NULL LOOP
  SELECT * INTO asset FROM website_media_assets WHERE public_id::text=lower(asset_public) AND organisation_id=item.organisation_id AND retired_at IS NULL;
  IF NOT FOUND OR (asset.identifiable_person AND btrim(asset.consent_reference)='') THEN RAISE EXCEPTION 'Choose active sanitised media with any required consent'; END IF;
 END LOOP;
 RETURN NEW;
END;
$$;
CREATE TRIGGER website_service_revision_guard BEFORE INSERT ON website_content_revisions FOR EACH ROW EXECUTE FUNCTION website_service_revision_guard();
CREATE OR REPLACE FUNCTION website_service_archive_guard() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE r record;
BEGIN
 IF NEW.type NOT IN ('service_category','public_service') THEN RETURN NEW; END IF;
 PERFORM 1 FROM organisations WHERE id=NEW.organisation_id FOR UPDATE;
 IF OLD.archived_at IS NULL AND NEW.archived_at IS NOT NULL AND NEW.type='service_category' AND website_service_has_children(NEW.public_id::text) THEN RAISE EXCEPTION 'Archive active children before archiving this category'; END IF;
 IF OLD.archived_at IS NOT NULL AND NEW.archived_at IS NULL THEN
  FOR r IN SELECT DISTINCT ON(language) content FROM website_content_revisions WHERE item_id=NEW.id ORDER BY language,revision_number DESC LOOP
   PERFORM website_service_parent_check(NEW.id,r.content->>'parent_public_id');
  END LOOP;
 END IF;
 RETURN NEW;
END;
$$;
CREATE TRIGGER website_service_archive_guard BEFORE UPDATE ON website_content_items FOR EACH ROW EXECUTE FUNCTION website_service_archive_guard();
CREATE OR REPLACE FUNCTION website_category_branch_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF EXISTS(SELECT 1 FROM website_content_items WHERE id=NEW.item_id AND type='service_category') THEN RAISE EXCEPTION 'Categories are organisation-wide'; END IF;
 RETURN NEW;
END;
$$;
CREATE TRIGGER website_category_branch_guard BEFORE INSERT OR UPDATE ON website_content_item_branches FOR EACH ROW EXECUTE FUNCTION website_category_branch_guard();
SQL);
    }

    public function down(): void {} // Keep all drafts, references, and immutable history.
};
