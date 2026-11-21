<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * STK-52 (owner item 17 and answer 14 = A, 21 Nov 2026): a checked pack conversion per SKU ("1 bottle = 60 ml"), so
 * Dispensary (DSP-24) can hand out whole packs while stock stays exact in the SKU's base unit. Append-only: a version's
 * values never change and each version has at most one outcome (checked, refused or withdrawn). The checker is never the
 * proposer; a withdrawal is recorded by the proposer; at most one version waits for an outcome per SKU.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_pack_conversions', function (Blueprint $t): void {
            $t->id();
            $t->uuid('public_id')->unique();
            $t->foreignId('organisation_id')->constrained()->restrictOnDelete();
            $t->foreignId('inventory_sku_id')->constrained('inventory_skus')->restrictOnDelete();
            $t->unsignedInteger('version');
            $t->string('pack_unit', 40)->nullable();
            $t->unsignedInteger('base_per_pack')->nullable();
            $t->string('base_unit', 100);
            $t->foreignId('proposed_by_user_id')->constrained('users')->restrictOnDelete();
            $t->timestamp('proposed_at');
            $t->uuid('idempotency_key');
            $t->char('request_hash', 64);
            $t->unique(['inventory_sku_id', 'version'], 'pack_conversions_sku_version_unique');
            $t->unique(['organisation_id', 'idempotency_key'], 'pack_conversions_idempotency_unique');
        });
        Schema::create('inventory_pack_conversion_outcomes', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('organisation_id')->constrained()->restrictOnDelete();
            $t->foreignId('pack_conversion_id')->unique('pack_conversion_outcomes_one_per_version')->constrained('inventory_pack_conversions')->restrictOnDelete();
            $t->string('outcome', 20);
            $t->string('reason', 20)->nullable();
            $t->unsignedInteger('checked_base_per_pack')->nullable();
            $t->foreignId('recorded_by_user_id')->constrained('users')->restrictOnDelete();
            $t->timestamp('recorded_at');
            $t->uuid('idempotency_key');
            $t->char('request_hash', 64);
            $t->unique(['organisation_id', 'idempotency_key'], 'pack_conversion_outcomes_idempotency_unique');
        });

        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
ALTER TABLE inventory_pack_conversions ADD CONSTRAINT pack_conversions_shape CHECK (
    version >= 1
    AND ((pack_unit IS NULL AND base_per_pack IS NULL) OR (pack_unit IS NOT NULL AND btrim(pack_unit) <> '' AND base_per_pack BETWEEN 2 AND 100000))
    AND pack_unit <> base_unit
);
ALTER TABLE inventory_pack_conversion_outcomes ADD CONSTRAINT pack_conversion_outcomes_shape CHECK (
    (outcome = 'checked' AND reason IS NULL)
    OR (outcome = 'refused' AND reason IN ('wrong_count', 'wrong_unit', 'other'))
    OR (outcome = 'withdrawn' AND reason IS NULL AND checked_base_per_pack IS NULL)
);

CREATE OR REPLACE FUNCTION kpone_pack_conversions_append_only() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    RAISE EXCEPTION 'Pack conversion evidence is append-only.' USING ERRCODE = 'P0001';
END $$;

CREATE OR REPLACE FUNCTION kpone_pack_conversion_insert() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE
    sku_org bigint;
    previous integer;
BEGIN
    SELECT organisation_id INTO sku_org FROM inventory_skus WHERE id = NEW.inventory_sku_id;
    IF sku_org IS DISTINCT FROM NEW.organisation_id THEN
        RAISE EXCEPTION 'A pack conversion must belong to its SKU''s organisation.' USING ERRCODE = 'P0001';
    END IF;
    IF NOT EXISTS (SELECT 1 FROM users WHERE id = NEW.proposed_by_user_id AND organisation_id = NEW.organisation_id) THEN
        RAISE EXCEPTION 'The proposer must belong to the organisation.' USING ERRCODE = 'P0001';
    END IF;
    SELECT COALESCE(MAX(version), 0) INTO previous FROM inventory_pack_conversions WHERE inventory_sku_id = NEW.inventory_sku_id;
    IF NEW.version <> previous + 1 THEN
        RAISE EXCEPTION 'Pack conversion versions are gapless.' USING ERRCODE = 'P0001';
    END IF;
    IF EXISTS (
        SELECT 1 FROM inventory_pack_conversions c
        WHERE c.inventory_sku_id = NEW.inventory_sku_id
          AND NOT EXISTS (SELECT 1 FROM inventory_pack_conversion_outcomes o WHERE o.pack_conversion_id = c.id)
    ) THEN
        RAISE EXCEPTION 'A pack conversion is already waiting for a check.' USING ERRCODE = 'P0001';
    END IF;
    RETURN NEW;
