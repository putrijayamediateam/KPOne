<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Q-16b (owner decisions 25 Nov 2026): a doctor's "I'll take it" claim on a red-zone Waiting patient. The alert itself is derived
 * (a Waiting consultation whose latest triage zone is red); only the claim is stored. Append-only evidence: ids and time only, no
 * patient or clinical content. A claim counts while its doctor is still the visit's assigned doctor, so a later reassignment brings
 * the alert back and a new claim is a new row.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('queue_red_zone_claims')) {
            return;
        }
        Schema::create('queue_red_zone_claims', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('organisation_id')->constrained()->restrictOnDelete();
            $t->foreignId('branch_id')->constrained()->restrictOnDelete();
            $t->foreignId('queue_entry_id')->constrained()->restrictOnDelete();
            $t->foreignId('visit_id')->constrained()->restrictOnDelete();
            $t->foreignId('claimed_by_user_id')->constrained('users')->restrictOnDelete();
            $t->foreignId('previous_doctor_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestampTz('claimed_at');
            $t->timestampsTz();
            $t->index(['queue_entry_id', 'id']);
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION kp_red_zone_claim_immutable() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RAISE EXCEPTION 'Red-zone claims are append-only' USING ERRCODE='P0001'; END $$;
CREATE OR REPLACE FUNCTION kp_red_zone_claim_scope() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE e queue_entries%ROWTYPE;
BEGIN
 SELECT * INTO e FROM queue_entries WHERE id = NEW.queue_entry_id;
 IF e.id IS NULL OR e.organisation_id <> NEW.organisation_id OR e.branch_id <> NEW.branch_id OR e.visit_id <> NEW.visit_id THEN
  RAISE EXCEPTION 'A red-zone claim must match its queue entry' USING ERRCODE='23514'; END IF;
 IF e.status <> 'waiting' THEN RAISE EXCEPTION 'Only a Waiting patient can be taken' USING ERRCODE='23514'; END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER red_zone_claim_scope BEFORE INSERT ON queue_red_zone_claims FOR EACH ROW EXECUTE FUNCTION kp_red_zone_claim_scope();
CREATE TRIGGER red_zone_claim_immutable BEFORE UPDATE OR DELETE ON queue_red_zone_claims FOR EACH ROW EXECUTE FUNCTION kp_red_zone_claim_immutable();
CREATE TRIGGER red_zone_claim_truncate BEFORE TRUNCATE ON queue_red_zone_claims FOR EACH STATEMENT EXECUTE FUNCTION kp_red_zone_claim_immutable();
SQL);
    }

    public function down(): void {} // Evidence is retained.
};
