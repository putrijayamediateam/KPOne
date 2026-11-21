<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * DSP-24 (owner decision 17 of 20-21 Nov 2026; answer 14 = A): the CA dispenses whole packs (for example bottles of
 * Paracetamol syrup) through a checked STK-52 conversion. Stock still moves in the base unit: quantity_dispensed stays
 * the ml, and the line keeps the exact conversion version and the number of packs.
 *
 * - An ordinary trigger on dispensary_items: both columns null, or both set with a usable conversion (not a "stop"
 *   version) whose base_per_pack × packs equals quantity_dispensed, and whose SKU is the medicine's active mapped SKU.
 * - A deferred constraint trigger on dispensary_items and dispensary_item_batch_allocations (Hakim): the conversion's
 *   SKU is the SKU of every allocation of the line, checked at commit, because the item is written before its picks.
 *
 * In the Inventory range by a one-off exception (as DSP-7), right after STK-52's tables (003200), which it references.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dispensary_items', function (Blueprint $table): void {
            // No foreign key: STK-52's conversions are append-only (their trigger refuses delete), the insert trigger
            // below refuses a missing or foreign conversion, and a key would stop STK-52's own migration rolling back.
            $table->unsignedBigInteger('pack_conversion_id')->nullable()->index();
            $table->unsignedSmallInteger('packs_dispensed')->nullable();
        });

        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE dispensary_items ADD CONSTRAINT dispensary_items_packs_shape CHECK (
            (pack_conversion_id IS NULL) = (packs_dispensed IS NULL)
            AND (packs_dispensed IS NULL OR packs_dispensed BETWEEN 1 AND 99)
        )');
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION validate_dispensary_item_packs() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE
    conv inventory_pack_conversions%ROWTYPE;
BEGIN
    IF NEW.pack_conversion_id IS NULL THEN
        RETURN NEW;
    END IF;
    SELECT * INTO conv FROM inventory_pack_conversions WHERE id = NEW.pack_conversion_id;
    IF conv.id IS NULL OR conv.organisation_id <> NEW.organisation_id OR conv.base_per_pack IS NULL OR conv.pack_unit IS NULL THEN
        RAISE EXCEPTION 'Invalid pack conversion for this line' USING ERRCODE = '23514';
    END IF;
    IF NEW.quantity_dispensed IS NULL OR NEW.quantity_dispensed <> (NEW.packs_dispensed::numeric * conv.base_per_pack) THEN
        RAISE EXCEPTION 'Packs do not match the dispensed quantity' USING ERRCODE = '23514';
    END IF;
    IF NOT EXISTS (
        SELECT 1 FROM medicine_catalogue_inventory_skus m
        WHERE m.medicine_catalogue_item_id = NEW.medicine_catalogue_item_id AND m.inventory_sku_id = conv.inventory_sku_id AND m.is_active
    ) THEN
        RAISE EXCEPTION 'The pack conversion belongs to another item' USING ERRCODE = '23514';
    END IF;
    RETURN NEW;
END; $$;
CREATE TRIGGER dispensary_items_packs_valid BEFORE INSERT OR UPDATE OF pack_conversion_id, packs_dispensed, quantity_dispensed ON dispensary_items
    FOR EACH ROW EXECUTE FUNCTION validate_dispensary_item_packs();

CREATE OR REPLACE FUNCTION enforce_dispensary_pack_allocation_sku() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE
    item_id bigint;
BEGIN
    -- Two tables share this function, so read each one's own column (a CASE would compile both field references).
    IF TG_TABLE_NAME = 'dispensary_items' THEN
        item_id := NEW.id;
    ELSE
        item_id := NEW.dispensary_item_id;
    END IF;
    IF EXISTS (
        SELECT 1
        FROM dispensary_items i
        JOIN inventory_pack_conversions c ON c.id = i.pack_conversion_id
        JOIN dispensary_item_batch_allocations a ON a.dispensary_item_id = i.id
        WHERE i.id = item_id AND a.inventory_sku_id <> c.inventory_sku_id
    ) THEN
        RAISE EXCEPTION 'A batch pick is from a different SKU than the pack conversion' USING ERRCODE = '23514';
    END IF;
    RETURN NULL;
END; $$;
CREATE CONSTRAINT TRIGGER dispensary_items_pack_allocation_sku AFTER INSERT OR UPDATE ON dispensary_items
    DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION enforce_dispensary_pack_allocation_sku();
CREATE CONSTRAINT TRIGGER dispensary_allocations_pack_sku AFTER INSERT OR UPDATE ON dispensary_item_batch_allocations
    DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION enforce_dispensary_pack_allocation_sku();
SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('DSP-24 keeps the packs dispensed on each line as dispensing evidence; this migration cannot be rolled back.');
    }
};
