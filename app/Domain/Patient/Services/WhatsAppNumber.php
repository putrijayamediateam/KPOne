<?php

namespace App\Domain\Patient\Services;

final class WhatsAppNumber
{
    public static function normalize(mixed $value): ?string
    {
        if (! is_string($value) || mb_strlen($value) > 128 || ! preg_match('/^[+0-9 ().\p{Zs}-]+$/uD', $value) || str_contains($value, '..')) {
            return null;
        }
        $compact = preg_replace('/[ ().\p{Zs}-]/u', '', $value) ?? '';
        $plus = str_starts_with($compact, '+');
        $digits = $plus ? substr($compact, 1) : $compact;
        if (! preg_match('/^[0-9]+$/D', $digits)) {
            return null;
        }
        if (! $plus && str_starts_with($digits, '0')) {
            $digits = '60'.substr($digits, 1);
        }
        if (str_starts_with($digits, '60')) {
            return preg_match('/^60(?:11[0-9]{8}|1[0-9]{7,8}|3[0-9]{8}|[4-79][0-9]{7}|8[0-9]{7,8})$/D', $digits) ? $digits : null;
        }

        return $plus && preg_match('/^[1-9][0-9]{7,14}$/D', $digits) ? $digits : null;
    }
}
