<?php

namespace App\Domain\Organisation\Inventory\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * STK-52: the read side for Dispensary (DSP-24). Dispensary converts whole packs to the SKU's base unit with
 * {@see toBase()} before it allocates, so STK-1's base-unit rule is never relaxed. Inside a save transaction use
 * {@see checkedForShare()}: every pack conversion write takes the SKU row FOR UPDATE, so a check landing at the same
 * moment is either fully seen or not seen. Lock order (agreed with DSP-24): case, line, SKU, batch.
 */
final class PackConversionReader
{
    public const MAX_PACKS = 1000;

    public function checkedFor(int $organisationId, int $skuId): ?CheckedPackConversion
    {
        $sku = DB::table('inventory_skus')->where('organisation_id', $organisationId)->where('id', $skuId)->first(['id', 'stock_unit', 'is_active']);

        return $sku === null ? null : $this->current($sku);
    }

    public function checkedForShare(int $organisationId, int $skuId): ?CheckedPackConversion
    {
        $sku = DB::table('inventory_skus')->where('organisation_id', $organisationId)->where('id', $skuId)->sharedLock()->first(['id', 'stock_unit', 'is_active']);

        return $sku === null ? null : $this->current($sku);
    }

    /** Whole packs (1 to MAX_PACKS) as an STK-1 base quantity in thousandths, for example 2 × 60 → "120.000". */
    public function toBase(CheckedPackConversion $conversion, int $packs): string
    {
        if ($packs < 1 || $packs > self::MAX_PACKS) {
            throw ValidationException::withMessages(['quantity' => 'Enter a whole number of packs ('.$conversion->packUnit.') from 1 to '.self::MAX_PACKS.'.']);
        }

        return StockUnitQuantity::format($packs * $conversion->basePerPack * 1000);
    }

    private function current(\stdClass $sku): ?CheckedPackConversion
    {
        if (! (bool) $sku->is_active) {
            return null;
        }
        $row = DB::table('inventory_pack_conversions as c')->join('inventory_pack_conversion_outcomes as o', 'o.pack_conversion_id', '=', 'c.id')
            ->where('c.inventory_sku_id', $sku->id)->where('o.outcome', 'checked')->orderByDesc('c.version')
            ->first(['c.id', 'c.public_id', 'c.version', 'c.pack_unit', 'c.base_per_pack', 'c.base_unit']);
        // "No pack conversion" checked last, or a base unit that no longer matches the SKU: nothing to convert with.
        if ($row === null || $row->pack_unit === null || (string) $row->base_unit !== (string) $sku->stock_unit) {
            return null;
        }

        return new CheckedPackConversion((int) $row->id, (string) $row->public_id, (int) $sku->id, (int) $row->version, (string) $row->pack_unit, (int) $row->base_per_pack, (string) $row->base_unit);
    }
}
