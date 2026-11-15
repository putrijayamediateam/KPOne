<?php

namespace App\Domain\Organisation\Inventory\Support;

use Carbon\CarbonImmutable;

/**
 * DSP-18 (shared with Inventory's STK-26): reads a GS1 element string from a scanned code. Read only: it never creates a
 * batch or fills a field; a page may only point at what it read. Plain barcodes (EAN-13, UPC, an internal code) are not
 * GS1 element strings and return null, so the STK-9 lookup for them is unchanged.
 *
 * Accepted forms:
 *  - bracketed, as printed under a barcode: (01)09556123000017(17)271031(10)AB12 (spaces, which some label printers
 *    add, are ignored);
 *  - raw, as a scanner sends it: an optional symbology prefix (]C1, ]d2, ]e0, ]Q3), then the element string, with the
 *    GS character (U+001D) ending a variable-length field (FNC1).
 *
 * Read AIs: 01 and 02 (GTIN, check digit verified), 10 (batch), 17 (expiry), and 11, 13, 15, 20 and 21, which are
 * stepped over. Without a valid GTIN the code is not a GS1 read. In the raw form an unknown AI or a bad value stops the
 * read and keeps what came before it; in the bracketed form, where every field is delimited, it is skipped. A bracketed
 * value containing "(" or ")" (allowed by GS1 but rare) cannot be split safely, so such a code is not a GS1 read and
 * falls back to the plain lookup.
 *
 * `batch` is set only when its end is known: in the bracketed form, or when a GS or the end of a code that carries a GS
 * ends it. In a raw code without any GS, a batch that runs to the end may have swallowed a following field (many keyboard
 * scanners drop GS), so it is left empty and its text is given as `batch_unconfirmed`: a page may compare it for an exact
 * match but must never fill it in or guess from it.
 *
 * `expiry` is AI 17 as Y-m-d: DD = 00 means the last day of that month, and the century is chosen by the GS1 rule
 * (General Specifications 7.12) against $today, which the caller sets to the branch-local date. An impossible date gives
 * no expiry; the GTIN still stands.
 *
 * `complete` is true only when every AI was understood and ended cleanly: nothing stopped the read, no value was
 * refused (including an impossible expiry) and the batch end was known.
 */
final class Gs1ElementString
{
    private const GS = "\x1D";

    /** Keyed by AI; PHP stores numeric AIs as integer keys, and lookups by string still work. @var array<int|string, int> */
    private const FIXED = ['01' => 14, '02' => 14, '11' => 6, '13' => 6, '15' => 6, '17' => 6, '20' => 2];

    /** Keyed by AI; PHP stores numeric AIs as integer keys, and lookups by string still work. @var array<int|string, int> */
    private const VARIABLE = ['10' => 20, '21' => 20];

    /** Named keys, because PHP turns numeric string keys such as '10' into integers. @var array<int|string, string> */
    private const NAMES = ['01' => 'gtin', '02' => 'gtin', '10' => 'batch', '11' => 'production', '13' => 'packaging', '15' => 'best_before',
        '17' => 'expiry', '20' => 'variant', '21' => 'serial'];

    /**
     * @return array{gtin: string, batch: string|null, batch_unconfirmed: string|null, expiry: string|null, complete: bool}|null
     */
    public static function parse(string $code, ?CarbonImmutable $today = null): ?array
    {
        $code = trim($code, " \t\n\r\0\x0B");
        if ($code === '' || mb_strlen($code) > 100) {
            return null;
        }

        $fields = str_starts_with($code, '(') ? self::bracketed((string) preg_replace('/\s+/', '', $code)) : self::raw($code);
        $gtin = $fields['gtin'] ?? null;
        if (! is_string($gtin)) {
            return null;
        }
        $batch = $fields['batch'] ?? null;
        $batch = is_string($batch) ? $batch : null;
        $certain = $batch !== null && ($fields['batch_certain'] ?? false) === true;
        $rawExpiry = $fields['expiry'] ?? null;
        $expiry = is_string($rawExpiry) ? self::expiry($rawExpiry, $today ?? CarbonImmutable::now('UTC')) : null;

        return [
            'gtin' => $gtin,
            'batch' => $certain ? $batch : null,
            'batch_unconfirmed' => $certain ? null : $batch,
            'expiry' => $expiry,
            'complete' => ($fields['complete'] ?? false) === true && ($batch === null || $certain) && ($rawExpiry === null || $expiry !== null),
        ];
    }

    /**
     * Barcode forms a stored SKU barcode may use for this GTIN: GTIN-14, GTIN-13 (EAN), GTIN-12 (UPC-A) and GTIN-8.
     *
     * @return list<string>
     */
    public static function barcodeForms(string $gtin): array
    {
        $forms = [$gtin];
        foreach ([13, 12, 8] as $length) {
            $padding = substr($gtin, 0, 14 - $length);
            if (trim($padding, '0') === '') {
                $forms[] = substr($gtin, 14 - $length);
            }
        }

        return $forms;
    }

