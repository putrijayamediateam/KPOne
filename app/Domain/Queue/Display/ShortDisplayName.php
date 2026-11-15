<?php

namespace App\Domain\Queue\Display;

/**
 * Q-3 (owner decisions 2026-11-05): the gentler TV name. The first two words of the registered name, skipping
 * relationship words (bin, binti, bt, bte, a/l, a/p, s/o, d/o) and the "@" alias sign, matched in any case and
 * with or without a trailing dot. Words that are only punctuation are skipped; a hyphenated word is one word;
 * the registered casing is kept. An attached "@" and trailing commas, semicolons or colons are dropped. When the two words together are longer than 24 characters, only the first word
 * is used. A word is never cut. Returns null when no usable word remains, so the TV falls back to the number.
 */
final class ShortDisplayName
{
    public const MAX_TWO_WORD_LENGTH = 24;

    private const SKIPPED = ['bin', 'binti', 'bt', 'bte', 'a/l', 'a/p', 's/o', 'd/o', '@'];

    public static function from(?string $fullName): ?string
    {
        $words = [];
        foreach (preg_split('/\s+/u', trim((string) $fullName)) ?: [] as $word) {
            // An attached alias sign ("@Abu") and trailing commas or semicolons ("Tan,") are not shown.
            $word = rtrim(ltrim($word, '@'), ',;:');
            if ($word === '' || ! preg_match('/[\p{L}\p{N}]/u', $word)) {
                continue;
            }
            // Trailing punctuation ("bin," "bt.") does not hide a relationship word.
            if (in_array(mb_strtolower(rtrim($word, '.')), self::SKIPPED, true)) {
                continue;
            }
            $words[] = $word;
            if (count($words) === 2) {
                break;
            }
        }
        if ($words === []) {
            return null;
        }
        $two = implode(' ', $words);

        return mb_strlen($two) > self::MAX_TWO_WORD_LENGTH ? $words[0] : $two;
    }
}