END $$;

CREATE OR REPLACE FUNCTION kpone_pack_conversion_outcome_insert() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE
    conversion inventory_pack_conversions%ROWTYPE;
BEGIN
    SELECT * INTO conversion FROM inventory_pack_conversions WHERE id = NEW.pack_conversion_id;
    IF conversion.organisation_id IS DISTINCT FROM NEW.organisation_id THEN
        RAISE EXCEPTION 'An outcome must belong to its conversion''s organisation.' USING ERRCODE = 'P0001';
    END IF;
    IF NOT EXISTS (SELECT 1 FROM users WHERE id = NEW.recorded_by_user_id AND organisation_id = NEW.organisation_id) THEN
        RAISE EXCEPTION 'The person recording an outcome must belong to the organisation.' USING ERRCODE = 'P0001';
    END IF;
    IF NEW.outcome = 'withdrawn' AND NEW.recorded_by_user_id <> conversion.proposed_by_user_id THEN
        RAISE EXCEPTION 'Only the proposer withdraws a pack conversion.' USING ERRCODE = 'P0001';
    END IF;
    IF NEW.outcome IN ('checked', 'refused') AND NEW.recorded_by_user_id = conversion.proposed_by_user_id THEN
        RAISE EXCEPTION 'The checker is never the proposer.' USING ERRCODE = 'P0001';
    END IF;
    -- A checked count must match the proposal exactly; a "no pack conversion" version carries no count.
    IF NEW.outcome = 'checked' AND NEW.checked_base_per_pack IS DISTINCT FROM conversion.base_per_pack THEN
        RAISE EXCEPTION 'A checked count must match the proposal.' USING ERRCODE = 'P0001';
    END IF;
    RETURN NEW;
END $$;

CREATE TRIGGER pack_conversions_no_update BEFORE UPDATE OR DELETE ON inventory_pack_conversions FOR EACH ROW EXECUTE FUNCTION kpone_pack_conversions_append_only();
CREATE TRIGGER pack_conversions_no_truncate BEFORE TRUNCATE ON inventory_pack_conversions FOR EACH STATEMENT EXECUTE FUNCTION kpone_pack_conversions_append_only();
CREATE TRIGGER pack_conversion_outcomes_no_update BEFORE UPDATE OR DELETE ON inventory_pack_conversion_outcomes FOR EACH ROW EXECUTE FUNCTION kpone_pack_conversions_append_only();
CREATE TRIGGER pack_conversion_outcomes_no_truncate BEFORE TRUNCATE ON inventory_pack_conversion_outcomes FOR EACH STATEMENT EXECUTE FUNCTION kpone_pack_conversions_append_only();
CREATE TRIGGER pack_conversions_insert BEFORE INSERT ON inventory_pack_conversions FOR EACH ROW EXECUTE FUNCTION kpone_pack_conversion_insert();
CREATE TRIGGER pack_conversion_outcomes_insert BEFORE INSERT ON inventory_pack_conversion_outcomes FOR EACH ROW EXECUTE FUNCTION kpone_pack_conversion_outcome_insert();
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_pack_conversion_outcomes');
        Schema::dropIfExists('inventory_pack_conversions');
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::unprepared('DROP FUNCTION IF EXISTS kpone_pack_conversion_outcome_insert(); DROP FUNCTION IF EXISTS kpone_pack_conversion_insert(); DROP FUNCTION IF EXISTS kpone_pack_conversions_append_only();');
        }
    }
};
