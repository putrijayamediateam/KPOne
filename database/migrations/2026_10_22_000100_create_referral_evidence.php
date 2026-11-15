<?php

use App\Domain\Clinical\Documents\ReferralWording;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TABLE referral_template_revisions (
 id bigserial PRIMARY KEY, language varchar(2) NOT NULL CHECK(language IN ('en','bm')),
 revision integer NOT NULL CHECK(revision = 1), renderer_version varchar(40) NOT NULL CHECK(renderer_version = 'referral-a4-v1'),
 production_approved boolean NOT NULL DEFAULT false CHECK(NOT production_approved), wording jsonb NOT NULL,
 UNIQUE(language, revision)
);
CREATE TABLE referral_chains (
 id bigserial PRIMARY KEY, organisation_id bigint NOT NULL, branch_id bigint NOT NULL, visit_id bigint NOT NULL,
 issuer_user_id bigint NOT NULL, lock_version integer NOT NULL DEFAULT 0 CHECK(lock_version >= 0),
 UNIQUE(id, organisation_id, branch_id, visit_id, issuer_user_id),
 FOREIGN KEY(visit_id, organisation_id, branch_id) REFERENCES visits(id, organisation_id, branch_id) ON DELETE RESTRICT,
 FOREIGN KEY(issuer_user_id, organisation_id) REFERENCES users(id, organisation_id) ON DELETE RESTRICT
);
CREATE TABLE referral_number_counters (
 id bigserial PRIMARY KEY, organisation_id bigint NOT NULL, branch_id bigint NOT NULL, year integer NOT NULL CHECK(year BETWEEN 2000 AND 9999),
 last_number integer NOT NULL CHECK(last_number BETWEEN 1 AND 999999), UNIQUE(organisation_id, branch_id, year),
 FOREIGN KEY(branch_id, organisation_id) REFERENCES branches(id, organisation_id) ON DELETE RESTRICT
);
CREATE TABLE referral_documents (
 id bigserial PRIMARY KEY, organisation_id bigint NOT NULL, branch_id bigint NOT NULL, visit_id bigint NOT NULL,
 issuer_user_id bigint NOT NULL, chain_id bigint NOT NULL, clinical_encounter_id bigint NOT NULL,
 template_revision_id bigint NOT NULL REFERENCES referral_template_revisions(id) ON DELETE RESTRICT,
 number varchar(60) NOT NULL UNIQUE CHECK(number ~ '^REF/KP/(CHR|SGB|PCG)/[0-9]{4}/[0-9]{6}$'),
 encrypted_snapshot text NOT NULL CHECK(length(encrypted_snapshot)>50), ciphertext_sha256 char(64) NOT NULL CHECK(ciphertext_sha256 ~ '^[0-9a-f]{64}$'),
 issued_at timestamptz NOT NULL,
 UNIQUE(id, organisation_id, branch_id, visit_id, issuer_user_id, chain_id),
 FOREIGN KEY(chain_id, organisation_id, branch_id, visit_id, issuer_user_id) REFERENCES referral_chains(id, organisation_id, branch_id, visit_id, issuer_user_id) ON DELETE RESTRICT,
 FOREIGN KEY(clinical_encounter_id, organisation_id, branch_id) REFERENCES clinical_encounters(id, organisation_id, branch_id) ON DELETE RESTRICT
);
CREATE INDEX referral_documents_history ON referral_documents(visit_id, issuer_user_id, id DESC);
CREATE TABLE referral_actions (
 id bigserial PRIMARY KEY, organisation_id bigint NOT NULL, branch_id bigint NOT NULL, visit_id bigint NOT NULL, actor_user_id bigint NOT NULL,
 chain_id bigint NOT NULL, document_id bigint NOT NULL, replaces_id bigint UNIQUE,
 action varchar(10) NOT NULL, change_reason varchar(30), idempotency_key uuid NOT NULL UNIQUE,
 payload_hash char(64) NOT NULL, control_version integer NOT NULL CHECK(control_version>=1), created_at timestamptz NOT NULL,
 UNIQUE(chain_id, control_version),
 CHECK((action='issue' AND replaces_id IS NULL AND change_reason IS NULL) OR
 (action='replace' AND replaces_id IS NOT NULL AND replaces_id<>document_id AND change_reason IN ('details_corrected','entered_in_error','patient_request')) OR
 (action='void' AND replaces_id=document_id AND change_reason IN ('details_corrected','entered_in_error','patient_request'))),
 FOREIGN KEY(document_id, organisation_id, branch_id, visit_id, actor_user_id, chain_id) REFERENCES referral_documents(id, organisation_id, branch_id, visit_id, issuer_user_id, chain_id) ON DELETE RESTRICT,
 FOREIGN KEY(replaces_id, organisation_id, branch_id, visit_id, actor_user_id, chain_id) REFERENCES referral_documents(id, organisation_id, branch_id, visit_id, issuer_user_id, chain_id) ON DELETE RESTRICT
);
CREATE UNIQUE INDEX referral_first_action ON referral_actions(chain_id) WHERE action='issue';
CREATE OR REPLACE FUNCTION protect_referral_evidence() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN RAISE EXCEPTION 'Referral evidence is immutable'; END; $$;
CREATE OR REPLACE FUNCTION guard_referral_source() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF NOT EXISTS(SELECT 1 FROM clinical_encounters WHERE id=NEW.clinical_encounter_id AND visit_id=NEW.visit_id AND attending_clinician_user_id=NEW.issuer_user_id) THEN RAISE EXCEPTION 'Referral source mismatch'; END IF;
 RETURN NEW;
