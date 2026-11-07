<?php

namespace App\Domain\HR\Payroll\Services;

/**
 * HR-7d MTD (PCB) for normal monthly remuneration (HR-PLAN 18.4), in whole sen with one rounding at the end. Pure: it
 * takes the schedule the clinic entered and checked, the person's MTD details and the figures in sen, and returns the
 * month's MTD. KPOne ships no tax number; every figure here comes from the clinic's schedule.
 *
 * P = Y − K + (Y1 − K1) + (Y2 − K2) × n − reliefs, never below zero, with the EPF relief limit applied to the year's
 * total (K2 the lower of K1 and what is left of the limit spread over n). MTD = ((P − M) × R + B − X) ÷ (n + 1), never
 * below zero, rounded as the schedule says, and nothing when below the smallest MTD.
 */
final class MtdCalculator
{
    public const ROUNDING = ['up_5_sen' => 'Up to the next 5 sen', 'nearest_sen' => 'To the nearest sen', 'none' => 'Drop part sen (round down to the sen)'];

    public const CATEGORIES = [1 => 'Category 1 (single)', 2 => 'Category 2 (married, spouse not working)', 3 => 'Category 3 (married, spouse working)'];

    /**
     * @param  array{rounding:string, minimum_sen:int, individual_relief_sen:int, spouse_relief_sen:int, disabled_self_relief_sen:int, disabled_spouse_relief_sen:int, child_relief_sen:int, epf_relief_cap_sen:int}  $schedule
     * @param  list<array{from:int, to:?int, rate_bps:int, base_cat13:int, base_cat2:int}>  $bands
     * @param  array{category:int, children:int, disabled_self:bool, disabled_spouse:bool}  $person
     * @param  array{y:int, k:int, x:int, y1:int, k1:int}  $figures  sen: year to date pay (Y), EPF (K) and MTD paid (X); this month's pay (Y1) and EPF (K1)
     * @param  int  $month  1 to 12
     * @return array{amount:int, chargeable:int, band:?int, monthsLeft:int}|null null when no band holds P
     */
    public static function month(array $schedule, array $bands, array $person, array $figures, int $month): ?array
    {
        $n = 12 - $month;
        $y1 = max(0, $figures['y1']);
        $cap = $schedule['epf_relief_cap_sen'];
        $k = min(max(0, $figures['k']), $cap);
        $k1 = min(max(0, $figures['k1']), $cap - $k);
        $k2 = $n === 0 ? 0 : min($k1, intdiv(max(0, $cap - $k - $k1), $n));
        $reliefs = $schedule['individual_relief_sen'] + $schedule['child_relief_sen'] * $person['children']
            + ($person['category'] === 2 ? $schedule['spouse_relief_sen'] : 0)
            + ($person['disabled_self'] ? $schedule['disabled_self_relief_sen'] : 0)
            + ($person['category'] === 2 && $person['disabled_spouse'] ? $schedule['disabled_spouse_relief_sen'] : 0);
        $p = max(0, $figures['y'] - $k + ($y1 - $k1) + ($y1 - $k2) * $n - $reliefs);

        $index = null;
        foreach ($bands as $i => $b) {
            if ($p >= $b['from'] && ($b['to'] === null || $p <= $b['to'])) {
                $index = $i;
                break;
            }
        }
        if ($index === null) {
            return null;
        }
        $band = $bands[$index];
        $base = $person['category'] === 2 ? $band['base_cat2'] : $band['base_cat13'];
        // ((P − M) × R ÷ 10000 + B − X) ÷ (n + 1), kept exact over one denominator.
        $numerator = ($p - $band['from']) * $band['rate_bps'] + ($base - max(0, $figures['x'])) * 10000;
        $denominator = 10000 * ($n + 1);
        $amount = $numerator <= 0 ? 0 : self::rounded($numerator, $denominator, $schedule['rounding']);
        if ($amount < $schedule['minimum_sen']) {
            $amount = 0;
        }

        return ['amount' => $amount, 'chargeable' => $p, 'band' => $index, 'monthsLeft' => $n];
    }

    private static function rounded(int $numerator, int $denominator, string $rounding): int
    {
        $q = intdiv($numerator, $denominator);
        $r = $numerator % $denominator;

        return match ($rounding) {
            'none' => $q,
            'nearest_sen' => $q + (2 * $r >= $denominator ? 1 : 0),
            'up_5_sen' => ($r === 0 && $q % 5 === 0) ? $q : (intdiv($q, 5) + 1) * 5,
            default => throw new \RuntimeException('Unknown MTD rounding.'),
        };
    }
}
