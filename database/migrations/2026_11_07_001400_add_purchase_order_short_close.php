<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * STK-21 (approved by B.E.R.N.I.S.S for the owner, 2026-11-11): a partly received purchase order can be closed short
 * ("Close the rest") with a fixed reason, by someone who did not raise or submit it. Each line keeps the quantity that
 * was not delivered, frozen at that moment. Nothing moves in stock and no money changes. Existing closed orders are
 * marked as a complete close.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_purchase_orders', function (Blueprint $table): void {
            $table->string('close_kind', 10)->nullable();
            $table->string('short_close_reason', 30)->nullable();
            $table->string('short_close_note', 200)->nullable();
        });
        Schema::table('inventory_purchase_order_lines', function (Blueprint $table): void {
            $table->decimal('not_delivered_quantity', 15, 3)->nullable();
        });
        DB::table('inventory_purchase_orders')->where('status', 'closed')->update(['close_kind' => 'complete']);

        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }
        DB::unprepared(<<<'SQL'
ALTER TABLE inventory_purchase_orders ADD CONSTRAINT purchase_orders_close_shape CHECK (
 (status = 'closed') = (close_kind IS NOT NULL)
 AND (close_kind IS NULL OR close_kind IN ('complete', 'short'))
 AND (close_kind = 'short') = (short_close_reason IS NOT NULL)
 AND (short_close_reason IS NULL OR short_close_reason IN ('supplier_cannot_supply', 'discontinued', 'ordered_elsewhere', 'other'))
 AND (short_close_note IS NULL OR (close_kind = 'short' AND length(btrim(short_close_note)) BETWEEN 1 AND 200))
 AND (short_close_reason IS DISTINCT FROM 'other' OR short_close_note IS NOT NULL)
);
ALTER TABLE inventory_purchase_order_lines ADD CONSTRAINT purchase_order_lines_not_delivered_shape CHECK (
 not_delivered_quantity IS NULL OR (not_delivered_quantity >= 0 AND not_delivered_quantity = ordered_quantity - received_quantity)
);
CREATE OR REPLACE FUNCTION guard_purchase_order_independent_approval() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 -- Only the approval step itself: a receipt correction that puts an order back to approved is not an approval.
 IF OLD.status = 'submitted' AND NEW.status = 'approved'
  AND (NEW.approved_by_user_id IS NULL OR NEW.approved_by_user_id = NEW.created_by_user_id OR NEW.approved_by_user_id = NEW.submitted_by_user_id) THEN
  RAISE EXCEPTION 'A purchase order must be approved by someone who did not raise or submit it' USING ERRCODE='23514';
 END IF;
 IF NEW.status = 'cancelled' AND OLD.status = 'approved'
  AND (NEW.cancelled_by_user_id = NEW.created_by_user_id OR NEW.cancelled_by_user_id = NEW.submitted_by_user_id) THEN
  RAISE EXCEPTION 'An approved purchase order must be cancelled by someone who did not raise or submit it' USING ERRCODE='23514';
 END IF;
 -- STK-21: a complete close only from fully received, a short close only from partly received.
 IF NEW.status = 'closed' AND OLD.status <> 'closed' THEN
  IF (OLD.status = 'partially_received') IS DISTINCT FROM (NEW.close_kind = 'short') OR OLD.status NOT IN ('partially_received', 'fully_received') THEN
   RAISE EXCEPTION 'A purchase order is closed short only when it is partly received' USING ERRCODE='23514';
  END IF;
  IF NEW.close_kind = 'short' THEN
   IF NEW.closed_by_user_id IS NULL OR NEW.closed_by_user_id = NEW.created_by_user_id OR NEW.closed_by_user_id = NEW.submitted_by_user_id THEN
    RAISE EXCEPTION 'A purchase order must be closed short by someone who did not raise or submit it' USING ERRCODE='23514';
   END IF;
   IF EXISTS (SELECT 1 FROM inventory_purchase_order_lines l WHERE l.purchase_order_id = NEW.id AND l.not_delivered_quantity IS NULL) THEN
    RAISE EXCEPTION 'Every line of a short-closed purchase order records what was not delivered' USING ERRCODE='23514';
   END IF;
   IF EXISTS (SELECT 1 FROM inventory_receiving_reviews r WHERE r.purchase_order_id = NEW.id
     AND NOT EXISTS (SELECT 1 FROM inventory_receiving_consumptions c WHERE c.review_id = r.id)
     AND NOT EXISTS (SELECT 1 FROM inventory_receipt_corrections x WHERE x.review_id = r.id)) THEN
    RAISE EXCEPTION 'A purchase order with a receiving review still waiting cannot be closed short' USING ERRCODE='23514';
   END IF;
  END IF;
 END IF;
 RETURN NEW;
END; $$;
SQL);
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
ALTER TABLE inventory_purchase_orders DROP CONSTRAINT IF EXISTS purchase_orders_close_shape;
ALTER TABLE inventory_purchase_order_lines DROP CONSTRAINT IF EXISTS purchase_order_lines_not_delivered_shape;
CREATE OR REPLACE FUNCTION guard_purchase_order_independent_approval() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF OLD.status = 'submitted' AND NEW.status = 'approved'
  AND (NEW.approved_by_user_id IS NULL OR NEW.approved_by_user_id = NEW.created_by_user_id OR NEW.approved_by_user_id = NEW.submitted_by_user_id) THEN
  RAISE EXCEPTION 'A purchase order must be approved by someone who did not raise or submit it' USING ERRCODE='23514';
 END IF;
 IF NEW.status = 'cancelled' AND OLD.status = 'approved'
  AND (NEW.cancelled_by_user_id = NEW.created_by_user_id OR NEW.cancelled_by_user_id = NEW.submitted_by_user_id) THEN
  RAISE EXCEPTION 'An approved purchase order must be cancelled by someone who did not raise or submit it' USING ERRCODE='23514';
 END IF;
 RETURN NEW;
END; $$;
SQL);
        }
        Schema::table('inventory_purchase_order_lines', function (Blueprint $table): void {
            $table->dropColumn('not_delivered_quantity');
        });
        Schema::table('inventory_purchase_orders', function (Blueprint $table): void {
            $table->dropColumn(['close_kind', 'short_close_reason', 'short_close_note']);
        });
    }
};
