<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Panel price changes by proposal (owner decision 27, 20-21 Nov 2026; design note
 * docs/teams/catalogue-settings/PANEL-PRICE-PROPOSALS-DESIGN.md). A Panel Officer proposes a price for a Panel price book
 * with an agreement reference and an effective date; a Finance Officer or Director who is not the proposer approves it
 * before it becomes a PriceEntry. Proposals and their events are never edited after a decision and never deleted.
 * This migration adds the evidence only: nothing writes a PriceEntry from it until the service is called, and direct
 * publishing of Panel prices is closed in a later slice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('price_change_proposals', function (Blueprint $t): void {
            $t->id();
            $t->uuid('public_id')->unique();
            $t->unsignedBigInteger('organisation_id');
            // Null until approval when the Panel has no price book yet: the book is created in the approval's own transaction,
            // so a rejected or withdrawn first proposal leaves nothing behind. scope_key names the target either way.
            $t->unsignedBigInteger('price_book_id')->nullable();
            $t->string('scope_key', 60);
            $t->unsignedBigInteger('panel_id')->nullable();
            $t->unsignedBigInteger('charge_definition_id');
            $t->bigInteger('proposed_unit_price_sen');
            $t->string('agreement_reference', 120);
            $t->timestampTz('effective_at');
            $t->unsignedBigInteger('base_version');
            $t->string('status', 12);
            $t->unsignedBigInteger('proposed_by_user_id');
            $t->timestampTz('proposed_at');
            $t->unsignedBigInteger('decided_by_user_id')->nullable();
            $t->timestampTz('decided_at')->nullable();
            $t->string('reason_code', 40)->nullable();
            $t->text('decision_note')->nullable();
            $t->unsignedBigInteger('price_entry_id')->nullable();
            $t->timestampTz('applied_effective_at')->nullable();
            $t->uuid('idempotency_key');
            $t->string('payload_hash', 64);
            $t->unsignedBigInteger('lock_version');
            $t->timestamps();
            $t->unique(['organisation_id', 'proposed_by_user_id', 'idempotency_key'], 'price_change_proposal_key_unique');
            $t->unique('price_entry_id', 'price_change_proposal_entry_unique');
            $t->index(['organisation_id', 'scope_key', 'charge_definition_id'], 'price_change_proposal_target_index');
            $t->foreign('panel_id')->references('id')->on('panels')->restrictOnDelete();
            $t->foreign(['price_book_id', 'organisation_id'])->references(['id', 'organisation_id'])->on('price_books')->restrictOnDelete();
            $t->foreign(['charge_definition_id', 'organisation_id'])->references(['id', 'organisation_id'])->on('charge_definitions')->restrictOnDelete();
            $t->foreign('price_entry_id')->references('id')->on('price_entries')->restrictOnDelete();
            foreach (['proposed_by_user_id', 'decided_by_user_id'] as $user) {
                $t->foreign([$user, 'organisation_id'])->references(['id', 'organisation_id'])->on('users')->restrictOnDelete();
            }
        });

        Schema::create('price_change_proposal_events', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('price_change_proposal_id')->index();
            $t->unsignedBigInteger('organisation_id');
            $t->string('event', 12);
            $t->unsignedBigInteger('actor_user_id');
            $t->timestampTz('occurred_at');
            $t->foreign('price_change_proposal_id')->references('id')->on('price_change_proposals')->restrictOnDelete();
            $t->foreign(['actor_user_id', 'organisation_id'])->references(['id', 'organisation_id'])->on('users')->restrictOnDelete();
        });

        DB::unprepared(<<<'SQL'
ALTER TABLE price_change_proposals ADD CONSTRAINT price_change_proposal_shape CHECK (
 proposed_unit_price_sen BETWEEN 0 AND 999999999999
 AND length(btrim(agreement_reference)) BETWEEN 3 AND 120
 AND base_version>=0 AND lock_version>0
 AND ((scope_key='panel:default' AND panel_id IS NULL) OR (scope_key='panel:'||panel_id::text))
 AND (price_book_id IS NOT NULL OR (status<>'approved' AND base_version=0))
 AND ((status='proposed' AND decided_by_user_id IS NULL AND decided_at IS NULL AND reason_code IS NULL AND decision_note IS NULL AND price_entry_id IS NULL AND applied_effective_at IS NULL)
  OR (status='approved' AND decided_by_user_id IS NOT NULL AND decided_at IS NOT NULL AND reason_code IS NULL AND decision_note IS NULL AND price_entry_id IS NOT NULL AND applied_effective_at IS NOT NULL AND applied_effective_at>=effective_at AND decided_by_user_id<>proposed_by_user_id)
  OR (status='rejected' AND decided_by_user_id IS NOT NULL AND decided_at IS NOT NULL AND reason_code IN ('wrong_amount','wrong_panel','no_agreement_seen','other') AND price_entry_id IS NULL AND applied_effective_at IS NULL AND decided_by_user_id<>proposed_by_user_id AND (reason_code<>'other' OR decision_note IS NOT NULL))
  OR (status='withdrawn' AND decided_by_user_id=proposed_by_user_id AND decided_at IS NOT NULL AND reason_code IS NULL AND decision_note IS NULL AND price_entry_id IS NULL AND applied_effective_at IS NULL)));
CREATE UNIQUE INDEX price_change_proposal_one_open_per_target ON price_change_proposals (organisation_id, scope_key, charge_definition_id) WHERE status='proposed';
ALTER TABLE price_change_proposal_events ADD CONSTRAINT price_change_proposal_event_shape CHECK (event IN ('proposed','approved','rejected','withdrawn'));

CREATE OR REPLACE FUNCTION kpone_price_change_retained() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN RAISE EXCEPTION 'Price change proposal evidence is retained' USING ERRCODE='23514'; END $$;

CREATE OR REPLACE FUNCTION kpone_price_change_has_permission(user_key bigint, permission_name text) RETURNS boolean LANGUAGE sql STABLE AS $$
 SELECT EXISTS (SELECT 1 FROM model_has_roles mr JOIN role_has_permissions rp ON rp.role_id=mr.role_id JOIN permissions p ON p.id=rp.permission_id
   WHERE mr.model_id=user_key AND mr.model_type='App\Models\User' AND p.name=permission_name)
 OR EXISTS (SELECT 1 FROM model_has_permissions mp JOIN permissions p ON p.id=mp.permission_id
   WHERE mp.model_id=user_key AND mp.model_type='App\Models\User' AND p.name=permission_name)
$$;

CREATE OR REPLACE FUNCTION kpone_price_change_guard() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE book record; charge record; entry record; current_version bigint;
BEGIN
 IF TG_OP='INSERT' THEN
  SELECT * INTO charge FROM charge_definitions WHERE id=NEW.charge_definition_id;
  IF charge.id IS NULL OR charge.organisation_id<>NEW.organisation_id THEN
   RAISE EXCEPTION 'A price change proposal needs a charge of the same organisation' USING ERRCODE='23514';
  END IF;
  IF NEW.price_book_id IS NOT NULL THEN
   SELECT * INTO book FROM price_books WHERE id=NEW.price_book_id;
   IF book.id IS NULL OR book.price_tier<>'panel' OR book.branch_id IS NOT NULL OR book.currency<>'MYR' OR book.organisation_id<>NEW.organisation_id OR book.scope_key<>NEW.scope_key THEN
    RAISE EXCEPTION 'A price change proposal needs an organisation Panel price book of the same scope' USING ERRCODE='23514';
   END IF;
  ELSE
   IF EXISTS (SELECT 1 FROM price_books WHERE organisation_id=NEW.organisation_id AND scope_key=NEW.scope_key)
    OR (NEW.panel_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM panels WHERE id=NEW.panel_id AND organisation_id=NEW.organisation_id)) THEN
    RAISE EXCEPTION 'A proposal without a price book is only for a Panel that has none yet' USING ERRCODE='23514';
   END IF;
  END IF;
  SELECT coalesce(max(version),0) INTO current_version FROM price_entries WHERE price_book_id=NEW.price_book_id AND charge_definition_id=NEW.charge_definition_id;
  IF NEW.status<>'proposed' OR NEW.lock_version<>1 OR NEW.base_version<>current_version
   OR NOT kpone_price_change_has_permission(NEW.proposed_by_user_id, 'panel.prices.propose.organisation') THEN
   RAISE EXCEPTION 'A price change proposal starts as proposed, on the current version, by someone who may propose' USING ERRCODE='23514';
  END IF;
  RETURN NEW;
 END IF;
 IF TG_OP<>'UPDATE' THEN RAISE EXCEPTION 'Price change proposal evidence is retained' USING ERRCODE='23514'; END IF;
 IF OLD.status<>'proposed' OR NEW.status NOT IN ('approved','rejected','withdrawn') OR NEW.lock_version<>OLD.lock_version+1
  OR (NEW.price_book_id IS DISTINCT FROM OLD.price_book_id AND NOT (OLD.price_book_id IS NULL AND NEW.status='approved'))
  OR (to_jsonb(NEW) - ARRAY['price_book_id','status','decided_by_user_id','decided_at','reason_code','decision_note','price_entry_id','applied_effective_at','lock_version','updated_at'])
   IS DISTINCT FROM (to_jsonb(OLD) - ARRAY['price_book_id','status','decided_by_user_id','decided_at','reason_code','decision_note','price_entry_id','applied_effective_at','lock_version','updated_at']) THEN
  RAISE EXCEPTION 'A price change proposal can only be decided once, never edited' USING ERRCODE='23514';
 END IF;
 IF NEW.status IN ('approved','rejected') AND NOT kpone_price_change_has_permission(NEW.decided_by_user_id, 'panel.prices.approve.organisation') THEN
  RAISE EXCEPTION 'Deciding a price change proposal needs the approve permission' USING ERRCODE='23514';
 END IF;
 IF NEW.status='approved' THEN
  SELECT * INTO entry FROM price_entries WHERE id=NEW.price_entry_id;
  IF entry.id IS NULL OR entry.organisation_id<>NEW.organisation_id OR NEW.price_book_id IS NULL OR entry.price_book_id<>NEW.price_book_id OR entry.charge_definition_id<>NEW.charge_definition_id
   OR entry.unit_price_sen<>NEW.proposed_unit_price_sen OR entry.version<=NEW.base_version OR entry.effective_at<>NEW.applied_effective_at THEN
   RAISE EXCEPTION 'An approved price change proposal must point at the price entry it created' USING ERRCODE='23514';
  END IF;
  IF NOT EXISTS (SELECT 1 FROM price_books WHERE id=NEW.price_book_id AND organisation_id=NEW.organisation_id AND scope_key=NEW.scope_key AND price_tier='panel') THEN
   RAISE EXCEPTION 'An approved price change proposal must point at the Panel price book of its scope' USING ERRCODE='23514';
  END IF;
 END IF;
 RETURN NEW;