END; $$;
CREATE TRIGGER referral_source BEFORE INSERT ON referral_documents FOR EACH ROW EXECUTE FUNCTION guard_referral_source();
CREATE OR REPLACE FUNCTION guard_referral_control() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF TG_OP='DELETE' THEN RAISE EXCEPTION 'Referral metadata is retained'; END IF;
 IF TG_TABLE_NAME='referral_chains' THEN
   IF (to_jsonb(NEW)-'lock_version') IS DISTINCT FROM (to_jsonb(OLD)-'lock_version') OR NEW.lock_version<>OLD.lock_version+1 THEN RAISE EXCEPTION 'Invalid referral version transition'; END IF;
 ELSE
   IF (to_jsonb(NEW)-'last_number') IS DISTINCT FROM (to_jsonb(OLD)-'last_number') OR NEW.last_number<>OLD.last_number+1 THEN RAISE EXCEPTION 'Invalid referral counter transition'; END IF;
 END IF;
 RETURN NEW;
END; $$;
SQL);
        foreach (['referral_documents', 'referral_actions', 'referral_template_revisions'] as $table) {
            DB::unprepared("CREATE TRIGGER {$table}_immutable BEFORE UPDATE OR DELETE ON {$table} FOR EACH ROW EXECUTE FUNCTION protect_referral_evidence(); CREATE TRIGGER {$table}_no_truncate BEFORE TRUNCATE ON {$table} FOR EACH STATEMENT EXECUTE FUNCTION protect_referral_evidence();");
        }
        foreach (['referral_chains', 'referral_number_counters'] as $table) {
            DB::unprepared("CREATE TRIGGER {$table}_guard BEFORE UPDATE OR DELETE ON {$table} FOR EACH ROW EXECUTE FUNCTION guard_referral_control(); CREATE TRIGGER {$table}_no_truncate BEFORE TRUNCATE ON {$table} FOR EACH STATEMENT EXECUTE FUNCTION protect_referral_evidence();");
        }
        foreach (ReferralWording::revisions() as $language => $wording) {
            DB::table('referral_template_revisions')->insert(['language' => $language, 'revision' => 1, 'renderer_version' => 'referral-a4-v1', 'production_approved' => false, 'wording' => json_encode($wording, JSON_THROW_ON_ERROR)]);
        }
    }

    public function down(): void
    {
        // Retained clinical evidence is not removed by rollback.
    }
};
