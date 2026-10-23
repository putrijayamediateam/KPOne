<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION website_shared_values(kind text, body jsonb) RETURNS jsonb LANGUAGE sql IMMUTABLE AS $$
 SELECT CASE
 WHEN kind='service_category' THEN jsonb_build_object('parent_public_id',lower(body->>'parent_public_id'))
 WHEN kind='public_service' THEN jsonb_build_object('_branch_ids',COALESCE((SELECT jsonb_agg(v ORDER BY v) FROM jsonb_array_elements(COALESCE(body->'_branch_ids','[]'::jsonb)) v),'[]'::jsonb),'parent_public_id',lower(body->>'parent_public_id'))
 WHEN kind='faq' THEN jsonb_build_object('linked_service_public_id',lower(body->>'linked_service_public_id'))
 ELSE jsonb_build_object('_branch_ids',COALESCE((SELECT jsonb_agg(v ORDER BY v) FROM jsonb_array_elements(COALESCE(body->'_branch_ids','[]'::jsonb)) v),'[]'::jsonb)) END;
$$;
CREATE OR REPLACE FUNCTION website_assert_shared_references(target bigint) RETURNS void LANGUAGE plpgsql AS $$
DECLARE kind text; a jsonb; b jsonb;
BEGIN
 SELECT type INTO kind FROM website_content_items WHERE id=target FOR UPDATE;
 IF kind='branch_public_profile' THEN RETURN; END IF;
 SELECT content INTO a FROM website_content_revisions WHERE item_id=target AND language='en' ORDER BY revision_number DESC LIMIT 1;
 SELECT content INTO b FROM website_content_revisions WHERE item_id=target AND language='bm' ORDER BY revision_number DESC LIMIT 1;
 IF a IS NOT NULL AND b IS NOT NULL AND website_shared_values(kind,a) IS DISTINCT FROM website_shared_values(kind,b) THEN RAISE EXCEPTION 'Website shared references must agree across languages'; END IF;
END;
$$;
CREATE OR REPLACE FUNCTION website_shared_reference_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 PERFORM website_assert_shared_references(NEW.item_id); RETURN NULL;
END;
$$;
CREATE OR REPLACE FUNCTION website_has_shared_references(target bigint) RETURNS boolean LANGUAGE sql STABLE AS $$
 SELECT type<>'branch_public_profile' FROM website_content_items WHERE id=target;
$$;
CREATE CONSTRAINT TRIGGER website_shared_references_consistent AFTER INSERT ON website_content_revisions DEFERRABLE INITIALLY DEFERRED FOR EACH ROW WHEN (website_has_shared_references(NEW.item_id)) EXECUTE FUNCTION website_shared_reference_guard();
CREATE OR REPLACE FUNCTION website_is_reference_copy(candidate website_content_revisions) RETURNS boolean LANGUAGE plpgsql AS $$
DECLARE old website_content_revisions%ROWTYPE; kind text; keys text[];
BEGIN
 SELECT type INTO kind FROM website_content_items WHERE id=candidate.item_id;
 SELECT * INTO old FROM website_content_revisions WHERE item_id=candidate.item_id AND language=candidate.language ORDER BY revision_number DESC LIMIT 1;
 IF NOT FOUND OR NOT EXISTS(SELECT 1 FROM website_content_revisions WHERE item_id=candidate.item_id AND language<>candidate.language) THEN RETURN false; END IF;
 keys:=CASE WHEN kind='service_category' THEN ARRAY['parent_public_id'] WHEN kind='public_service' THEN ARRAY['_branch_ids','parent_public_id'] WHEN kind='faq' THEN ARRAY['linked_service_public_id'] ELSE ARRAY['_branch_ids'] END;
 RETURN old.hero_asset_id IS NOT DISTINCT FROM candidate.hero_asset_id AND old.content-keys=candidate.content-keys AND website_shared_values(kind,old.content) IS DISTINCT FROM website_shared_values(kind,candidate.content);
