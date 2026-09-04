<?php

namespace App\Domain\Visit\Billing\Services;

use App\Domain\Visit\Billing\Models\ChargeDefinition;
use App\Domain\Visit\Billing\Models\PriceBook;
use App\Domain\Visit\Billing\Models\PriceEntry;
use App\Domain\Visit\Models\Visit;
use Illuminate\Validation\ValidationException;

class PriceResolutionService
{
    /** @param list<array<string,mixed>> $lines
     * @return list<array<string,mixed>>
     */
    public function price(Visit $visit, array $lines): array
    {
        $charges = ChargeDefinition::query()->where('organisation_id', $visit->organisation_id)->whereIn('source_key', array_column($lines, 'charge_key'))->where('is_active', true)->orderBy('id')->lockForUpdate()->get()->keyBy('source_key');
        $books = PriceBook::query()->where('organisation_id', $visit->organisation_id)->where('currency', 'MYR')->where('is_active', true)->where(fn ($q) => $q->whereNull('branch_id')->orWhere('branch_id', $visit->branch_id))->orderBy('id')->lockForUpdate()->get();
        $entries = PriceEntry::query()->where('organisation_id', $visit->organisation_id)->whereIn('price_book_id', $books->pluck('id'))->whereIn('charge_definition_id', $charges->pluck('id'))->where('effective_at', '<=', now()->utc())->orderBy('id')->lockForUpdate()->get();
        foreach ($lines as &$line) {
            $charge = $charges->get($line['charge_key']);
            $price = null;
            foreach ([$books->firstWhere('branch_id', $visit->branch_id), $books->firstWhere('branch_id', null)] as $book) {
                if ($book && $charge) {
                    $price = $entries->where('price_book_id', $book->id)->where('charge_definition_id', $charge->id)->sortByDesc('version')->first();
                    if ($price) {
                        break;
                    }
                }
            }
            if (! $charge || ! $price || $charge->unit !== $line['unit_snapshot']) {
                throw ValidationException::withMessages(['price' => 'A governed price with the exact selling unit is required for every charge.']);
            }
            unset($line['charge_key']);
            $line += ['charge_definition_id' => $charge->id, 'price_entry_id' => $price->id, 'price_version' => $price->version,
                'unit_price_sen' => $price->unit_price_sen, 'line_total_sen' => ExactMoney::line(ExactMoney::quantity($line['quantity']), $price->unit_price_sen)];
        }
        unset($line);

        return $lines;
    }
}
