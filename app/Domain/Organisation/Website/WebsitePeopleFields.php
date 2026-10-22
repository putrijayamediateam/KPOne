<?php

namespace App\Domain\Organisation\Website;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class WebsitePeopleFields
{
    public const TYPES = ['doctor_profile', 'career_posting'];

    public const DOCTOR_TEXT = ['display_name' => 150, 'professional_title' => 100, 'biography' => 1500, 'public_registration_text' => 80, 'registration_display_reference' => 200, 'profile_consent_reference' => 200];

    public const CAREER_TEXT = ['title' => 150, 'summary' => 600, 'location_note' => 150, 'apply_url' => 500, 'apply_email' => 254];

    public const DOCTOR_LISTS = ['qualifications' => [8, 150], 'areas_of_practice' => [8, 80], 'languages_spoken' => [6, 40]];

    public const CAREER_LISTS = ['responsibilities' => [10, 200], 'requirements' => [10, 200]];

    public const EMPLOYMENT = ['full_time', 'part_time', 'contract', 'internship', 'locum'];

    /** @return array<string,mixed> */
    public static function empty(string $type): array
    {
        $doctor = $type === 'doctor_profile';

        return [...array_fill_keys(array_keys($doctor ? self::DOCTOR_TEXT : self::CAREER_TEXT), ''), ...array_fill_keys(array_keys($doctor ? self::DOCTOR_LISTS : self::CAREER_LISTS), []), ...($doctor ? ['include_on_website' => false] : ['employment_type' => 'full_time', 'closing_date' => '', 'open' => false]), 'sort_order' => 0];
    }

    /** @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public static function validate(string $type, array $input): array
    {
        abort_unless(in_array($type, self::TYPES, true), 404);
        $doctor = $type === 'doctor_profile';
        if (array_diff(array_keys($input), array_keys(self::empty($type))) !== []) {
            throw ValidationException::withMessages(['content' => 'Unknown content field.']);
        }
        $out = self::empty($type);
        foreach ($doctor ? self::DOCTOR_TEXT : self::CAREER_TEXT as $field => $limit) {
            $out[$field] = self::text($input[$field] ?? '', 'content.'.$field, $limit);
        }
        foreach ($doctor ? self::DOCTOR_LISTS : self::CAREER_LISTS as $field => [$count, $limit]) {
            $values = $input[$field] ?? [];
            if (! is_array($values) || ! array_is_list($values) || count($values) > $count) {
                throw ValidationException::withMessages(['content.'.$field => "Use up to {$count} entries."]);
            }
            $out[$field] = array_map(fn ($value) => self::text($value, 'content.'.$field, $limit), $values);
        }
        $flag = $doctor ? 'include_on_website' : 'open';
        $extra = Validator::make($input, ['sort_order' => ['required', 'integer', 'between:-100000,100000'], $flag => ['required', 'boolean'], ...($doctor ? [] : ['employment_type' => ['required', Rule::in(self::EMPLOYMENT)], 'closing_date' => ['nullable', 'date_format:Y-m-d']])])->validate();
        $out['sort_order'] = (int) $extra['sort_order'];
        $out[$flag] = filter_var($extra[$flag], FILTER_VALIDATE_BOOLEAN);
        if ($doctor) {
            if ($out['public_registration_text'] !== '' && $out['registration_display_reference'] === '') {
                throw ValidationException::withMessages(['content.registration_display_reference' => 'Record who approved displaying this registration text.']);
            }
        } else {
            $out['employment_type'] = $extra['employment_type'];
            $out['closing_date'] = $extra['closing_date'] ?? '';
            if (($out['apply_url'] === '') === ($out['apply_email'] === '')) {
                throw ValidationException::withMessages(['content.apply_url' => 'Provide exactly one HTTPS link or public HR email.']);
            }
            if ($out['apply_url'] !== '') {
                $parts = parse_url($out['apply_url']);
                if (! filter_var($out['apply_url'], FILTER_VALIDATE_URL) || ! is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || isset($parts['user']) || isset($parts['pass'])) {
                    throw ValidationException::withMessages(['content.apply_url' => 'Use an HTTPS link without credentials.']);
                }
            }
            if ($out['apply_email'] !== '' && ! filter_var($out['apply_email'], FILTER_VALIDATE_EMAIL)) {
                throw ValidationException::withMessages(['content.apply_email' => 'Use a valid public HR email.']);
            }
        }

        return $out;
    }

    private static function text(mixed $value, string $field, int $limit): string
    {
        $value = WebsiteProfileFields::cleanText($value, $field, $limit);
        if (preg_match('/(?:\*\*|__|`|!\[|\[[^\]]+\]\(|^\s{0,3}#{1,6}\s)/u', $value)) {
            throw ValidationException::withMessages([$field => 'Use plain text without markup.']);
        }

        return $value;
    }

    /** @param array<string,mixed> $content
     * @return list<string>
     */
    public static function missing(string $type, array $content): array
    {
        $fields = $type === 'doctor_profile' ? ['display_name', 'professional_title', 'qualifications', 'areas_of_practice', 'languages_spoken', 'biography', 'profile_consent_reference'] : ['title', 'summary', 'responsibilities', 'requirements', 'location_note'];

        return array_values(array_filter($fields, fn ($field) => empty($content[$field]) || (is_array($content[$field]) && count(array_filter($content[$field], fn ($value) => $value !== '')) === 0)));
    }
}
