<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_receiving_reviews', function (Blueprint $t): void {
            $t->id();
            $t->uuid('public_id')->unique();
            $t->foreignId('organisation_id')->constrained()->restrictOnDelete();
            $t->foreignId('branch_id')->constrained()->restrictOnDelete();
            $t->foreignId('purchase_order_id')->constrained('inventory_purchase_orders')->restrictOnDelete();
            $t->foreignId('receiver_user_id')->constrained('users')->restrictOnDelete();
            $t->string('kind', 12);
            $t->jsonb('payload');
            $t->jsonb('reason_codes');
            $t->char('payload_hash', 64);
            $t->unsignedInteger('order_lock_version');
            $t->uuid('retry_key');
            $t->unsignedInteger('lock_version')->default(1);
            $t->timestamp('created_at');
            $t->unique(['organisation_id', 'receiver_user_id', 'retry_key'], 'receiving_review_retry_unique');
        });
        Schema::create('inventory_receiving_approvals', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('review_id')->unique()->constrained('inventory_receiving_reviews')->restrictOnDelete();
            $t->foreignId('organisation_id')->constrained()->restrictOnDelete();
            $t->foreignId('branch_id')->constrained()->restrictOnDelete();
            $t->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $t->uuid('retry_key');
            $t->char('payload_hash', 64);
            $t->unsignedInteger('lock_version')->default(2);
            $t->timestamp('created_at');
            $t->unique(['organisation_id', 'actor_user_id', 'retry_key'], 'receiving_approval_retry_unique');
        });
        Schema::create('inventory_receipt_line_evidence', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('organisation_id')->constrained()->restrictOnDelete();
            $t->foreignId('goods_receipt_id')->constrained('inventory_goods_receipts')->restrictOnDelete();
            $t->foreignId('purchase_order_line_id')->constrained('inventory_purchase_order_lines')->restrictOnDelete();
            foreach (['physical_quantity', 'accepted_quantity', 'quarantined_quantity', 'rejected_quantity', 'applied_order_quantity', 'overage_quantity'] as $name) {
                $t->decimal($name, 15, 3);
            }
            $t->unique(['goods_receipt_id', 'purchase_order_line_id'], 'receipt_line_evidence_unique');
        });
        Schema::create('inventory_receipt_lots', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('line_evidence_id')->constrained('inventory_receipt_line_evidence')->restrictOnDelete();
            $t->foreignId('inventory_batch_id')->nullable()->constrained()->restrictOnDelete();
            $t->string('batch_number', 100);
            $t->date('expiry_date');
            $t->decimal('quantity', 15, 3);
            $t->string('outcome', 15);
            $t->string('reason', 32)->nullable();
            $t->unsignedBigInteger('unit_cost_sen')->nullable();
        });
        Schema::create('inventory_receiving_consumptions', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('review_id')->unique()->constrained('inventory_receiving_reviews')->restrictOnDelete();
            $t->foreignId('goods_receipt_id')->nullable()->constrained('inventory_goods_receipts')->restrictOnDelete();
            $t->timestamp('created_at');
        });
        Schema::create('inventory_receipt_corrections', function (Blueprint $t): void {
            $t->id();
            $t->uuid('public_id')->unique();
            $t->foreignId('review_id')->unique()->constrained('inventory_receiving_reviews')->restrictOnDelete();
            $t->foreignId('goods_receipt_id')->unique()->constrained('inventory_goods_receipts')->restrictOnDelete();
            $t->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $t->uuid('retry_key');
            $t->char('payload_hash', 64);
            $t->unique(['actor_user_id', 'retry_key'], 'receipt_correction_retry_unique');
            $t->timestamp('created_at');
        });
        DB::statement("ALTER TABLE inventory_receiving_reviews ADD CONSTRAINT receiving_review_shape CHECK (kind IN ('receipt','correction') AND lock_version=1 AND jsonb_typeof(payload)='object' AND jsonb_typeof(reason_codes)='array')");
        DB::statement('ALTER TABLE inventory_receipt_line_evidence ADD CONSTRAINT receiving_quantity_conservation CHECK (physical_quantity > 0 AND accepted_quantity >= 0 AND quarantined_quantity >= 0 AND rejected_quantity >= 0 AND applied_order_quantity >= 0 AND overage_quantity >= 0 AND physical_quantity = accepted_quantity + quarantined_quantity + rejected_quantity AND accepted_quantity + quarantined_quantity = applied_order_quantity + overage_quantity)');
        DB::statement("ALTER TABLE inventory_receipt_lots ADD CONSTRAINT receiving_lot_shape CHECK (quantity > 0 AND ((outcome='accepted' AND reason IS NULL AND inventory_batch_id IS NOT NULL) OR (outcome IN ('quarantined','rejected') AND reason IN ('damaged','wrong_item','short_expiry','documentation_missing','other_approved') AND (outcome='rejected' OR inventory_batch_id IS NOT NULL))))");
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION guard_verified_receiving_immutable() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN RAISE EXCEPTION 'Verified receiving evidence is append-only' USING ERRCODE='P0001'; END; $$;
CREATE OR REPLACE FUNCTION guard_receiving_approval() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF NEW.lock_version<>2 OR NOT EXISTS (SELECT 1 FROM inventory_receiving_reviews r JOIN users u ON u.id=NEW.actor_user_id WHERE r.id=NEW.review_id AND r.organisation_id=NEW.organisation_id AND r.branch_id=NEW.branch_id AND u.organisation_id=NEW.organisation_id AND r.receiver_user_id<>NEW.actor_user_id) THEN RAISE EXCEPTION 'Independent receiving approval required' USING ERRCODE='23514'; END IF;
 RETURN NEW;
