<?php

namespace App\Domain\HR\Payroll\Services;

use RuntimeException;

/**
 * Exact whole-number arithmetic for payroll lines (HR-PLAN 16.3). A line is a product of whole factors divided by a whole
 * denominator, kept exact until the one rounding the line names, so nothing is rounded twice. The product is carried as
 * a quotient and a remainder, so it never overflows while the result itself fits; no float ever touches a figure.
 */
final class PayrollMath
{
    public const HALF_UP = 'half_up';

    public const DOWN = 'down';

    public const UP_RINGGIT = 'up_ringgit';

    /**
     * (f1 × f2 × … ÷ denominator), rounded once.
     *
     * @param  list<int>  $factors  each zero or more
     */
    public static function fraction(array $factors, int $denominator, string $rounding = self::HALF_UP): int
    {
        if ($denominator < 1) {
            throw new RuntimeException('A payroll denominator must be positive.');
        }
        [$q, $r] = [1, 0]; // value = q + r / d
        $first = true;
        foreach ($factors as $f) {
            if ($f < 0) {
                throw new RuntimeException('Payroll factors are never negative.');
            }
            if ($first) {
                [$q, $r] = [intdiv($f, $denominator), $f % $denominator];
                $first = false;

                continue;
            }
            if ($f !== 0 && $q > intdiv(PHP_INT_MAX - intdiv($r * $f, $denominator), $f)) {
                throw new RuntimeException('A payroll figure is too large.');
            }
            [$q, $r] = [$q * $f + intdiv($r * $f, $denominator), ($r * $f) % $denominator];
        }
        if ($first) {
            [$q, $r] = [intdiv(1, $denominator), 1 % $denominator];
        }

        return match ($rounding) {
            self::DOWN => $q,
            self::HALF_UP => $q + (2 * $r >= $denominator ? 1 : 0),
            self::UP_RINGGIT => ($r === 0 && $q % 100 === 0) ? $q : (intdiv($q, 100) + 1) * 100,
            default => throw new RuntimeException('Unknown payroll rounding.'),
        };
    }

    /** A table's rounding rule (HR-7a) as one of the three roundings here. */
    public static function forTable(string $rule): string
    {
        return match ($rule) {
            'none' => self::DOWN,
            'nearest_sen' => self::HALF_UP,
            'up_ringgit' => self::UP_RINGGIT,
            default => throw new RuntimeException('Unknown table rounding.'),
        };
    }

    /** A stored decimal multiplier ("1.50") as hundredths (150), read from its text. */
    public static function hundredths(string $decimal): int
    {
        if (preg_match('/\A(\d{1,2})(?:\.(\d{1,2}))?\z/', trim($decimal), $m) !== 1) {
            throw new RuntimeException('A multiplier is not a plain decimal.');
        }

        return (int) $m[1] * 100 + (int) str_pad($m[2] ?? '', 2, '0');
    }
}
