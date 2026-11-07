<?php

namespace App\Domain\HR\Payroll\Services;

use Illuminate\Validation\ValidationException;

/**
 * Whole-number parsing for payroll (HR-PLAN 14.3 rule 1). Money is whole sen, percentages are basis points and
 * divisors are hundredths, all read from text with integer arithmetic only: no float ever touches a payroll figure.
 */
final class PayrollMoney
{
    public const MAX_SEN = 99999999;

    /** RM text ("2500" or "2,500.00") to whole sen. Null when empty and optional. */
    public static function sen(mixed $value, string $field, bool $optional = false, bool $allowZero = false): ?int
    {
        if (self::blank($value)) {
            if ($optional) {
                return null;
            }
            throw ValidationException::withMessages([$field => 'Enter an amount in RM, for example 2,500.00.']);
        }
        $text = is_int($value) ? (string) $value : (is_string($value) ? str_replace(',', '', trim($value)) : '');
        if (preg_match('/\A(0|[1-9][0-9]{0,5})(?:\.([0-9]{1,2}))?\z/', $text, $m) !== 1) {
            throw ValidationException::withMessages([$field => 'Enter an amount in RM, for example 2,500.00.']);
        }
        $sen = (int) $m[1] * 100 + (int) str_pad($m[2] ?? '', 2, '0');
        if ($sen > self::MAX_SEN || ($sen < 1 && ! $allowZero)) {
            throw ValidationException::withMessages([$field => $allowZero ? 'Enter an amount from RM 0.00 to RM 999,999.99.' : 'Enter an amount from RM 0.01 to RM 999,999.99.']);
        }

        return $sen;
    }

    /** A percentage ("11" or "0.25") to basis points (1100, 25), from 0% to 100%. */
    public static function basisPoints(mixed $value, string $field): int
    {
        $text = is_string($value) ? trim($value) : (is_int($value) ? (string) $value : '');
        if (preg_match('/\A(0|[1-9][0-9]{0,2})(?:\.([0-9]{1,2}))?\z/', $text, $m) !== 1) {
            throw ValidationException::withMessages([$field => 'Enter a percentage, for example 11 or 0.25.']);
        }
        $bps = (int) $m[1] * 100 + (int) str_pad($m[2] ?? '', 2, '0');
        if ($bps > 10000) {
            throw ValidationException::withMessages([$field => 'Enter a percentage from 0 to 100.']);
        }

        return $bps;
    }

    /** A divisor ("26" or "21.67") to hundredths, within a range given in hundredths. */
    public static function hundredths(mixed $value, string $field, int $min, int $max, string $message): int
    {
        $text = is_string($value) ? trim($value) : (is_int($value) ? (string) $value : '');
        if (preg_match('/\A([1-9][0-9]{0,1})(?:\.([0-9]{1,2}))?\z/', $text, $m) !== 1) {
            throw ValidationException::withMessages([$field => $message]);
        }
        $value = (int) $m[1] * 100 + (int) str_pad($m[2] ?? '', 2, '0');
        if ($value < $min || $value > $max) {
            throw ValidationException::withMessages([$field => $message]);
        }

        return $value;
    }

    /** "RM 2,500.00" from whole sen, for messages. */
    public static function rm(int $sen): string
    {
        $ringgit = (string) intdiv($sen, 100);
        $grouped = ltrim(strrev(implode(',', str_split(strrev($ringgit), 3))), ',');

        return 'RM '.$grouped.'.'.str_pad((string) ($sen % 100), 2, '0', STR_PAD_LEFT);
    }

    /** "11.00%" from basis points. */
    public static function percent(int $bps): string
    {
        return intdiv($bps, 100).'.'.str_pad((string) ($bps % 100), 2, '0', STR_PAD_LEFT).'%';
    }

    /** "26" or "21.67" from hundredths. */
    public static function divisor(int $hundredths): string
    {
        return $hundredths % 100 === 0 ? (string) intdiv($hundredths, 100) : intdiv($hundredths, 100).'.'.str_pad((string) ($hundredths % 100), 2, '0', STR_PAD_LEFT);
    }

    public static function blank(mixed $value): bool
    {
        return $value === null || (is_string($value) && trim($value) === '');
    }
}
