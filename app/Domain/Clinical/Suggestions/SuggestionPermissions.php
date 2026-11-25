<?php

namespace App\Domain\Clinical\Suggestions;

/**
 * SUG (owner, 25 Nov 2026, "Lulus"): the clinic doctor's review of the lists behind the consultation recommendation popup
 * (diagnoses with codes now; standard phrases in SUG-1b). The Resident Doctor and the Director review; the reviewer is
 * never the person who entered or last changed the item. Drafting uses the existing settings permission. Never people or
 * access authority, and nothing is granted to the CA or the Medical Assistant.
 */
final class SuggestionPermissions
{
    public const APPROVE = 'clinical_suggestions.approve.organisation';

    /** @return array<string, list<string>> */
    public static function grants(): array
    {
        return [self::APPROVE => ['resident_doctor', 'director']];
    }
}
