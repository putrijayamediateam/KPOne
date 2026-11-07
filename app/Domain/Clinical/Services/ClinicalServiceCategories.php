<?php

namespace App\Domain\Clinical\Services;

use Illuminate\Validation\ValidationException;

/**
 * CAT-11 (B.E.R.N.I.S.S for the owner, 2026-11-06): a clinical service's category is one of four fixed names, or none.
 * Billing (BIL-9) maps "Procedure(s)" and "Investigation(s)" to their own invoice rows and the other two to the
 * Service row, so the names must match exactly. Categories saved before CAT-11 are kept and stay readable; only a new
 * service, or a change of category, must use the list. PostgreSQL repeats the rule (migration 2026_11_09_000200).
 */
final class ClinicalServiceCategories
{
    /** @var list<string> */
    public const NAMES = ['Procedure(s)', 'Investigation(s)', 'Consultation-related', 'Other service'];

    public static function assertAllowed(?string $category): void
    {
        if ($category !== null && ! in_array($category, self::NAMES, true)) {
            throw ValidationException::withMessages([
                'category' => 'Choose a category from the list.',
            ]);
        }
    }
}
