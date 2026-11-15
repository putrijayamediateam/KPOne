<?php

namespace App\Domain\Agents\Tools;

use LogicException;

/**
 * Refuses any data source result that names or carries personal or clinical data (contract principle 4).
 * Keys are split into words (camelCase, acronyms and snake_case; plural endings are also tried) and checked
 * against a word list and a few substrings; every string value is checked for identity numbers, email
 * addresses and phone numbers (public ids, which are UUIDs, are skipped), because some values come from staff-typed text (location names).
 */
final class AggregatePayloadGuard
{
    public const FORBIDDEN_WORDS = [
        'name', 'patient', 'ic', 'nric', 'mykad', 'passport', 'phone', 'mobile', 'email', 'address',
        'diagnosis', 'diagnose', 'note', 'guardian', 'birth', 'dob', 'contact', 'password', 'token', 'secret',
        'user', 'username', 'staff', 'clinical', 'allergy', 'allergie', 'prescription', 'kp', 'mrn', 'number', 'surname',
    ];

    public const FORBIDDEN_SUBSTRINGS = ['name', 'nric', 'mykad', 'passport', 'phone', 'email', 'patient'];

    private const VALUE_PATTERNS = [
        '/\b\d{6}[\s-]?\d{2}[\s-]?\d{4}\b/u',
        '/\d{9,}/u',
        '/[^\s@]+@[^\s@]+\.[^\s@]+/u',
        '/(?<!\d)(?:\+?60[\s-]?|0)\d{1,2}[\s-]?\d{3,4}[\s-]?\d{4}(?!\d)/u',
        '/(?<![\d-])1\d-\d{3,4}[\s-]?\d{4}(?!\d)/u',
    ];

    /** @param array<array-key, mixed> $payload */
    public function assertAggregate(array $payload, string $path = ''): void
    {
        foreach ($payload as $key => $value) {
            if (is_string($key) && $this->forbiddenKey($key)) {
                throw new LogicException("Agent data source result has a forbidden key [{$path}{$key}].");
            }
            if (is_string($value) && ! preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value)) {
                foreach (self::VALUE_PATTERNS as $pattern) {
                    if (preg_match($pattern, $value)) {
                        throw new LogicException("Agent data source result has personal-looking text at [{$path}{$key}].");
                    }
                }
            }
            if (is_array($value)) {
                $this->assertAggregate($value, $path.$key.'.');
            }
        }
    }

    public function forbiddenKey(string $key): bool
    {
        $lower = strtolower($key);
        foreach (self::FORBIDDEN_SUBSTRINGS as $part) {
            if (str_contains($lower, $part)) {
                return true;
            }
        }

        foreach ($this->words($key) as $word) {
            foreach ([$word, preg_replace('/s$/', '', $word), preg_replace('/es$/', '', $word)] as $candidate) {
                if (in_array($candidate, self::FORBIDDEN_WORDS, true)) {
                    return true;
                }
            }
        }

        return false;
    }

    /** @return list<string> */
    public function words(string $key): array
    {
        $split = preg_replace(['/([A-Z]+)([A-Z][a-z])/', '/([a-z0-9])([A-Z])/'], ['$1_$2', '$1_$2'], $key) ?? $key;
        $words = array_filter(explode('_', strtolower(preg_replace('/[^A-Za-z0-9]+/', '_', $split) ?? $split)));

        return array_values($words);
    }
}
