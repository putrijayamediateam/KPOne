<?php

namespace App\Domain\HR\Payroll;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;

/**
 * HR-7e: the MTD details on a pay profile (tax category, children for relief, and whether the staff member or spouse is
 * disabled) are personal and include health data, so they are stored encrypted as one value and never in plain text
 * (owner decision via B.E.R.N.I.S.S, 2026-11-10, after the PDPA cross-check).
 */
final class PayProfileMtd
{
    /**
     * @param  array{category:int, children:int, disabled_self:bool, disabled_spouse:bool}|null  $details
     */
    public static function encrypt(?array $details): ?string
    {
        return $details === null ? null : Crypt::encryptString(json_encode([
            'category' => (int) $details['category'], 'children' => (int) $details['children'],
            'disabled_self' => (bool) $details['disabled_self'], 'disabled_spouse' => (bool) $details['disabled_spouse'],
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * The profile's MTD details, or null when it has none or they cannot be read (logged, never shown).
     *
     * @return array{category:int, children:int, disabled_self:bool, disabled_spouse:bool}|null
     */
    public static function read(?\stdClass $profile): ?array
    {
        $value = $profile->mtd_details_encrypted ?? null;
        if (! is_string($value)) {
            return null;
        }
        try {
            $data = json_decode(Crypt::decryptString($value), true, 4, JSON_THROW_ON_ERROR);
        } catch (DecryptException|\JsonException) {
            Log::error('HR pay profile MTD details could not be read', ['profile_id' => $profile->id ?? null]);

            return null;
        }
        if (! is_array($data) || ! in_array($data['category'] ?? null, [1, 2, 3], true) || ! is_int($data['children'] ?? null)) {
            return null;
        }

        return ['category' => $data['category'], 'children' => $data['children'],
            'disabled_self' => (bool) ($data['disabled_self'] ?? false), 'disabled_spouse' => (bool) ($data['disabled_spouse'] ?? false)];
    }
}
