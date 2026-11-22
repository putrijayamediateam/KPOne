<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PNL-25 (Panel pilot work): a Panel claim pack is frozen at first print, as BIL-5 does for invoices. Each distinct
 * printed content of one pack (the same claims) is one immutable, encrypted version; every print of a version is one
 * row labelled ORIGINAL (first print of version 1), REVISED (first print of a later version) or COPY. Retention
 * (owner decision relayed 2026-11-15): kept for 7 years from the claim's last decision or payment, and never deleted
 * automatically in this version. No claim, amount or financial record changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('panel_claim_pack_documents', function (Blueprint $t): void {
            $t->id();
            $t->uuid('public_id')->unique();
            $t->foreignId('organisation_id')->constrained('organisations')->restrictOnDelete();
            $t->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $t->foreignId('panel_id')->constrained('panels')->restrictOnDelete();
            // sha256 of the sorted claim ids: the identity of "this pack" across versions.
            $t->char('pack_key', 64);
            $t->jsonb('claim_ids');
            $t->unsignedInteger('version');
            $t->char('content_sha256', 64);
            // Whether the frozen content prints an identity number; only a holder of the identity permission may open it.
            $t->boolean('shows_identity')->default(false);
            $t->longText('encrypted_snapshot');
            $t->char('ciphertext_sha256', 64);
            $t->foreignId('issued_by_user_id')->constrained('users')->restrictOnDelete();
            $t->timestampTz('issued_at');
            $t->timestampTz('created_at')->useCurrent();
            $t->index(['organisation_id', 'branch_id', 'pack_key']);
        });
        DB::statement("ALTER TABLE panel_claim_pack_documents ADD CONSTRAINT panel_claim_pack_documents_shape CHECK (
            version >= 1 AND pack_key ~ '^[0-9a-f]{64}\$' AND content_sha256 ~ '^[0-9a-f]{64}\$' AND ciphertext_sha256 ~ '^[0-9a-f]{64}\$'
            AND length(encrypted_snapshot) > 50 AND jsonb_typeof(claim_ids) = 'array' AND jsonb_array_length(claim_ids) BETWEEN 1 AND 50)");
        DB::statement('CREATE UNIQUE INDEX panel_claim_pack_documents_version_unique ON panel_claim_pack_documents (organisation_id, branch_id, pack_key, version)');

        Schema::create('panel_claim_pack_prints', function (Blueprint $t): void {
            $t->id();
            $t->uuid('public_id')->unique();
            $t->foreignId('organisation_id')->constrained('organisations')->restrictOnDelete();
            $t->foreignId('panel_claim_pack_document_id')->constrained('panel_claim_pack_documents')->restrictOnDelete();
            $t->string('label', 10);
            $t->foreignId('printed_by_user_id')->constrained('users')->restrictOnDelete();
            $t->timestampTz('printed_at');
            $t->uuid('idempotency_key');
            $t->char('payload_hash', 64);
            $t->timestampTz('created_at')->useCurrent();
            $t->unique(['organisation_id', 'idempotency_key']);
        });
        DB::statement("ALTER TABLE panel_claim_pack_prints ADD CONSTRAINT panel_claim_pack_prints_shape CHECK (label IN ('original', 'revised', 'copy') AND payload_hash ~ '^[0-9a-f]{64}\$')");

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION protect_panel_claim_pack_documents() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN RAISE EXCEPTION 'Panel claim pack copies are immutable' USING ERRCODE = '0A000'; END; $$;
CREATE TRIGGER panel_claim_pack_documents_immutable BEFORE UPDATE OR DELETE ON panel_claim_pack_documents FOR EACH ROW EXECUTE FUNCTION protect_panel_claim_pack_documents();
CREATE TRIGGER panel_claim_pack_documents_no_truncate BEFORE TRUNCATE ON panel_claim_pack_documents FOR EACH STATEMENT EXECUTE FUNCTION protect_panel_claim_pack_documents();
CREATE TRIGGER panel_claim_pack_prints_immutable BEFORE UPDATE OR DELETE ON panel_claim_pack_prints FOR EACH ROW EXECUTE FUNCTION protect_panel_claim_pack_documents();
CREATE TRIGGER panel_claim_pack_prints_no_truncate BEFORE TRUNCATE ON panel_claim_pack_prints FOR EACH STATEMENT EXECUTE FUNCTION protect_panel_claim_pack_documents();

