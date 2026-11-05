<?php

namespace App\Domain\Visit\Billing\Services;

use App\Domain\Visit\Billing\Models\ChargeDefinition;
use App\Domain\Visit\Billing\Models\PriceBook;
use App\Domain\Visit\Billing\Models\PriceEntry;
use App\Domain\Visit\Models\Visit;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class PriceResolutionService
{
    /** @param list<array<string,mixed>> $lines
     * @return list<array<string,mixed>>
     */
    public function price(Visit $visit, array $lines): array
    {
        $charges = ChargeDefinition::query()->where('organisation_id', $visit->organisation_id)->whereIn('source_key', array_column($lines, 'charge_key'))->where('is_active', true)->orderBy('id')->lockForUpdate()->get()->keyBy('source_key');
        $books = PriceBook::query()->where('organisation_id', $visit->organisation_id)->where('currency', 'MYR')->where('is_active', true)
            ->where(function ($query) use ($visit): void {
                $query->where(function ($selfPay) use ($visit): void {
                    $selfPay->where('price_tier', 'self_pay')
                        ->where(fn ($scope) => $scope->whereNull('branch_id')->orWhere('branch_id', $visit->branch_id));
                })->orWhere(function ($panel) use ($visit): void {
                    $panel->where('price_tier', 'panel')->whereNull('branch_id')
                        ->where(function ($scope) use ($visit): void {
                            $scope->whereNull('panel_id');
                            if ($visit->panel_id !== null) {
                                $scope->orWhere('panel_id', $visit->panel_id);
                            }
                        });
                });
            })->orderBy('id')->lockForUpdate()->get();
        $entries = PriceEntry::query()->where('organisation_id', $visit->organisation_id)->whereIn('price_book_id', $books->pluck('id'))->whereIn('charge_definition_id', $charges->pluck('id'))->where('effective_at', '<=', now()->utc())->orderBy('id')->lockForUpdate()->get();
        foreach ($lines as &$line) {
            $charge = $charges->get($line['charge_key']);
            $price = $this->select($books, $entries, $charge, $visit->coverage_type === 'panel', $visit->panel_id, (int) $visit->branch_id);
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

    /**
     * The one price choice billing makes for a charge, shared with the CAT-2 price list check so the two can
     * never disagree. A Panel visit uses the visit Panel's own book, then the default Panel book; a Self-pay visit
     * uses the visit branch's Self-pay book, then the organisation one. Within a book the highest version wins.
     * The caller supplies active MYR books and entries already in effect; nothing is queried or locked here.
     *
     * @param  Collection<int, PriceBook>  $books
     * @param  Collection<int, PriceEntry>  $entries
     */
    public function select(Collection $books, Collection $entries, ?ChargeDefinition $charge, bool $panelCoverage, ?int $panelId, int $branchId): ?PriceEntry
    {
        if ($charge === null) {
            return null;
        }
        $candidates = $panelCoverage
            ? [
                $panelId === null ? null : $books->first(fn (PriceBook $book): bool => $book->price_tier === 'panel' && $book->panel_id === $panelId),
                $books->first(fn (PriceBook $book): bool => $book->price_tier === 'panel' && $book->panel_id === null),
            ]
            : [
                $books->first(fn (PriceBook $book): bool => $book->price_tier === 'self_pay' && $book->branch_id === $branchId),
                $books->first(fn (PriceBook $book): bool => $book->price_tier === 'self_pay' && $book->branch_id === null),
            ];
        foreach ($candidates as $book) {
            if ($book) {
                $price = $entries->where('price_book_id', $book->id)->where('charge_definition_id', $charge->id)->sortByDesc('version')->first();
                if ($price) {
                    return $price;
                }
            }
        }

        return null;
    }
}
