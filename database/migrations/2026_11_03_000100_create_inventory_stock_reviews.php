<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_stock_reviews', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('organisation_id')->constrained()->restrictOnDelete();
            $t->foreignId('branch_id')->constrained()->restrictOnDelete();
            $t->string('worklist', 20);
            $t->unsignedBigInteger('item_id');
            $t->foreignId('inventory_sku_id')->constrained()->restrictOnDelete();
            $t->foreignId('inventory_location_id')->constrained()->restrictOnDelete();
            $t->foreignId('inventory_batch_id')->nullable()->constrained()->restrictOnDelete();
            $t->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $t->string('outcome', 40);
            $t->date('review_by')->nullable();
            $t->unsignedInteger('lock_version');
            $t->uuid('retry_key');
            $t->string('payload_hash', 64);
            $t->timestampTz('created_at');
            $t->unique(['organisation_id', 'actor_user_id', 'retry_key'], 'stock_review_retry_unique');
            $t->unique(['branch_id', 'worklist', 'item_id', 'lock_version'], 'stock_review_version_unique');
        });
        DB::statement("ALTER TABLE inventory_stock_reviews ADD CONSTRAINT stock_review_codes CHECK (worklist IN ('low_stock','expiring','expired') AND outcome IN ('ordered_externally','no_action_needed','monitoring','disposal_requested_external') AND lock_version > 0 AND (outcome NOT IN ('monitoring','ordered_externally') OR review_by IS NOT NULL))");
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION guard_inventory_stock_review() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF TG_OP <> 'INSERT' THEN RAISE EXCEPTION 'Stock reviews are append-only' USING ERRCODE='P0001'; END IF;
 IF NOT EXISTS (SELECT 1 FROM branches b JOIN inventory_locations l ON l.branch_id=b.id AND l.organisation_id=b.organisation_id JOIN inventory_skus s ON s.organisation_id=b.organisation_id JOIN users u ON u.organisation_id=b.organisation_id WHERE b.id=NEW.branch_id AND b.organisation_id=NEW.organisation_id AND l.id=NEW.inventory_location_id AND s.id=NEW.inventory_sku_id AND u.id=NEW.actor_user_id) THEN RAISE EXCEPTION 'Stock review scope mismatch' USING ERRCODE='23514'; END IF;
 IF NEW.worklist='low_stock' THEN
  IF NEW.inventory_batch_id IS NOT NULL OR NOT EXISTS (SELECT 1 FROM inventory_reorder_levels r WHERE r.id=NEW.item_id AND r.organisation_id=NEW.organisation_id AND r.inventory_location_id=NEW.inventory_location_id AND r.inventory_sku_id=NEW.inventory_sku_id) THEN RAISE EXCEPTION 'Invalid review reference' USING ERRCODE='23514'; END IF;
 ELSE
  IF NEW.inventory_batch_id IS NULL OR NOT EXISTS (SELECT 1 FROM inventory_stock_balances b JOIN inventory_batches batch ON batch.id=b.inventory_batch_id AND batch.organisation_id=b.organisation_id WHERE b.id=NEW.item_id AND b.organisation_id=NEW.organisation_id AND b.inventory_location_id=NEW.inventory_location_id AND b.inventory_sku_id=NEW.inventory_sku_id AND b.inventory_batch_id=NEW.inventory_batch_id AND batch.inventory_sku_id=NEW.inventory_sku_id) THEN RAISE EXCEPTION 'Invalid review reference' USING ERRCODE='23514'; END IF;
 END IF;
 IF NEW.lock_version <> COALESCE((SELECT MAX(lock_version) FROM inventory_stock_reviews WHERE branch_id=NEW.branch_id AND worklist=NEW.worklist AND item_id=NEW.item_id),0)+1 THEN RAISE EXCEPTION 'Invalid review version' USING ERRCODE='23514'; END IF;
 RETURN NEW;
END; $$;
CREATE TRIGGER inventory_stock_reviews_append BEFORE INSERT OR UPDATE OR DELETE ON inventory_stock_reviews FOR EACH ROW EXECUTE FUNCTION guard_inventory_stock_review();
CREATE TRIGGER inventory_stock_reviews_truncate BEFORE TRUNCATE ON inventory_stock_reviews FOR EACH STATEMENT EXECUTE FUNCTION guard_inventory_stock_review();
SQL);
    }

    public function down(): void
    {
        // Retain all acknowledgement evidence.
    }
};
