<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * STK-9 owner decision 2026-11-05: one barcode maps to one SKU per organisation. Codes compare trimmed and
 * case-insensitively. A new or changed barcode that another SKU already uses is refused; existing duplicates are
 * left as they are (no data is changed) and listed on the Pilot readiness page for staff to fix by hand.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('CREATE INDEX inventory_skus_barcode_lookup ON inventory_skus (organisation_id, upper(trim(barcode))) WHERE barcode IS NOT NULL');
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION guard_inventory_sku_barcode() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE code text := upper(trim(coalesce(NEW.barcode, '')));
BEGIN
 IF code = '' OR (TG_OP = 'UPDATE' AND code = upper(trim(coalesce(OLD.barcode, ''))) AND NEW.organisation_id = OLD.organisation_id) THEN
  RETURN NEW;
 END IF;
 -- Serialise concurrent saves of the same code in one organisation.
 PERFORM pg_advisory_xact_lock(hashtext('inventory_sku_barcode'), hashtext(NEW.organisation_id::text || ':' || code));
 IF EXISTS (SELECT 1 FROM inventory_skus s WHERE s.organisation_id = NEW.organisation_id AND s.id IS DISTINCT FROM NEW.id
   AND s.barcode IS NOT NULL AND upper(trim(s.barcode)) = code) THEN
  RAISE EXCEPTION 'This barcode is already used by another SKU' USING ERRCODE = '23505';
 END IF;
 RETURN NEW;
END; $$;
CREATE TRIGGER inventory_skus_unique_barcode BEFORE INSERT OR UPDATE OF barcode, organisation_id ON inventory_skus
 FOR EACH ROW EXECUTE FUNCTION guard_inventory_sku_barcode();
SQL);
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared('DROP TRIGGER IF EXISTS inventory_skus_unique_barcode ON inventory_skus; DROP FUNCTION IF EXISTS guard_inventory_sku_barcode(); DROP INDEX IF EXISTS inventory_skus_barcode_lookup;');
    }
};
