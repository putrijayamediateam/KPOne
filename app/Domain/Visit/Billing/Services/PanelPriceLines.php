<?php

namespace App\Domain\Visit\Billing\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Read-only Panel prices of one charge for the catalogue screens (owner decision 27): for the default Panel price and
 * every Panel that has its own price, the price in effect now and, when there is one, the next price that starts later.
 */
final class PanelPriceLines
{
    /** @return list<array{name: string, isDefault: bool, amountRm: string|null, scheduled: array{amountRm: string, label: string}|null}> */
    public function forCharge(int $organisationId, ?int $chargeId, string $timezone): array
    {
        if ($chargeId === null) {
            return [];
        }
        $books = DB::table('price_books as b')->leftJoin('panels as p', 'p.id', '=', 'b.panel_id')
            ->where('b.organisation_id', $organisationId)->where('b.price_tier', 'panel')->whereNull('b.branch_id')->where('b.currency', 'MYR')->where('b.is_active', true)
            ->get(['b.id', 'b.panel_id', 'p.name as panel_name']);
        if ($books->isEmpty()) {
            return [];
        }
        $entries = DB::table('price_entries')->where('organisation_id', $organisationId)->where('charge_definition_id', $chargeId)
            ->whereIn('price_book_id', $books->pluck('id')->all())->get(['price_book_id', 'unit_price_sen', 'version', 'effective_at'])->groupBy('price_book_id');
        $now = CarbonImmutable::now('UTC');
        $rm = static fn (int $sen): string => intdiv($sen, 100).'.'.str_pad((string) ($sen % 100), 2, '0', STR_PAD_LEFT);
        $lines = [];
        foreach ($books as $book) {
            $forBook = $entries->get($book->id);
            if ($forBook === null) {
                continue;
            }
            $at = static fn ($e): CarbonImmutable => CarbonImmutable::parse((string) $e->effective_at, 'UTC');
            $sorted = $forBook->sortByDesc(fn ($e): string => $at($e)->format('Y-m-d H:i:s.u').sprintf('%020d', $e->version));
            $inEffect = $sorted->first(fn ($e): bool => $at($e)->lte($now));
            $later = $forBook->filter(fn ($e): bool => $at($e)->gt($now))->sortBy(fn ($e): string => $at($e)->format('Y-m-d H:i:s.u').sprintf('%020d', $e->version))->first();
            $lines[] = [
                'name' => $book->panel_id === null ? 'Default Panel price' : (string) $book->panel_name,
                'isDefault' => $book->panel_id === null,
                'amountRm' => $inEffect === null ? null : $rm((int) $inEffect->unit_price_sen),
                'scheduled' => $later === null ? null : ['amountRm' => $rm((int) $later->unit_price_sen), 'label' => $at($later)->setTimezone($timezone)->format('j M Y, g:i A')],
            ];
        }
        usort($lines, static fn (array $a, array $b): int => [! $a['isDefault'], $a['name']] <=> [! $b['isDefault'], $b['name']]);

        return $lines;
    }
}
