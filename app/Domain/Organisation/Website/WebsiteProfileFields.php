<?php

namespace App\Domain\Organisation\Website;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class WebsiteProfileFields
{
    public const LIMITS = ['display_name' => 200, 'address_line_1' => 255, 'address_line_2' => 255, 'postcode' => 20, 'city' => 100, 'state' => 100, 'public_phone' => 40, 'whatsapp_number' => 128, 'public_email' => 254, 'map_url' => 500, 'hours_note' => 300, 'parking_notes' => 500, 'accessibility_notes' => 500];

    public const DAYS = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];

    /** @return array<string, mixed> */
    public static function empty(): array
    {
        return [...array_fill_keys(array_keys(self::LIMITS), ''), 'opening_hours' => array_fill_keys(self::DAYS, ['closed' => false, 'open' => '', 'close' => '']), 'contact_blocks' => []];
    }

    public static function cleanText(mixed $value, string $field, int $limit): string
    {
        if (! is_string($value) || ! mb_check_encoding($value, 'UTF-8') || mb_strlen($value) > $limit || preg_match('/[\p{Cc}\p{Cf}\p{Zl}\p{Zp}]/u', $value) || preg_match('/<[^>]*>/u', $value)) {
            throw ValidationException::withMessages([$field => "Use plain text, at most {$limit} characters, without markup or invisible/control characters."]);
        }

        return trim($value);
    }

    public static function whatsapp(string $value): string
    {
        if ($value === '') {
            return '';
        }
        if (! preg_match('/^[+0-9 ().\p{Zs}-]+$/u', $value) || str_contains($value, '..')) {
            self::invalid('whatsapp_number', 'Enter one complete mobile number; masked numbers and landlines are not supported.');
        }
        $compact = preg_replace('/[ ().\p{Zs}-]/u', '', $value) ?? '';
        $plus = str_starts_with($compact, '+');
        $digits = $plus ? substr($compact, 1) : $compact;
        if (! $plus && preg_match('/^01[0-9]{7,8}$/D', $digits)) {
            return '6'.$digits;
        }
        if (str_starts_with($digits, '60')) {
            if (preg_match('/^601[0-9]{7,8}$/D', $digits)) {
                return $digits;
            }
        } elseif ($plus && preg_match('/^[1-9][0-9]{7,14}$/D', $digits)) {
            return $digits;
        }
        self::invalid('whatsapp_number', 'Enter a 9–10 digit local Malaysian mobile or a complete international + number.');
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public static function validate(array $input): array
    {
        $unknown = array_diff(array_keys($input), [...array_keys(self::LIMITS), 'opening_hours', 'contact_blocks']);
        if ($unknown !== []) {
            self::invalid('content', 'Unknown profile fields are not allowed.');
        }
        $data = self::empty();
        foreach (self::LIMITS as $key => $limit) {
            $data[$key] = self::cleanText($input[$key] ?? '', "content.{$key}", $limit);
        }
        $data['whatsapp_number'] = self::whatsapp($data['whatsapp_number']);
        if ($data['public_email'] !== '' && ! filter_var($data['public_email'], FILTER_VALIDATE_EMAIL)) {
            self::invalid('content.public_email', 'Enter a valid public email.');
        }
        if ($data['public_phone'] !== '' && ! preg_match('/^\+?[0-9 ()\p{Zs}.-]{6,40}$/u', $data['public_phone'])) {
            self::invalid('content.public_phone', 'Enter a public contact phone number.');
        }
        if ($data['map_url'] !== '') {
            $url = parse_url($data['map_url']);
            if (! filter_var($data['map_url'], FILTER_VALIDATE_URL) || ! $url || ($url['scheme'] ?? '') !== 'https' || empty($url['host']) || isset($url['user']) || isset($url['pass'])) {
                self::invalid('content.map_url', 'Use an HTTPS URL without embedded credentials.');
            }
        }
        $hours = $input['opening_hours'] ?? $data['opening_hours'];
        Validator::make(['hours' => $hours], ['hours' => ['required', 'array:'.implode(',', self::DAYS)], ...array_fill_keys(array_map(fn ($d) => 'hours.'.$d, self::DAYS), ['required', 'array:closed,open,close'])])->validate();
        foreach (self::DAYS as $day) {
            $row = $hours[$day];
            if (! isset($row['closed']) || ! is_bool($row['closed'])) {
                self::invalid('content.opening_hours', 'Every weekday needs a closed flag.');
            }
            $open = self::cleanText($row['open'] ?? '', "content.opening_hours.{$day}.open", 5);
            $close = self::cleanText($row['close'] ?? '', "content.opening_hours.{$day}.close", 5);
            if ($row['closed'] && ($open !== '' || $close !== '')) {
                self::invalid('content.opening_hours', 'Closed days must have no times.');
            }
            if (! $row['closed'] && ($open !== '' || $close !== '')) {
                if (! preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/D', $open) || ! preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/D', $close) || $open >= $close) {
                    self::invalid('content.opening_hours', 'Use HH:MM and a closing time after opening. Overnight hours are not supported in this draft slice.');
                }
            }
            $data['opening_hours'][$day] = ['closed' => $row['closed'], 'open' => $open, 'close' => $close];
        }
        $blocks = $input['contact_blocks'] ?? [];
        if (! is_array($blocks) || ! array_is_list($blocks) || count($blocks) > 3) {
            self::invalid('content.contact_blocks', 'Use at most three contact blocks.');
        }
        foreach ($blocks as $block) {
            if (! is_array($block) || array_diff(array_keys($block), ['label', 'text']) !== []) {
                self::invalid('content.contact_blocks', 'Use a label and plain text.');
            }
            $data['contact_blocks'][] = ['label' => self::cleanText($block['label'] ?? '', 'content.contact_blocks', 60), 'text' => self::cleanText($block['text'] ?? '', 'content.contact_blocks', 300)];
        }

        return $data;
    }

    /**
     * @param  array<string,mixed>  $content
     * @return list<string>
     */
    public static function missing(array $content): array
    {
        $missing = [];
        foreach (['display_name', 'address_line_1', 'postcode', 'city', 'state', 'public_phone'] as $field) {
            if (trim((string) ($content[$field] ?? '')) === '') {
                $missing[] = $field;
            }
        }
        foreach (self::DAYS as $day) {
            $row = $content['opening_hours'][$day] ?? [];
            if (! ($row['closed'] ?? false) && (empty($row['open']) || empty($row['close']))) {
                $missing[] = 'opening_hours.'.$day;
            }
        }

        return $missing;
    }

    private static function invalid(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
