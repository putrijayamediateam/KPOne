<?php

namespace App\Domain\Patient\Services;

use Illuminate\Validation\ValidationException;
use libphonenumber\NumberParseException;
use libphonenumber\PhoneNumberFormat;
use libphonenumber\PhoneNumberUtil;

final class PatientPhoneNormalizer
{
    public function normalize(mixed $value, mixed $country = 'MY', bool $required = false): ?string
    {
        if ($value === null || $value === '') {
            if ($required) {
                throw ValidationException::withMessages(['mobile_phone' => 'Nombor telefon diperlukan.']);
            }

            return null;
        }

        $invalid = 'Masukkan nombor telefon yang sah untuk negara yang dipilih.';
        if (! is_string($value) || strlen($value) > 64 || preg_match('/\A[ +()0-9-]+\z/D', $value) !== 1) {
            throw ValidationException::withMessages(['mobile_phone' => $invalid]);
        }

        $value = trim($value, ' ');
        if ($value === '') {
            return $this->normalize(null, $country, $required);
        }

        $phone = PhoneNumberUtil::getInstance();
        if (! is_string($country) || ! in_array($country, $phone->getSupportedRegions(), true)) {
            throw ValidationException::withMessages(['phone_country' => 'Semak kod negara dan nombor telefon.']);
        }

        $compact = str_replace([' ', '-', '(', ')'], '', $value);
        if (preg_match('/\A\+?[0-9]+\z/D', $compact) !== 1) {
            throw ValidationException::withMessages(['mobile_phone' => $invalid]);
        }

        try {
            $parsed = $phone->parse($compact, $country);
            if (! $phone->isValidNumber($parsed)) {
                throw ValidationException::withMessages(['mobile_phone' => $invalid]);
            }

            $canonical = $phone->format($parsed, PhoneNumberFormat::E164);
            // Explicit international input must not be repaired by the parser.
            if (str_starts_with($compact, '+') && $canonical !== $compact) {
                throw ValidationException::withMessages(['mobile_phone' => 'Semak kod negara dan nombor telefon.']);
            }

            return $canonical;
        } catch (NumberParseException) {
            throw ValidationException::withMessages(['mobile_phone' => $invalid]);
        }
    }
}
