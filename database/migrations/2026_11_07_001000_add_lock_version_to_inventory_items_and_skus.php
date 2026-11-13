<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * STK-14: an optimistic version on Inventory Items and SKUs, so the SKU edit screen can refuse a stale save.
 * PostgreSQL raises the version by one on every update, so existing writers that do not know about it (seeders,
 * STK-1 base-unit verification, STK-9 barcode fixes, receiving) keep working; a write that sets the version itself
 * must set exactly the next one.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['inventory_items', 'inventory_skus'] as $table) {
            Schema::table($table, fn (Blueprint $t) => $t->unsignedInteger('lock_version')->default(1));
        }
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION kpone_inventory_reference_version() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF NEW.lock_version = OLD.lock_version THEN
  NEW.lock_version := OLD.lock_version + 1;
 ELSIF NEW.lock_version <> OLD.lock_version + 1 THEN
  RAISE EXCEPTION 'Invalid inventory reference version' USING ERRCODE='23514';
 END IF;
 RETURN NEW;
END; $$;
CREATE TRIGGER inventory_items_version BEFORE UPDATE ON inventory_items FOR EACH ROW EXECUTE FUNCTION kpone_inventory_reference_version();
CREATE TRIGGER inventory_skus_version BEFORE UPDATE ON inventory_skus FOR EACH ROW EXECUTE FUNCTION kpone_inventory_reference_version();
ALTER TABLE inventory_items ADD CONSTRAINT inventory_items_lock_version_positive CHECK (lock_version >= 1);
ALTER TABLE inventory_skus ADD CONSTRAINT inventory_skus_lock_version_positive CHECK (lock_version >= 1);
SQL);
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::unprepared('DROP TRIGGER IF EXISTS inventory_items_version ON inventory_items; DROP TRIGGER IF EXISTS inventory_skus_version ON inventory_skus; DROP FUNCTION IF EXISTS kpone_inventory_reference_version();');
        }
        foreach (['inventory_items', 'inventory_skus'] as $table) {
            Schema::table($table, fn (Blueprint $t) => $t->dropColumn('lock_version'));
        }
    }
};
