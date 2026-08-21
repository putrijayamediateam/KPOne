<?php

namespace App\Domain\Patient\Services;

use App\Domain\Patient\Models\Patient;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Normalizer;

class PatientIdentityService
{
    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public function normalizePatient(array $attributes): array
    {
        $fullName = $this->normalizeDisplayText((string) ($attributes['full_name'] ?? ''));

        return [
            ...$attributes,
            'full_name' => $fullName,
            'search_name' => Str::lower($fullName),
            'mobile_phone' => $this->normalizePhone($attributes['mobile_phone'] ?? null),
            'email' => $this->nullableLower($attributes['email'] ?? null),
            'nationality_code' => $this->countryCode($attributes['nationality_code'] ?? null),
            'country_code' => $this->countryCode($attributes['country_code'] ?? null),
            'address_line_1' => $this->nullableText($attributes['address_line_1'] ?? null),
            'address_line_2' => $this->nullableText($attributes['address_line_2'] ?? null),
            'postcode' => $this->nullableText($attributes['postcode'] ?? null),
            'city' => $this->nullableText($attributes['city'] ?? null),
            'state' => $this->nullableText($attributes['state'] ?? null),
        ];
    }

    /**
     * @param  array<string, mixed>  $identifier
     * @return array{identifier_type: string, issuing_country_code: string, normalized_value: string}
     */
    public function normalizeIdentifier(array $identifier): array
    {
        $type = Str::lower(trim((string) ($identifier['identifier_type'] ?? '')));
        $issuer = $this->countryCode($identifier['issuing_country_code'] ?? null);
        $value = $this->unicode((string) ($identifier['value'] ?? ''));

        if (! in_array($type, ['nric', 'passport'], true)) {
            throw ValidationException::withMessages(['identifier_type' => 'Select a supported identifier type.']);
        }

        if ($type === 'nric') {
            $issuer = 'MY';
            $normalized = preg_replace('/[\s-]+/u', '', $value) ?? '';

            if (preg_match('/\A[0-9]{12}\z/', $normalized) !== 1) {
                throw ValidationException::withMessages(['value' => 'Enter a valid 12-digit Malaysian NRIC.']);
            }
        } else {
            if ($issuer === null) {
                throw ValidationException::withMessages(['issuing_country_code' => 'Passport issuing country is required.']);
            }

            $normalized = Str::upper(preg_replace('/\s+/u', '', trim($value)) ?? '');

            if (preg_match('/\A[A-Z0-9][A-Z0-9-]{2,31}\z/', $normalized) !== 1) {
                throw ValidationException::withMessages(['value' => 'Enter a valid passport number.']);
            }
        }

        return [
            'identifier_type' => $type,
            'issuing_country_code' => $issuer,
            'normalized_value' => $normalized,
        ];
    }

    public function displayIdentifier(string $type, string $normalized): string
    {
        if ($type === 'nric' && preg_match('/\A([0-9]{6})([0-9]{2})([0-9]{4})\z/', $normalized, $parts) === 1) {
            return "{$parts[1]}-{$parts[2]}-{$parts[3]}";
        }

        return $normalized;
    }

    public function maskIdentifier(string $type, string $normalized): string
    {
        $last = Str::substr($normalized, -4);

        return $type === 'nric' ? "******-**-{$last}" : '••••'.$last;
    }

    public function maskPhone(?string $phone): ?string
    {
        if ($phone === null) {
            return null;
        }

        return '••••'.Str::substr($phone, -4);
    }

    public function normalizeSearchPhone(string $value): string
    {
        return $this->normalizePhone($value) ?? '';
    }

    public function normalizeSearchName(string $value): string
    {
        return $this->normalizeDisplayText($value);
    }

    /**
     * @param  array<string, mixed>  $normalized
     * @return Collection<int, Patient>
     */
    public function possibleDuplicates(int $organisationId, array $normalized, ?Patient $except = null): Collection
    {
        $name = (string) ($normalized['search_name'] ?? '');
        $dob = $normalized['date_of_birth'] ?? null;
        $phone = $normalized['mobile_phone'] ?? null;

        if ($name === '' || ($dob === null && $phone === null)) {
            return new Collection;
        }

        return Patient::query()
            ->where('organisation_id', $organisationId)
            ->when($except, fn ($query) => $query->whereKeyNot($except->getKey()))
            ->where(function ($query) use ($name, $dob, $phone): void {
                if ($dob !== null) {
                    $query->orWhere(fn ($match) => $match
                        ->where('search_name', $name)
                        ->whereDate('date_of_birth', $dob));
                }

                if ($phone !== null) {
                    $query->orWhere(fn ($match) => $match
                        ->where('search_name', $name)
                        ->where('mobile_phone', $phone));

                    if ($dob !== null) {
                        $query->orWhere(fn ($match) => $match
                            ->whereDate('date_of_birth', $dob)
                            ->where('mobile_phone', $phone));
                    }
                }
            })
            ->limit(5)
            ->get();
    }

    private function normalizePhone(mixed $value): ?string
    {
        $value = $this->nullableText($value);

        if ($value === null) {
            return null;
        }

        $compact = preg_replace('/[\s().-]+/u', '', $value) ?? '';

        if (str_starts_with($compact, '0')) {
            $compact = '+60'.substr($compact, 1);
        } elseif (str_starts_with($compact, '60')) {
            $compact = '+'.$compact;
        }

        if (preg_match('/\A\+[1-9][0-9]{7,14}\z/', $compact) !== 1) {
            throw ValidationException::withMessages(['mobile_phone' => 'Enter a valid international or Malaysian mobile number.']);
        }

        return $compact;
    }

    private function countryCode(mixed $value): ?string
    {
        $value = $this->nullableText($value);

        if ($value === null) {
            return null;
        }

        $value = Str::upper($value);

        if (preg_match('/\A[A-Z]{2}\z/', $value) !== 1) {
            throw ValidationException::withMessages(['country_code' => 'Use a two-letter country code.']);
        }

        return $value;
    }

    private function nullableLower(mixed $value): ?string
    {
        $value = $this->nullableText($value);

        return $value === null ? null : Str::lower($value);
    }

    private function nullableText(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = $this->normalizeDisplayText($value);

        return $value === '' ? null : $value;
    }

    private function normalizeDisplayText(string $value): string
    {
        return trim(preg_replace('/\s+/u', ' ', $this->unicode($value)) ?? '');
    }

    private function unicode(string $value): string
    {
        return class_exists(Normalizer::class)
            ? (Normalizer::normalize($value, Normalizer::FORM_KC) ?: $value)
            : $value;
    }
}
