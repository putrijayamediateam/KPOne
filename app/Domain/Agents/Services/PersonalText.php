<?php

namespace App\Domain\Agents\Services;

/**
 * Best-effort check that short agent text (run steps, messages, decision reasons) does not carry an
 * identity number, email address or phone number. Defence in depth only: agent text is built from fixed
 * templates and staff are told not to enter patient details.
 */
final class PersonalText
{
    public const PATTERNS = [
        '/\b\d{6}[\s-]?\d{2}[\s-]?\d{4}\b/u',                                  // NRIC, with or without separators
        '/\d{9,}/u',                                                           // long digit runs (passport, phone, IC)
        '/[^\s@]+@[^\s@]+\.[^\s@]+/u',                                         // email address
        '/(?<!\d)(?:\+?60[\s-]?|0)\d{1,2}[\s-]?\d{3,4}[\s-]?\d{4}(?!\d)/u',     // Malaysian phone
        '/(?<![\d-])1\d-\d{3,4}[\s-]?\d{4}(?!\d)/u',                            // mobile without the leading 0
    ];

    public static function looksPersonal(string $text): bool
    {
        foreach (self::PATTERNS as $pattern) {
            if (preg_match($pattern, $text)) {
                return true;
            }
        }

        return false;
    }
}