-- A pack copy holds claims of one Panel in its own organisation and branch, and versions follow on without gaps.
CREATE OR REPLACE FUNCTION guard_panel_claim_pack_document() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE latest integer; matched integer;
BEGIN
  SELECT count(*) INTO matched FROM panel_claims c
    WHERE c.organisation_id = NEW.organisation_id AND c.branch_id = NEW.branch_id AND c.panel_id = NEW.panel_id
      AND c.id IN (SELECT jsonb_array_elements_text(NEW.claim_ids)::bigint);
  IF matched <> jsonb_array_length(NEW.claim_ids) OR matched <> (SELECT count(DISTINCT v) FROM jsonb_array_elements_text(NEW.claim_ids) AS v) THEN
    RAISE EXCEPTION 'Pack claims must be distinct claims of one Panel at this branch' USING ERRCODE = '23514';
  END IF;
  PERFORM pg_advisory_xact_lock(hashtextextended(NEW.organisation_id::text || ':' || NEW.branch_id::text || ':' || NEW.pack_key, 0));
  SELECT max(version) INTO latest FROM panel_claim_pack_documents
    WHERE organisation_id = NEW.organisation_id AND branch_id = NEW.branch_id AND pack_key = NEW.pack_key;
  IF NEW.version <> COALESCE(latest, 0) + 1 THEN
    RAISE EXCEPTION 'Pack copy versions must follow on' USING ERRCODE = '23514';
  END IF;
  RETURN NEW;
END; $$;
CREATE TRIGGER panel_claim_pack_documents_guard BEFORE INSERT ON panel_claim_pack_documents FOR EACH ROW EXECUTE FUNCTION guard_panel_claim_pack_document();

-- ORIGINAL only for the first print of version 1, REVISED only for the first print of a later version, COPY otherwise.
CREATE OR REPLACE FUNCTION guard_panel_claim_pack_print() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE doc panel_claim_pack_documents%ROWTYPE; printed boolean;
BEGIN
  SELECT * INTO doc FROM panel_claim_pack_documents WHERE id = NEW.panel_claim_pack_document_id;
  IF doc.organisation_id <> NEW.organisation_id OR NOT EXISTS (SELECT 1 FROM users WHERE id = NEW.printed_by_user_id AND organisation_id = NEW.organisation_id) THEN
    RAISE EXCEPTION 'Print must stay in its organisation' USING ERRCODE = '23514';
  END IF;
  PERFORM pg_advisory_xact_lock(hashtextextended(doc.organisation_id::text || ':' || doc.branch_id::text || ':' || doc.pack_key, 0));
  printed := EXISTS (SELECT 1 FROM panel_claim_pack_prints WHERE panel_claim_pack_document_id = doc.id);
  IF (NEW.label = 'copy') <> printed OR (NEW.label = 'original' AND doc.version <> 1) OR (NEW.label = 'revised' AND doc.version = 1) THEN
    RAISE EXCEPTION 'Print label does not match the version' USING ERRCODE = '23514';
  END IF;
  RETURN NEW;
END; $$;
CREATE TRIGGER panel_claim_pack_prints_guard BEFORE INSERT ON panel_claim_pack_prints FOR EACH ROW EXECUTE FUNCTION guard_panel_claim_pack_print();
SQL);
    }

    public function down(): void
    {
        // Evidence is retained: rolling back is refused once it exists.
        if (DB::table('panel_claim_pack_documents')->exists()) {
            throw new RuntimeException('Panel claim pack copies exist; they are retained evidence.');
        }
        Schema::dropIfExists('panel_claim_pack_prints');
        Schema::dropIfExists('panel_claim_pack_documents');
        foreach (['protect_panel_claim_pack_documents', 'guard_panel_claim_pack_document', 'guard_panel_claim_pack_print'] as $function) {
            DB::statement("DROP FUNCTION IF EXISTS {$function}()");
        }
    }
};