END $$;

CREATE OR REPLACE FUNCTION kpone_price_change_event_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF TG_OP<>'INSERT' THEN RAISE EXCEPTION 'Price change proposal evidence is retained' USING ERRCODE='23514'; END IF;
 RETURN NEW;
END $$;
SQL);

        DB::unprepared('CREATE TRIGGER price_change_proposals_guard BEFORE INSERT OR UPDATE OR DELETE ON price_change_proposals FOR EACH ROW EXECUTE FUNCTION kpone_price_change_guard()');
        DB::unprepared('CREATE TRIGGER price_change_proposals_no_truncate BEFORE TRUNCATE ON price_change_proposals FOR EACH STATEMENT EXECUTE FUNCTION kpone_price_change_retained()');
        DB::unprepared('CREATE TRIGGER price_change_proposal_events_guard BEFORE UPDATE OR DELETE ON price_change_proposal_events FOR EACH ROW EXECUTE FUNCTION kpone_price_change_event_guard()');
        DB::unprepared('CREATE TRIGGER price_change_proposal_events_no_truncate BEFORE TRUNCATE ON price_change_proposal_events FOR EACH STATEMENT EXECUTE FUNCTION kpone_price_change_retained()');
    }

    public function down(): void
    {
        if (DB::table('price_change_proposals')->exists()) {
            throw new RuntimeException('Price change proposal evidence is retained; roll back is refused while proposals exist.');
        }
        DB::unprepared('DROP TABLE IF EXISTS price_change_proposal_events');
        DB::unprepared('DROP TABLE IF EXISTS price_change_proposals');
        DB::unprepared('DROP FUNCTION IF EXISTS kpone_price_change_guard()');
        DB::unprepared('DROP FUNCTION IF EXISTS kpone_price_change_event_guard()');
        DB::unprepared('DROP FUNCTION IF EXISTS kpone_price_change_has_permission(bigint, text)');
        DB::unprepared('DROP FUNCTION IF EXISTS kpone_price_change_retained()');
    }
};
