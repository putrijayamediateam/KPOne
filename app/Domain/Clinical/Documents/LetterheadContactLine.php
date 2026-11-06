<?php

namespace App\Domain\Clinical\Documents;

use App\Domain\Shared\PrintedPhone;

/**
 * The phone and email line under the clinic address on referral and attendance copies (CAT-13, B.E.R.N.I.S.S for
 * the owner, 2026-11-06: one phone style on every print, Daniel). Phones print through PrintedPhone; with a second
 * phone the line reads "Tel: A / B · email", and an empty part is left out ("Tel: B"). Without a second phone it
 * keeps the older "phone / email" shape. Display only, used when a new copy is rendered: issued copies keep the HTML
 * stored at issue.
 */
final class LetterheadContactLine
{
    /** @param  array<string, mixed>  $details */
    public static function format(array $details): string
    {
        $phone = PrintedPhone::format(self::text($details['phone'] ?? null));
        $secondary = PrintedPhone::format(self::text($details['phone_secondary'] ?? null));
        $email = self::text($details['email'] ?? null);
        $email = $email === null || trim($email) === '' ? null : $email;

        if ($secondary === null) {
            return implode(' / ', array_filter([$phone, $email], fn (?string $part): bool => $part !== null));
        }

        $phones = implode(' / ', array_filter([$phone, $secondary], fn (?string $part): bool => $part !== null));

        return 'Tel: '.$phones.($email !== null ? ' · '.$email : '');
    }

    private static function text(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }
}
