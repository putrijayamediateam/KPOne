<?php

namespace App\Domain\Organisation\Inventory\Services;

/**
 * STK-48: the side-by-side comparison of a quote round, in whole-number maths only.
 *
 * Per line, the cheapest supplier is the one with the lowest exact price per stock unit (pack price divided by pack
 * size, compared by cross-multiplication, never a rounded figure); a tie goes to the shorter lead time, then the lower
 * RFQ number. Line totals are rounded half up to whole sen once each. An RFQ total adds its own line totals; a
 * cheapest total is only given when every compared RFQ quotes the same set of lines (Daniel: no marker across
 * unequal sets).
 */
final class RfqComparison
{
    /**
     * @param  array<int, array{id:int, quantity:int}>  $lines  quantity in thousandths of a stock unit
     * @param  array<int, array{id:int, number:string, lead:int, cells:array<int, array{cannot:bool, price:int|null, pack:int|null}>}>  $rfqs
     *                                                                                                                                   price in sen per pack, pack in thousandths of a stock unit
     * @return array{lines: array<int, array{cheapest:int|null, tieBreak:string|null}>, cells: array<int, array<int, array{unitHundredthSen:int|null, lineSen:int|null}>>, totals: array<int, array{sen:int, supplied:int}>, cheapestTotal: int|null, totalTieBreak: string|null}
     */
    public static function compare(array $lines, array $rfqs): array
    {
        $cells = [];
        $totals = [];
        foreach ($rfqs as $rfq) {
            $sum = 0;
            $supplied = 0;
            foreach ($lines as $line) {
                $cell = $rfq['cells'][$line['id']] ?? null;
                if ($cell === null || $cell['cannot'] || $cell['price'] === null || $cell['pack'] === null || $cell['pack'] <= 0) {
                    $cells[$rfq['id']][$line['id']] = ['unitHundredthSen' => null, 'lineSen' => null];

                    continue;
                }
                $lineSen = self::halfUp($line['quantity'] * $cell['price'], $cell['pack']);
                $cells[$rfq['id']][$line['id']] = ['unitHundredthSen' => self::halfUp($cell['price'] * 100_000, $cell['pack']), 'lineSen' => $lineSen];
                $sum += $lineSen;
                $supplied++;
            }
            $totals[$rfq['id']] = ['sen' => $sum, 'supplied' => $supplied];
        }

        $perLine = [];
        foreach ($lines as $line) {
            $candidates = [];
            foreach ($rfqs as $rfq) {
                $cell = $rfq['cells'][$line['id']] ?? null;
                if ($cell !== null && ! $cell['cannot'] && $cell['price'] !== null && $cell['pack'] !== null && $cell['pack'] > 0) {
                    $candidates[] = ['rfq' => $rfq, 'price' => $cell['price'], 'pack' => $cell['pack']];
                }
            }
            if ($candidates === []) {
                $perLine[$line['id']] = ['cheapest' => null, 'tieBreak' => null];

                continue;
            }
            usort($candidates, fn (array $a, array $b): int => ($a['price'] * $b['pack'] <=> $b['price'] * $a['pack'])
                ?: ($a['rfq']['lead'] <=> $b['rfq']['lead']) ?: strcmp($a['rfq']['number'], $b['rfq']['number']));
            $best = $candidates[0];
            $tieBreak = null;
            if (isset($candidates[1]) && $candidates[1]['price'] * $best['pack'] === $best['price'] * $candidates[1]['pack']) {
                $tieBreak = $best['rfq']['lead'] < $candidates[1]['rfq']['lead'] ? 'faster_delivery' : 'lower_number';
            }
            $perLine[$line['id']] = ['cheapest' => $best['rfq']['id'], 'tieBreak' => $tieBreak];
        }

        // A cheapest total only across equal sets of quoted lines.
        $cheapestTotal = null;
        $totalTieBreak = null;
        $sets = [];
        foreach ($rfqs as $rfq) {
            $set = [];
            foreach ($lines as $line) {
                if ($cells[$rfq['id']][$line['id']]['lineSen'] !== null) {
                    $set[] = $line['id'];
                }
            }
            $sets[$rfq['id']] = implode(',', $set);
        }
        if ($rfqs !== [] && count(array_unique($sets)) === 1 && reset($sets) !== '') {
            $ranked = $rfqs;
            usort($ranked, fn (array $a, array $b): int => ($totals[$a['id']]['sen'] <=> $totals[$b['id']]['sen'])
                ?: ($a['lead'] <=> $b['lead']) ?: strcmp($a['number'], $b['number']));
            $cheapestTotal = $ranked[0]['id'];
            if (isset($ranked[1]) && $totals[$ranked[0]['id']]['sen'] === $totals[$ranked[1]['id']]['sen']) {
                $totalTieBreak = $ranked[0]['lead'] < $ranked[1]['lead'] ? 'faster_delivery' : 'lower_number';
            }
        }

        return ['lines' => $perLine, 'cells' => $cells, 'totals' => $totals, 'cheapestTotal' => $cheapestTotal, 'totalTieBreak' => $totalTieBreak];
    }

    /** The price per stock unit in whole sen, rounded half up, as STK-10B requires for an estimate. */
    public static function unitSen(int $packPriceSen, int $packScaled): int
    {
        return self::halfUp($packPriceSen * 1000, $packScaled);
    }

    /** Non-negative numerator over a positive denominator, rounded half up with integers only. */
    public static function halfUp(int $numerator, int $denominator): int
    {
        return intdiv(2 * $numerator + $denominator, 2 * $denominator);
    }
}
