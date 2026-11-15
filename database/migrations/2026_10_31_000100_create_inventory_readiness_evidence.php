<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_unit_contracts', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('organisation_id')->constrained()->restrictOnDelete();
            $t->foreignId('branch_id')->constrained()->restrictOnDelete();
            $t->foreignId('inventory_sku_id')->unique()->constrained()->restrictOnDelete();
            $t->string('base_unit', 100);
            $t->string('direction', 32)->default('input_to_base');
            $t->unsignedSmallInteger('precision')->default(3);
            $t->decimal('factor', 12, 3)->default(1);
            $t->unsignedInteger('lock_version')->default(1);
            $t->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $t->uuid('retry_key');
            $t->string('payload_hash', 64);
            $t->timestampTz('created_at');
            $t->unique(['organisation_id', 'actor_user_id', 'retry_key'], 'inventory_unit_contract_retry_unique');
        });
        Schema::create('inventory_opening_verifications', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('organisation_id')->constrained()->restrictOnDelete();
            $t->foreignId('branch_id')->constrained()->restrictOnDelete();
            $t->foreignId('stock_movement_id')->unique()->constrained()->restrictOnDelete();
            $t->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $t->unsignedInteger('lock_version')->default(1);
            $t->uuid('retry_key');
            $t->string('payload_hash', 64);
            $t->timestampTz('created_at');
            $t->unique(['organisation_id', 'actor_user_id', 'retry_key'], 'inventory_opening_verify_retry_unique');
        });
        Schema::table('stock_movements', fn (Blueprint $t) => $t->jsonb('unit_snapshot')->nullable());
        DB::unprepared(<<<'SQL'
CREATE INDEX inventory_openings_readiness_idx ON stock_movements(organisation_id,inventory_sku_id,destination_location_id,id) WHERE movement_type='opening_balance';
CREATE OR REPLACE FUNCTION refuse_inventory_readiness_mutation() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN RAISE EXCEPTION 'Inventory readiness evidence is append-only' USING ERRCODE='P0001'; END; $$;
CREATE TRIGGER inventory_unit_contracts_immutable BEFORE UPDATE OR DELETE OR TRUNCATE ON inventory_unit_contracts FOR EACH STATEMENT EXECUTE FUNCTION refuse_inventory_readiness_mutation();
CREATE TRIGGER inventory_opening_verifications_immutable BEFORE UPDATE OR DELETE OR TRUNCATE ON inventory_opening_verifications FOR EACH STATEMENT EXECUTE FUNCTION refuse_inventory_readiness_mutation();
ALTER TABLE inventory_unit_contracts ADD CONSTRAINT inventory_unit_contract_fixed CHECK (precision=3 AND factor=1 AND direction='input_to_base' AND lock_version=1 AND btrim(base_unit)<>'');
ALTER TABLE inventory_opening_verifications ADD CONSTRAINT inventory_opening_verify_version CHECK (lock_version=1);
CREATE OR REPLACE FUNCTION enforce_inventory_readiness_insert() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE s inventory_skus%ROWTYPE; m stock_movements%ROWTYPE; l inventory_locations%ROWTYPE;
BEGIN
IF TG_TABLE_NAME='inventory_unit_contracts' THEN
 SELECT * INTO STRICT s FROM inventory_skus WHERE id=NEW.inventory_sku_id AND organisation_id=NEW.organisation_id FOR UPDATE;
 IF NEW.base_unit<>s.stock_unit THEN RAISE EXCEPTION 'Base unit must preserve the SKU stock unit' USING ERRCODE='P0001'; END IF;
ELSE
 SELECT * INTO STRICT m FROM stock_movements WHERE id=NEW.stock_movement_id AND organisation_id=NEW.organisation_id FOR UPDATE;
 SELECT * INTO STRICT l FROM inventory_locations WHERE id=m.destination_location_id AND organisation_id=NEW.organisation_id;
 IF m.movement_type<>'opening_balance' OR l.branch_id IS DISTINCT FROM NEW.branch_id OR NEW.actor_user_id=m.actor_user_id THEN
 RAISE EXCEPTION 'Independent branch opening checker required' USING ERRCODE='P0001'; END IF;
END IF;
IF NOT EXISTS(SELECT 1 FROM branches WHERE id=NEW.branch_id AND organisation_id=NEW.organisation_id) OR NOT EXISTS(SELECT 1 FROM users WHERE id=NEW.actor_user_id AND organisation_id=NEW.organisation_id) THEN
 RAISE EXCEPTION 'Readiness tenant mismatch' USING ERRCODE='P0001'; END IF;
RETURN NEW; END; $$;
CREATE TRIGGER inventory_unit_contract_insert BEFORE INSERT ON inventory_unit_contracts FOR EACH ROW EXECUTE FUNCTION enforce_inventory_readiness_insert();
CREATE TRIGGER inventory_opening_verify_insert BEFORE INSERT ON inventory_opening_verifications FOR EACH ROW EXECUTE FUNCTION enforce_inventory_readiness_insert();
CREATE OR REPLACE FUNCTION snapshot_inventory_posting_unit() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE base text;
BEGIN
 SELECT stock_unit INTO STRICT base FROM inventory_skus WHERE id=NEW.inventory_sku_id AND organisation_id=NEW.organisation_id;
 IF btrim(base)='' OR (NEW.unit_snapshot IS NOT NULL AND NEW.unit_snapshot<>jsonb_build_object('base_unit',base,'input_unit',base,'direction','input_to_base','factor','1.000','precision',3)) THEN
 RAISE EXCEPTION 'Only exact base-unit posting evidence is supported' USING ERRCODE='P0001'; END IF;
 NEW.unit_snapshot:=jsonb_build_object('base_unit',base,'input_unit',base,'direction','input_to_base','factor','1.000','precision',3);
 RETURN NEW; END; $$;
CREATE TRIGGER stock_movement_unit_snapshot BEFORE INSERT ON stock_movements FOR EACH ROW EXECUTE FUNCTION snapshot_inventory_posting_unit();
CREATE OR REPLACE FUNCTION preserve_verified_inventory_base_unit() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN IF NEW.stock_unit IS DISTINCT FROM OLD.stock_unit AND EXISTS(SELECT 1 FROM inventory_unit_contracts WHERE inventory_sku_id=OLD.id) THEN
 RAISE EXCEPTION 'Verified base stock unit is immutable' USING ERRCODE='P0001'; END IF; RETURN NEW; END; $$;
CREATE TRIGGER inventory_sku_verified_base BEFORE UPDATE ON inventory_skus FOR EACH ROW EXECUTE FUNCTION preserve_verified_inventory_base_unit();
SQL);
    }

    public function down(): void
    {
        // Retain append-only evidence and existing units.
    }
};
