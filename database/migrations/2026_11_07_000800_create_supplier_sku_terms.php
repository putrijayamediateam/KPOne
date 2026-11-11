<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * STK-13 (owner "a until g" approval, 2026-11-11): a supplier price list and lead time per SKU. The price is the
 * whole pack price exactly as the supplier quotes it, with the pack size in stock units, so no per-unit amount is
 * ever rounded (the STK-10B lesson). Current terms are versioned; every change is kept as an append-only revision.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_supplier_sku_terms', function (Blueprint $t): void {
            $t->id();
            $t->uuid('public_id')->unique();
            $t->unsignedBigInteger('organisation_id');
            $t->unsignedBigInteger('inventory_supplier_id');
            $t->unsignedBigInteger('inventory_sku_id');
            $t->string('pack_description', 60);
            $t->decimal('pack_quantity', 15, 3);
            $t->unsignedBigInteger('pack_price_sen')->nullable();
            $t->unsignedSmallInteger('lead_time_days');
            $t->unsignedInteger('minimum_order_packs')->nullable();
            $t->boolean('is_preferred')->default(false);
            $t->boolean('is_active')->default(true);
            $t->unsignedInteger('lock_version')->default(1);
            $t->timestamps();
            $t->foreign(['inventory_supplier_id', 'organisation_id'], 'supplier_sku_terms_supplier_fk')
                ->references(['id', 'organisation_id'])->on('inventory_suppliers')->restrictOnDelete();
            $t->foreign(['inventory_sku_id', 'organisation_id'], 'supplier_sku_terms_sku_fk')
                ->references(['id', 'organisation_id'])->on('inventory_skus')->restrictOnDelete();
            $t->unique(['organisation_id', 'inventory_supplier_id', 'inventory_sku_id'], 'supplier_sku_terms_pair_unique');
            $t->index(['organisation_id', 'inventory_sku_id'], 'supplier_sku_terms_sku_index');
        });

        Schema::create('inventory_supplier_sku_term_revisions', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('organisation_id');
            $t->unsignedBigInteger('inventory_supplier_sku_term_id');
            $t->unsignedInteger('revision');
            $t->unsignedBigInteger('actor_user_id');
            $t->string('action', 20);
            $t->string('pack_description', 60);
            $t->decimal('pack_quantity', 15, 3);
            $t->unsignedBigInteger('pack_price_sen')->nullable();
            $t->unsignedSmallInteger('lead_time_days');
            $t->unsignedInteger('minimum_order_packs')->nullable();
            $t->boolean('is_preferred');
            $t->boolean('is_active');
            $t->timestamp('created_at');
            $t->foreign('inventory_supplier_sku_term_id', 'supplier_sku_term_revisions_term_fk')
                ->references('id')->on('inventory_supplier_sku_terms')->restrictOnDelete();
            $t->foreign('organisation_id', 'supplier_sku_term_revisions_org_fk')->references('id')->on('organisations')->restrictOnDelete();
            $t->foreign('actor_user_id', 'supplier_sku_term_revisions_actor_fk')->references('id')->on('users')->restrictOnDelete();
            $t->unique(['inventory_supplier_sku_term_id', 'revision'], 'supplier_sku_term_revisions_unique');
        });

        Schema::create('inventory_supplier_sku_term_commands', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('organisation_id');
            $t->unsignedBigInteger('branch_id');
            $t->unsignedBigInteger('inventory_supplier_sku_term_id');
            $t->uuid('idempotency_key');
            $t->string('operation', 20);
            $t->char('payload_hash', 64);
            $t->timestamp('created_at');
            $t->foreign('inventory_supplier_sku_term_id', 'supplier_sku_term_commands_term_fk')
                ->references('id')->on('inventory_supplier_sku_terms')->restrictOnDelete();
            $t->foreign('organisation_id', 'supplier_sku_term_commands_org_fk')->references('id')->on('organisations')->restrictOnDelete();
            $t->foreign('branch_id', 'supplier_sku_term_commands_branch_fk')->references('id')->on('branches')->restrictOnDelete();
            $t->unique(['organisation_id', 'idempotency_key'], 'supplier_sku_term_commands_key_unique');
        });

        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        $shape = 'length(trim(pack_description)) > 0 AND pack_quantity > 0 AND (pack_price_sen IS NULL OR pack_price_sen >= 0) AND lead_time_days BETWEEN 0 AND 365 AND (minimum_order_packs IS NULL OR minimum_order_packs >= 1)';
        DB::statement("ALTER TABLE inventory_supplier_sku_terms ADD CONSTRAINT supplier_sku_terms_shape CHECK ({$shape} AND lock_version >= 1 AND (is_active OR NOT is_preferred))");
        DB::statement("ALTER TABLE inventory_supplier_sku_term_revisions ADD CONSTRAINT supplier_sku_term_revisions_shape CHECK ({$shape} AND revision >= 1 AND action IN ('created','updated','deactivated','activated') AND (is_active OR NOT is_preferred))");
        // At most one preferred supplier per SKU.
        DB::statement('CREATE UNIQUE INDEX supplier_sku_terms_one_preferred ON inventory_supplier_sku_terms (organisation_id, inventory_sku_id) WHERE is_preferred');
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION guard_supplier_sku_term_revisions() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 RAISE EXCEPTION 'Supplier price list history is append-only' USING ERRCODE='P0001';
END; $$;
CREATE TRIGGER inventory_supplier_sku_term_revisions_immutable BEFORE UPDATE OR DELETE OR TRUNCATE ON inventory_supplier_sku_term_revisions FOR EACH STATEMENT EXECUTE FUNCTION guard_supplier_sku_term_revisions();
CREATE TRIGGER inventory_supplier_sku_term_commands_immutable BEFORE UPDATE OR DELETE OR TRUNCATE ON inventory_supplier_sku_term_commands FOR EACH STATEMENT EXECUTE FUNCTION guard_supplier_sku_term_revisions();
CREATE OR REPLACE FUNCTION guard_supplier_sku_terms_delete() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 RAISE EXCEPTION 'Supplier price list rows are deactivated, never deleted' USING ERRCODE='P0001';
END; $$;
CREATE TRIGGER inventory_supplier_sku_terms_no_delete BEFORE DELETE OR TRUNCATE ON inventory_supplier_sku_terms FOR EACH STATEMENT EXECUTE FUNCTION guard_supplier_sku_terms_delete();
CREATE OR REPLACE FUNCTION guard_supplier_sku_terms_version() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF NEW.organisation_id<>OLD.organisation_id OR NEW.inventory_supplier_id<>OLD.inventory_supplier_id OR NEW.inventory_sku_id<>OLD.inventory_sku_id OR NEW.public_id<>OLD.public_id THEN
  RAISE EXCEPTION 'A price list row keeps its supplier and SKU' USING ERRCODE='23514';
 END IF;
 IF NEW.lock_version<>OLD.lock_version+1 THEN RAISE EXCEPTION 'Invalid price list version' USING ERRCODE='23514'; END IF;
 RETURN NEW;
END; $$;
CREATE TRIGGER inventory_supplier_sku_terms_version BEFORE UPDATE ON inventory_supplier_sku_terms FOR EACH ROW EXECUTE FUNCTION guard_supplier_sku_terms_version();
SQL);
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::unprepared('DROP TRIGGER IF EXISTS inventory_supplier_sku_terms_version ON inventory_supplier_sku_terms; DROP TRIGGER IF EXISTS inventory_supplier_sku_terms_no_delete ON inventory_supplier_sku_terms;');
        }
        Schema::dropIfExists('inventory_supplier_sku_term_commands');
        Schema::dropIfExists('inventory_supplier_sku_term_revisions');
        Schema::dropIfExists('inventory_supplier_sku_terms');
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::unprepared('DROP FUNCTION IF EXISTS guard_supplier_sku_terms_version(); DROP FUNCTION IF EXISTS guard_supplier_sku_terms_delete(); DROP FUNCTION IF EXISTS guard_supplier_sku_term_revisions();');
        }
    }
};