END;
$$;
CREATE OR REPLACE FUNCTION website_active_faq_count(service_public text) RETURNS bigint LANGUAGE sql AS $$
 SELECT count(DISTINCT item_id) FROM (
 SELECT DISTINCT ON(r.item_id,r.language) r.item_id,r.content FROM website_content_revisions r JOIN website_content_items i ON i.id=r.item_id WHERE i.type='faq' AND i.archived_at IS NULL AND i.organisation_id=(SELECT organisation_id FROM website_content_items WHERE public_id::text=lower(service_public)) ORDER BY r.item_id,r.language,r.revision_number DESC
 ) current_refs WHERE lower(content->>'linked_service_public_id')=lower(service_public);
$$;
CREATE OR REPLACE FUNCTION website_service_faq_archive_guard() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE total bigint;
BEGIN
 IF NEW.type='public_service' AND OLD.archived_at IS NULL AND NEW.archived_at IS NOT NULL THEN
 PERFORM 1 FROM organisations WHERE id=NEW.organisation_id FOR UPDATE;
 total:=website_active_faq_count(NEW.public_id::text);
 IF total>0 THEN RAISE EXCEPTION 'Cannot archive: % active FAQs link to this service. Clear their links or archive those FAQs first.',total; END IF;
 END IF; RETURN NEW;
END;
$$;
CREATE TRIGGER website_service_faq_archive_guard BEFORE UPDATE ON website_content_items FOR EACH ROW EXECUTE FUNCTION website_service_faq_archive_guard();
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
  IF NOT website_is_reference_copy(NEW) AND (NOT FOUND OR (asset.identifiable_person AND btrim(asset.consent_reference)='')) THEN RAISE EXCEPTION 'Choose active sanitised media with any required consent'; END IF;
 END LOOP;
 RETURN NEW;
END;
$$;
CREATE OR REPLACE FUNCTION website_editorial_revision_guard() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE item website_content_items%ROWTYPE; other jsonb; linked text; asset_public text;
BEGIN
 SELECT * INTO STRICT item FROM website_content_items WHERE id=NEW.item_id;
 IF item.type NOT IN ('promotion','activity','faq') THEN RETURN NEW; END IF;
 PERFORM 1 FROM organisations WHERE id=item.organisation_id FOR UPDATE;
 IF item.archived_at IS NOT NULL THEN RAISE EXCEPTION 'Unarchive before editing'; END IF;
 IF item.type='faq' THEN
  IF COALESCE(NEW.content->'_branch_ids','[]'::jsonb)<>'[]'::jsonb THEN RAISE EXCEPTION 'FAQs are organisation-wide'; END IF;
  linked:=NEW.content->>'linked_service_public_id';
  IF linked IS NOT NULL AND NOT EXISTS(SELECT 1 FROM website_content_items WHERE public_id::text=lower(linked) AND organisation_id=item.organisation_id AND type='public_service' AND archived_at IS NULL) THEN RAISE EXCEPTION 'Choose an active public service from this organisation'; END IF;
 END IF;
 FOR asset_public IN SELECT value FROM jsonb_array_elements_text(COALESCE(NEW.content->'gallery_public_ids','[]'::jsonb)) UNION SELECT NEW.content->>'cover_public_id' WHERE NULLIF(NEW.content->>'cover_public_id','') IS NOT NULL LOOP
  IF NOT website_is_reference_copy(NEW) AND NOT EXISTS(SELECT 1 FROM website_media_assets WHERE public_id::text=lower(asset_public) AND organisation_id=item.organisation_id AND retired_at IS NULL AND (NOT identifiable_person OR btrim(consent_reference)<>'')) THEN RAISE EXCEPTION 'Choose active sanitised media with required consent'; END IF;
 END LOOP;
 RETURN NEW;
END;
$$;
SQL);
    }

    public function down(): void {} // Preserve evidence and consistency protections.
};
