<?php

namespace App\Domain\Organisation\Inventory\Services;

use Illuminate\Validation\ValidationException;

/** Quantities are thousandths of one base stock unit, never binary floats. */
final class StockUnitQuantity
{
    public const MAX = 999999999999999;

    public static function scaled(mixed $value, bool $allowZero = false): int
    {
        if (! is_string($value) && ! is_int($value)) {
            self::refuse('Enter an exact decimal quantity, not a floating-point value.');
        }
        $text = trim((string) $value);
        if (! preg_match('/^(\d+)(?:\.(\d+))?$/D', $text, $parts)) {
            self::refuse('Enter a plain decimal quantity.');
        }
        $whole = ltrim($parts[1], '0');
        $fraction = rtrim($parts[2] ?? '', '0');
        if (strlen($whole) > 12 || strlen($fraction) > 3) {
            self::refuse('Quantity exceeds the stock range or would lose precision. No rounding is permitted.');
        }
        $scaled = (int) ($whole ?: '0') * 1000 + (int) str_pad($fraction, 3, '0');
        if ((! $allowZero && $scaled === 0) || $scaled > self::MAX) {
            self::refuse('Quantity must be positive and within the stock range.');
        }

        return $scaled;
    }

    public static function format(int $scaled): string
    {
        return intdiv($scaled, 1000).'.'.str_pad((string) ($scaled % 1000), 3, '0', STR_PAD_LEFT);
    }

    public static function base(mixed $value, string $base, ?string $inputUnit = null): string
    {
        if (trim($base) === '' || ($inputUnit !== null && $inputUnit !== $base)) {
            self::refuse('Only the SKU base stock unit is supported. Conversions are not inferred.');
        }

        return self::format(self::scaled($value));
    }

    private static function refuse(string $message): never
    {
        throw ValidationException::withMessages(['quantity' => $message]);
    }
}