END; $$;
CREATE OR REPLACE FUNCTION guard_receiving_review_scope() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF NOT EXISTS (SELECT 1 FROM inventory_purchase_orders p JOIN inventory_locations l ON l.id=p.destination_location_id JOIN users u ON u.id=NEW.receiver_user_id JOIN branches b ON b.id=NEW.branch_id WHERE p.id=NEW.purchase_order_id AND p.organisation_id=NEW.organisation_id AND l.branch_id=b.id AND b.organisation_id=NEW.organisation_id AND u.organisation_id=NEW.organisation_id) THEN RAISE EXCEPTION 'Invalid receiving review scope' USING ERRCODE='23514'; END IF;
 RETURN NEW;
END; $$;
CREATE TRIGGER receiving_review_scope BEFORE INSERT ON inventory_receiving_reviews FOR EACH ROW EXECUTE FUNCTION guard_receiving_review_scope();
CREATE OR REPLACE FUNCTION guard_receipt_line_scope() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF NOT EXISTS (SELECT 1 FROM inventory_goods_receipts r JOIN inventory_purchase_order_lines l ON l.purchase_order_id=r.purchase_order_id WHERE r.id=NEW.goods_receipt_id AND l.id=NEW.purchase_order_line_id AND r.organisation_id=NEW.organisation_id AND l.organisation_id=NEW.organisation_id) THEN RAISE EXCEPTION 'Invalid receipt line scope' USING ERRCODE='23514'; END IF;
 RETURN NEW;
END; $$;
CREATE TRIGGER receipt_line_scope BEFORE INSERT ON inventory_receipt_line_evidence FOR EACH ROW EXECUTE FUNCTION guard_receipt_line_scope();
CREATE OR REPLACE FUNCTION guard_receipt_lot_scope() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF NEW.inventory_batch_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM inventory_receipt_line_evidence e JOIN inventory_purchase_order_lines l ON l.id=e.purchase_order_line_id JOIN inventory_batches b ON b.id=NEW.inventory_batch_id WHERE e.id=NEW.line_evidence_id AND b.inventory_sku_id=l.inventory_sku_id AND b.organisation_id=e.organisation_id AND b.batch_number=NEW.batch_number AND b.expiry_date=NEW.expiry_date) THEN RAISE EXCEPTION 'Invalid receipt lot scope' USING ERRCODE='23514'; END IF;
 RETURN NEW;
END; $$;
CREATE TRIGGER receipt_lot_scope BEFORE INSERT ON inventory_receipt_lots FOR EACH ROW EXECUTE FUNCTION guard_receipt_lot_scope();
CREATE OR REPLACE FUNCTION guard_receiving_consumption() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF NOT EXISTS (SELECT 1 FROM inventory_receiving_reviews v JOIN inventory_receiving_approvals a ON a.review_id=v.id WHERE v.id=NEW.review_id AND ((v.kind='receipt' AND EXISTS (SELECT 1 FROM inventory_goods_receipts r WHERE r.id=NEW.goods_receipt_id AND r.purchase_order_id=v.purchase_order_id AND r.received_by_user_id=v.receiver_user_id AND r.organisation_id=v.organisation_id)) OR (v.kind='correction' AND NEW.goods_receipt_id IS NULL AND EXISTS (SELECT 1 FROM inventory_receipt_corrections c WHERE c.review_id=v.id)))) THEN RAISE EXCEPTION 'Independent approval required before consumption' USING ERRCODE='23514'; END IF;
 RETURN NEW;
END; $$;
CREATE TRIGGER receiving_consumption_scope BEFORE INSERT ON inventory_receiving_consumptions FOR EACH ROW EXECUTE FUNCTION guard_receiving_consumption();
CREATE TRIGGER receiving_approval_scope BEFORE INSERT ON inventory_receiving_approvals FOR EACH ROW EXECUTE FUNCTION guard_receiving_approval();
SQL);
        foreach (['inventory_goods_receipts', 'inventory_goods_receipt_lines', 'inventory_receiving_reviews', 'inventory_receiving_approvals', 'inventory_receipt_line_evidence', 'inventory_receipt_lots', 'inventory_receiving_consumptions', 'inventory_receipt_corrections'] as $table) {
            DB::unprepared('CREATE TRIGGER '.$table.'_immutable BEFORE UPDATE OR DELETE OR TRUNCATE ON '.$table.' FOR EACH STATEMENT EXECUTE FUNCTION guard_verified_receiving_immutable()');
        }
    }

    public function down(): void
    {
        // Retain inspections, approvals, lots and reversing correction evidence.
    }
};
