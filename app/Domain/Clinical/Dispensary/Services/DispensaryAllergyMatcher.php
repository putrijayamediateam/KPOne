<?php

namespace App\Domain\Clinical\Dispensary\Services;

use App\Domain\Clinical\Models\PatientAllergyRecord;
use Illuminate\Support\Str;

/**
 * DSP-1: compares a patient's active allergy records (free text) with a medicine's names.
 *
 * This is a plain text comparison, not a clinical drug-allergy check: it cannot know drug classes
 * (penicillin and amoxicillin), brand names or misspellings, so it can miss a real allergy. The
 * screen says so and the full allergy list stays visible. A match only asks the CA to confirm.
 *
 * Rule: both sides are lower-cased and every run of non-letters/non-digits becomes one space.
 * The allergy text is also split into parts at commas, semicolons, slashes, brackets, plus signs,
 * ampersands, " - ", and the words "and", "or", "dan", "atau"; wrapper words such as "allergic to",
 * "allergy" and "alahan" are dropped from each part. A record matches when the whole
 * allergy text or any part of it (at least 3 characters) appears inside the medicine's display
 * name, snapshot name or generic name, or when a medicine name (at least 3 characters) appears
 * inside the allergy text.
 */
class DispensaryAllergyMatcher
{
    private const MINIMUM_LENGTH = 3;

    /** Words that only say "this is an allergy"; "Allergic to aspirin" and "Aspirin allergy" both mean "aspirin". */
    private const WRAPPER_WORDS = ['allergy', 'allergies', 'allergic', 'allergen', 'alahan', 'alergi', 'to', 'kepada', 'terhadap'];

    /**
     * @param  iterable<PatientAllergyRecord>  $records
     * @param  array<int, string|null>  $medicineNames
     * @return list<PatientAllergyRecord>
     */
    public function matches(iterable $records, array $medicineNames): array
    {
        $names = array_values(array_unique(array_filter(array_map(fn (?string $name): string => $this->normalise($name), $medicineNames), fn (string $name): bool => $name !== '')));
        if ($names === []) {
            return [];
        }
        $matched = [];
        foreach ($records as $record) {
            if ($record->status !== PatientAllergyRecord::STATUS_ACTIVE) {
                continue;
            }
            if ($this->recordMatches((string) $record->allergen_text, $names)) {
                $matched[] = $record;
            }
        }

        return $matched;
    }

    public function normalise(?string $text): string
    {
        $lower = Str::lower(trim((string) $text));

        return trim((string) preg_replace('/[^\p{L}\p{M}\p{N}]+/u', ' ', $lower));
    }

    /** @param list<string> $names */
    private function recordMatches(string $allergen, array $names): bool
    {
        $whole = $this->normalise($allergen);
        if ($whole === '') {
            return false;
        }
        $parts = preg_split('/[,;\/()\[\]+&]|\s-\s|\b(?:and|or|dan|atau)\b/iu', $allergen) ?: [];
        $normalisedParts = array_map(fn (string $part): string => $this->normalise($part), $parts);
        $needles = array_filter(array_unique([$whole, ...$normalisedParts, ...array_map(fn (string $part): string => $this->withoutWrapperWords($part), [$whole, ...$normalisedParts])]), fn (string $needle): bool => mb_strlen($needle) >= self::MINIMUM_LENGTH);
        foreach ($names as $name) {
            foreach ($needles as $needle) {
                if ($this->containsPhrase($name, $needle)) {
                    return true;
                }
            }
            if (mb_strlen($name) >= self::MINIMUM_LENGTH && $this->containsPhrase($whole, $name)) {
                return true;
            }
        }

        return false;
    }

    private function withoutWrapperWords(string $normalised): string
    {
        return implode(' ', array_filter(explode(' ', $normalised), fn (string $word): bool => ! in_array($word, self::WRAPPER_WORDS, true)));
    }

    /** Substring on purpose: "penicillin" also matches "benzylpenicillin"; a false alarm is safer than a miss. */
    private function containsPhrase(string $haystack, string $needle): bool
    {
        return str_contains($haystack, $needle);
    }
}
