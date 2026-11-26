<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('inventory_purchase_orders', 'expected_delivery_date')) {
            Schema::table('inventory_purchase_orders', function (Blueprint $table): void {
                $table->date('expected_delivery_date')->nullable();
            });
        }
        if (! Schema::hasColumn('inventory_purchase_orders', 'supplier_reference')) {
            Schema::table('inventory_purchase_orders', function (Blueprint $table): void {
                $table->string('supplier_reference', 120)->nullable();
            });
        }

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION guard_purchase_order_delivery_metadata() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF NEW.expected_delivery_date IS DISTINCT FROM OLD.expected_delivery_date
    OR NEW.supplier_reference IS DISTINCT FROM OLD.supplier_reference THEN
  IF OLD.status <> 'draft' OR NEW.status <> 'draft'
     OR NEW.lock_version <> OLD.lock_version + 1 THEN
   RAISE EXCEPTION 'Purchase order delivery metadata changes require a current draft version' USING ERRCODE='23514';
  END IF;
 END IF;
 RETURN NEW;
END; $$;
DROP TRIGGER IF EXISTS purchase_order_delivery_metadata_guard ON inventory_purchase_orders;
CREATE TRIGGER purchase_order_delivery_metadata_guard BEFORE UPDATE ON inventory_purchase_orders
FOR EACH ROW EXECUTE FUNCTION guard_purchase_order_delivery_metadata();
SQL);
        }
    }

    public function down(): void
    {
        // Preserve saved purchase-order metadata and its guard. Reversal requires
        // a separately reviewed forward migration; rollback must not erase history.
    }
};