    /**
     * @return array<string, string|bool>|null
     */
    private static function bracketed(string $code): ?array
    {
        if (preg_match_all('/\((\d{2,4})\)([^()]*)/', $code, $matches, PREG_SET_ORDER) === 0) {
            return null;
        }
        if (implode('', array_column($matches, 0)) !== $code) {
            return null;
        }

        /** @var array<string, string|bool> $fields */
        $fields = ['complete' => true];
        // Every bracketed field is delimited, so an unknown or bad field is skipped (and the read is not complete).
        foreach ($matches as [, $ai, $value]) {
            if (! self::accept($fields, $ai, $value)) {
                $fields['complete'] = false;
            }
        }
        if (isset($fields['batch'])) {
            $fields['batch_certain'] = true;
        }

        return $fields;
    }

    /**
     * @return array<string, string|bool>|null
     */
    private static function raw(string $code): ?array
    {
        if (preg_match('/^\][A-Za-z]\d/', $code) === 1) {
            $code = substr($code, 3);
        }
        $code = ltrim($code, self::GS);
        $hasSeparator = str_contains($code, self::GS);

        // A raw element string starts with a GTIN; anything else is a plain code for the STK-9 lookup.
        if (preg_match('/^0[12]\d{14}/', $code) !== 1 || strlen($code) <= 16 && ! $hasSeparator) {
            return strlen($code) === 16 && preg_match('/^0[12]\d{14}$/', $code) === 1 ? self::fieldsFromGtinOnly($code) : null;
        }

        /** @var array<string, string|bool> $fields */
        $fields = ['complete' => true];
        $at = 0;
        $length = strlen($code);
        while ($at < $length) {
            if ($code[$at] === self::GS) {
                $at++;

                continue;
            }
            $ai = substr($code, $at, 2);
            if (isset(self::FIXED[$ai])) {
                $value = substr($code, $at + 2, self::FIXED[$ai]);
                if (strlen($value) !== self::FIXED[$ai] || ! self::accept($fields, $ai, $value)) {
                    $fields['complete'] = false;
                    break;
                }
                $at += 2 + self::FIXED[$ai];

                continue;
            }
            if (! isset(self::VARIABLE[$ai])) {
                $fields['complete'] = false;
                break;
            }
            $end = strpos($code, self::GS, $at + 2);
            $value = substr($code, $at + 2, ($end === false ? $length : $end) - $at - 2);
            if (! self::accept($fields, $ai, $value)) {
                $fields['complete'] = false;
                break;
            }
            if ($ai === '10') {
                $fields['batch_certain'] = $end !== false || $hasSeparator;
            }
            $at = $end === false ? $length : $end + 1;
        }

        return $fields;
    }

    /**
     * @return array<string, string|bool>|null
     */
    private static function fieldsFromGtinOnly(string $code): ?array
    {
        /** @var array<string, string|bool> $fields */
        $fields = ['complete' => true];

        return self::accept($fields, substr($code, 0, 2), substr($code, 2)) ? $fields : null;
    }

    /**
     * @param  array<string, string|bool>  $fields
     */
    private static function accept(array &$fields, string $ai, string $value): bool
    {
        $name = self::NAMES[$ai] ?? null;
        if ($name === null || isset($fields[$name])) {
            return false;
        }

        if (isset(self::FIXED[$ai])) {
            if (strlen($value) !== self::FIXED[$ai] || ! ctype_digit($value)) {
                return false;
            }
            if ($ai === '01' || $ai === '02') {
                if (! self::validCheckDigit($value)) {
                    return false;
                }
            }
        } elseif (isset(self::VARIABLE[$ai])) {
            // GS1 AI 82 character set; anything else (or an over-long value) means the read went wrong.
            if ($value === '' || strlen($value) > self::VARIABLE[$ai] || preg_match('/^[!"%&\'()*+,\-.\/0-9:;<=>?A-Z_a-z]+$/', $value) !== 1) {
                return false;
            }
        } else {
            return false;
        }

        $fields[$name] = $value;

        return true;
    }

    private static function validCheckDigit(string $digits): bool
    {
        $sum = 0;
        $body = substr($digits, 0, -1);
        $weight = 3;
        for ($i = strlen($body) - 1; $i >= 0; $i--) {
            $sum += (int) $body[$i] * $weight;
            $weight = $weight === 3 ? 1 : 3;
        }

        return (10 - $sum % 10) % 10 === (int) substr($digits, -1);
    }

    /** AI 17 YYMMDD; DD = 00 means the last day of that month, and the century follows the GS1 sliding window. */
    private static function expiry(string $value, CarbonImmutable $today): ?string
    {
        $yy = (int) substr($value, 0, 2);
        $month = (int) substr($value, 2, 2);
        $day = (int) substr($value, 4, 2);
        if ($month < 1 || $month > 12) {
            return null;
        }

        $currentYear = $today->year;
        $century = intdiv($currentYear, 100) * 100;
        $difference = $yy - $currentYear % 100;
        if ($difference >= 51) {
            $century -= 100;
        } elseif ($difference <= -50) {
            $century += 100;
        }
        $year = $century + $yy;

        $first = CarbonImmutable::create($year, $month, 1, 0, 0, 0, 'UTC');
        if ($first === null) {
            return null;
        }
        if ($day === 0) {
            return $first->endOfMonth()->toDateString();
        }
        if ($day > $first->daysInMonth) {
            return null;
        }

        return $first->setDay($day)->toDateString();
    }
}
