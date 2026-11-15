<?php

namespace App\Domain\Visit\Billing\Services;

use Illuminate\Validation\ValidationException;

final class ExactMoney
{
    public const MAX_SEN = 999_999_999_999;

    public static function sen(mixed $value, string $field = 'amount_sen'): int
    {
        if ((! is_string($value) && ! is_int($value)) || preg_match('/\A(?:0|[1-9][0-9]{0,11})\z/', (string) $value) !== 1) {
            throw ValidationException::withMessages([$field => 'Enter a bounded nonnegative whole number of sen.']);
        }

        return (int) $value;
    }

    public static function quantity(mixed $value, string $field = 'quantity'): int
    {
        if ((! is_string($value) && ! is_int($value)) || preg_match('/\A(0|[1-9][0-9]{0,8})(?:\.([0-9]{1,3}))?\z/', (string) $value, $parts) !== 1) {
            throw ValidationException::withMessages([$field => 'Enter an exact quantity with at most three decimal places.']);
        }

        return ((int) $parts[1]) * 1000 + (int) str_pad($parts[2] ?? '', 3, '0');
    }

    public static function decimal(int $milli): string
    {
        return intdiv($milli, 1000).'.'.str_pad((string) ($milli % 1000), 3, '0', STR_PAD_LEFT);
    }

    public static function line(int $milli, int $unitSen): int
    {
        if ($milli < 1 || $unitSen < 0 || $unitSen > self::MAX_SEN || ($unitSen > 0 && $milli > intdiv(PHP_INT_MAX - 500, $unitSen))) {
            throw ValidationException::withMessages(['price' => 'The quantity or price exceeds the safe calculation range.']);
        }
        $total = intdiv($milli * $unitSen + 500, 1000);
        if ($total > self::MAX_SEN) {
            throw ValidationException::withMessages(['price' => 'The line exceeds the permitted monetary range.']);
        }

        return $total;
    }
}
