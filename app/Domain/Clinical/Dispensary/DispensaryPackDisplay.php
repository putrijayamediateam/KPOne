<?php

namespace App\Domain\Clinical\Dispensary;

use App\Domain\Clinical\Dispensary\Labels\DrugLabelLayout;
use App\Domain\Clinical\Dispensary\Models\DispensaryItem;
use Illuminate\Support\Facades\DB;

/**
 * DSP-24: how a line dispensed in whole packs reads on screen, for example "2 bottles (120 ml)". One read for all the
 * lines of a page. A line dispensed in the base unit only has no entry, and screens keep showing "120 ml" as before.
 */
final class DispensaryPackDisplay
{
    /**
     * @param  iterable<DispensaryItem>  $items
     * @return array<int, array{packs: int, packUnit: string, text: string}> keyed by dispensary item id
     */
    public static function forItems(iterable $items): array
    {
        $packed = [];
        foreach ($items as $item) {
            if ($item->pack_conversion_id !== null && $item->packs_dispensed !== null && $item->quantity_dispensed !== null) {
                $packed[] = $item;
            }
        }
        if ($packed === []) {
            return [];
        }
        $conversions = DB::table('inventory_pack_conversions')->whereIn('id', array_map(fn (DispensaryItem $i): int => (int) $i->pack_conversion_id, $packed))->get(['id', 'pack_unit', 'base_unit'])->keyBy('id');
        $display = [];
        foreach ($packed as $item) {
            $conversion = $conversions->get($item->pack_conversion_id);
            if ($conversion === null || $conversion->pack_unit === null) {
                continue;
            }
            $display[$item->id] = [
                'packs' => (int) $item->packs_dispensed,
                'packUnit' => (string) $conversion->pack_unit,
                'text' => DrugLabelLayout::packQuantity((int) $item->packs_dispensed, (string) $conversion->pack_unit, (string) $item->quantity_dispensed, (string) $conversion->base_unit),
            ];
        }

        return $display;
    }

    /** The on-screen text, for example "2 bottles (120 ml)", or null for a line dispensed in the base unit. */
    public static function text(DispensaryItem $item): ?string
    {
        return self::forItems([$item])[$item->id]['text'] ?? null;
    }
}
