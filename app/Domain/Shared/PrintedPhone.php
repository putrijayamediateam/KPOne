<?php

namespace App\Domain\Shared;

/**
 * One reading format for a clinic phone on every print (drug label, invoice, receipt, MC); owner decision
 * 2026-11-06, Daniel's recommendation. Display only: the stored value never changes.
 *
 * Malaysian numbers given as +60, 60 or 0 print in national form: 03-2123 4567 (8-digit 03 lines),
 * 0X-XXX XXXX (other area codes), 08X-XXX XXX (Sabah and Sarawak), 01X-XXX XXXX and 011-XXXX XXXX (mobiles).
 * Anything else prints as stored (trimmed), so a foreign or unusual number is never rewritten.
 */
final class PrintedPhone
{
    public static function format(?string $stored): ?string
    {
        if ($stored === null) {
            return null;
        }
        $stored = trim($stored);
        if ($stored === '' || preg_match('/\A\+?[0-9 ().-]+\z/D', $stored) !== 1) {
            return $stored === '' ? null : $stored;
        }

        $digits = (string) preg_replace('/\D/', '', $stored);
        if ($digits === '') {
            return $stored;
        }
        $international = str_starts_with($stored, '+');
        if (str_starts_with($digits, '60') && ! str_starts_with($stored, '0')) {
            $national = '0'.substr($digits, 2);
        } elseif (! $international && str_starts_with($digits, '0')) {
            $national = $digits;
        } else {
            return $stored;
        }

        $patterns = [
            '/\A(011)(\d{4})(\d{4})\z/' => '$1-$2 $3',
            '/\A(01[02-9])(\d{3})(\d{4})\z/' => '$1-$2 $3',
            '/\A(03)(\d{4})(\d{4})\z/' => '$1-$2 $3',
            '/\A(08[2-9])(\d{3})(\d{3})\z/' => '$1-$2 $3',
            '/\A(0[2-9])(\d{3})(\d{4})\z/' => '$1-$2 $3',
        ];
        foreach ($patterns as $pattern => $replacement) {
            if (preg_match($pattern, $national) === 1) {
                return (string) preg_replace($pattern, $replacement, $national);
            }
        }

        return $stored;
    }
}
