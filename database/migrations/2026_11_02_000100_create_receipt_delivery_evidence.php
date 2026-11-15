<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_receipt_delivery_evidence', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organisation_id')->constrained()->restrictOnDelete();
            $table->foreignId('goods_receipt_id')->unique()->constrained('inventory_goods_receipts')->restrictOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('supplier_id')->constrained('inventory_suppliers')->restrictOnDelete();
            $table->string('delivery_reference', 120);
            $table->string('reference_key', 120);
            $table->foreignId('received_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('received_at');
            $table->unsignedInteger('lock_version')->default(1);
            $table->unique(['organisation_id', 'supplier_id', 'branch_id', 'reference_key'], 'receiving_delivery_reference_unique');
        });
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION guard_receipt_delivery_evidence() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF TG_OP <> 'INSERT' THEN RAISE EXCEPTION 'Receipt delivery evidence is append-only' USING ERRCODE='P0001'; END IF;
 IF NEW.lock_version <> 1 OR length(trim(NEW.delivery_reference))=0 OR NEW.reference_key<>upper(trim(NEW.delivery_reference)) OR NOT EXISTS (
  SELECT 1 FROM inventory_goods_receipts r JOIN inventory_purchase_orders p ON p.id=r.purchase_order_id JOIN inventory_locations l ON l.id=p.destination_location_id JOIN branches b ON b.id=l.branch_id
  WHERE r.id=NEW.goods_receipt_id AND r.organisation_id=NEW.organisation_id AND p.organisation_id=NEW.organisation_id AND b.organisation_id=NEW.organisation_id AND b.id=NEW.branch_id AND p.supplier_id=NEW.supplier_id AND r.received_by_user_id=NEW.received_by_user_id AND r.received_at=NEW.received_at
 ) THEN RAISE EXCEPTION 'Invalid receipt delivery scope' USING ERRCODE='23514'; END IF;
 RETURN NEW;
END; $$;
CREATE TRIGGER receipt_delivery_evidence_insert BEFORE INSERT ON inventory_receipt_delivery_evidence FOR EACH ROW EXECUTE FUNCTION guard_receipt_delivery_evidence();
CREATE TRIGGER receipt_delivery_evidence_immutable BEFORE UPDATE OR DELETE OR TRUNCATE ON inventory_receipt_delivery_evidence FOR EACH STATEMENT EXECUTE FUNCTION guard_receipt_delivery_evidence();
SQL);
    }

    public function down(): void
    {
        // Retain all delivery evidence. No historical receipt backfill.
    }
};
