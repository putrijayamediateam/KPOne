<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * STK-19: PostgreSQL repeats the service rule. The person who approves a purchase order, or cancels it once it is
 * approved, is never the person who raised or submitted it. Only new changes are checked; existing rows are left.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION guard_purchase_order_independent_approval() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF NEW.status = 'approved' AND OLD.status IS DISTINCT FROM 'approved'
  AND (NEW.approved_by_user_id IS NULL OR NEW.approved_by_user_id = NEW.created_by_user_id OR NEW.approved_by_user_id = NEW.submitted_by_user_id) THEN
  RAISE EXCEPTION 'A purchase order must be approved by someone who did not raise or submit it' USING ERRCODE='23514';
 END IF;
 IF NEW.status = 'cancelled' AND OLD.status = 'approved'
  AND (NEW.cancelled_by_user_id = NEW.created_by_user_id OR NEW.cancelled_by_user_id = NEW.submitted_by_user_id) THEN
  RAISE EXCEPTION 'An approved purchase order must be cancelled by someone who did not raise or submit it' USING ERRCODE='23514';
 END IF;
 RETURN NEW;
END; $$;
DROP TRIGGER IF EXISTS purchase_orders_independent_approval ON inventory_purchase_orders;
CREATE TRIGGER purchase_orders_independent_approval BEFORE UPDATE ON inventory_purchase_orders FOR EACH ROW EXECUTE FUNCTION guard_purchase_order_independent_approval();
SQL);
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::unprepared('DROP TRIGGER IF EXISTS purchase_orders_independent_approval ON inventory_purchase_orders; DROP FUNCTION IF EXISTS guard_purchase_order_independent_approval();');
        }
    }
};
