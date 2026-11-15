<?php

namespace App\Domain\Clinical\Dispensary\Services;

use App\Domain\Clinical\Models\PatientAllergyRecord;
use Illuminate\Support\Str;

/**
 * DSP-1: compares a patient's active allergy records (free text) with a medicine's names.
 *
 * This is a plain text comparison, not a clinical drug-allergy check. Drug groups (DSP-1b) and other names
 * such as brand names and misspellings (DSP-1c) that catalogue editors added to a medicine are passed in as
 * extra names; anything an editor has not added (an untagged class such as penicillin and amoxicillin, a brand
 * name or a spelling not listed) is still missed, so it can miss a real allergy. The
 * screen says so and the full allergy list stays visible. A match only asks the CA to confirm.
 *
 * Rule: both sides are lower-cased and every run of non-letters/non-digits becomes one space.
 * The allergy text is also split into parts at commas, semicolons, slashes, brackets, plus signs,
 * ampersands, " - ", and the words "and", "or", "dan", "atau"; wrapper words such as "allergic to",
 * "allergy" and "alahan" are dropped from each part. A record matches when the whole
 * allergy text or any part of it (at least 3 characters) appears inside the medicine's display
 * name, snapshot name, generic name, a tagged drug group or another name, or when a medicine name (at least 3 characters) appears
 * inside the allergy text.
 *
 * DSP-14: strengths, dose forms and extra words no longer hide a match. A "core" of each side drops tokens
 * that start with a digit ("500", "500mg", "250mg5ml"), units, dose forms ("tablet", "syrup"), salt words
 * ("sodium") and, on the allergy side, wrapper, reaction, symptom and filler words ("rash", "pain", "ruam").
 * A record also matches when an allergy part's core appears inside a medicine name (as whole words when the
 * core is shorter than 5 characters, so "pen" never matches "suspension"), or when the medicine name's core
 * appears inside the allergy text's core as whole words (so "iron" never matches "environmental", and
 * "Fever Syrup" never matches "Ibuprofen - headache and fever"). So "Amoxicillin
 * 500mg rash" matches "Amoxicillin 250mg Capsule", and "Panadol 500mg tablets rash" matches "Panadol Actifast
 * 500mg". It only adds matches: nothing that matched before stops matching. A core is matched as a phrase,
 * never word by word, so a shared dose form, salt, strength or ordinary word ("Amoxicillin syrup" and every
 * syrup, "Diclofenac sodium" and "Naproxen sodium") is not a match.
 */
class DispensaryAllergyMatcher
{
    private const MINIMUM_LENGTH = 3;

    /** Words that only say "this is an allergy"; "Allergic to aspirin" and "Aspirin allergy" both mean "aspirin". */
    private const WRAPPER_WORDS = ['allergy', 'allergies', 'allergic', 'allergen', 'alahan', 'alergi', 'to', 'kepada', 'terhadap'];

    /** DSP-14: an allergy core this long may match inside a longer word ("sulfa" in "sulfasalazine"); shorter ones match whole words only. */
    private const MINIMUM_SUBSTRING_CORE_LENGTH = 5;

    /** @var array<string, true>|null */
    private static ?array $medicineDrop = null;

    /** @var array<string, true>|null */
    private static ?array $allergyDrop = null;

    /** DSP-14: strength units, dropped from both sides together with number tokens; "w" and "v" come from "w/v", "x" from "2 x". */
    private const UNIT_WORDS = ['mg', 'mcg', 'ug', 'µg', 'g', 'gm', 'kg', 'ml', 'l', 'iu', 'unit', 'units', 'percent', 'w', 'v', 'x', 'μg'];

    /** DSP-14: dose forms, dropped from both sides and never matched on their own. */
    private const FORM_WORDS = [
        'tablet', 'tablets', 'tab', 'tabs', 'capsule', 'capsules', 'cap', 'caps', 'syrup', 'syrups', 'suspension',
        'mixture', 'solution', 'liquid', 'drops', 'drop', 'cream', 'ointment', 'gel', 'lotion', 'spray', 'inhaler',
        'injection', 'injections', 'inj', 'sachet', 'sachets', 'powder', 'suppository', 'suppositories', 'patch',
        'lozenge', 'lozenges', 'oral', 'topical', 'eye', 'ear', 'nasal', 'film', 'coated', 'chewable', 'effervescent',
        'dispersible', 'retard', 'forte', 'pil', 'ubat', 'sirap', 'krim', 'titis', 'suntikan',
    ];

    /** DSP-14: salt words, dropped from both sides and never matched on their own. */
    private const SALT_WORDS = [
        'sodium', 'potassium', 'hydrochloride', 'hcl', 'phosphate', 'sulphate', 'sulfate', 'maleate', 'citrate',
        'acetate', 'succinate', 'tartrate', 'besylate', 'mesylate', 'fumarate', 'bromide', 'chloride', 'trihydrate',
        'monohydrate', 'dihydrate',
    ];

    /** DSP-14: reaction and filler words in allergy text, dropped from the allergy side only. */
    private const ALLERGY_FILLER_WORDS = [
        'rash', 'rashes', 'itch', 'itchy', 'itching', 'hives', 'swelling', 'swollen', 'swell', 'reaction', 'reactions',
        'anaphylaxis', 'anaphylactic', 'shock', 'vomiting', 'vomit', 'nausea', 'dizzy', 'dizziness', 'breathless',
        'breathing', 'breath', 'difficulty', 'wheeze', 'wheezing', 'face', 'facial', 'lips', 'eyes', 'skin', 'body',
        'whole', 'severe', 'mild', 'moderate', 'very', 'with', 'after', 'when', 'from', 'causes', 'caused', 'cause',
        'gives', 'gave', 'have', 'has', 'had', 'been', 'history', 'unknown', 'type', 'drug', 'drugs', 'medicine',
        'medicines', 'medication', 'patient', 'reports', 'reported', 'says', 'ruam', 'gatal', 'bengkak', 'sesak',
        'nafas', 'muntah', 'loya', 'pening', 'muka', 'bibir', 'mata', 'kulit', 'badan', 'seluruh', 'teruk', 'sangat',
        'dengan', 'selepas', 'apabila', 'bila', 'makan', 'pernah', 'sejarah', 'tidak', 'tahu', 'ada', 'pesakit',
        'pain', 'fever', 'headache', 'stomach', 'throat', 'tongue', 'mouth', 'lip', 'diarrhoea', 'diarrhea', 'urticaria',
        'angioedema', 'redness', 'itchiness', 'palpitation', 'palpitations', 'faint', 'fainted', 'also', 'maybe',
        'suspected', 'possible', 'since', 'years', 'child', 'sakit', 'demam', 'perut', 'kepala', 'tekak', 'cirit',
    ];

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
        $nameCores = array_map(fn (string $name): string => $this->core($name, self::medicineDrop()), $names);
        $matched = [];
        foreach ($records as $record) {
            if ($record->status !== PatientAllergyRecord::STATUS_ACTIVE) {
                continue;
            }
            if ($this->recordMatches((string) $record->allergen_text, $names, $nameCores)) {
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

    /**
     * @param  list<string>  $names
     * @param  list<string>  $nameCores
     */
    private function recordMatches(string $allergen, array $names, array $nameCores): bool
    {
        $whole = $this->normalise($allergen);
        if ($whole === '') {
            return false;
        }
        $parts = preg_split('/[,;\/()\[\]+&]|\s-\s|\b(?:and|or|dan|atau)\b/iu', $allergen) ?: [];
        $normalisedParts = array_map(fn (string $part): string => $this->normalise($part), $parts);
        $needles = array_filter(array_unique([$whole, ...$normalisedParts, ...array_map(fn (string $part): string => $this->withoutWrapperWords($part), [$whole, ...$normalisedParts])]), fn (string $needle): bool => mb_strlen($needle) >= self::MINIMUM_LENGTH);
        $wholeCore = $this->core($whole, self::allergyDrop());
        $cores = array_filter(array_unique(array_map(fn (string $part): string => $this->core($part, self::allergyDrop()), $normalisedParts)), fn (string $core): bool => mb_strlen($core) >= self::MINIMUM_LENGTH);
        foreach ($names as $index => $name) {
            foreach ($needles as $needle) {
                if ($this->containsPhrase($name, $needle)) {
                    return true;
                }
            }
            if (mb_strlen($name) >= self::MINIMUM_LENGTH && $this->containsPhrase($whole, $name)) {
                return true;
            }
            foreach ($cores as $core) {
                if (mb_strlen($core) >= self::MINIMUM_SUBSTRING_CORE_LENGTH ? $this->containsPhrase($name, $core) : $this->containsWords($name, $core)) {
                    return true;
                }
            }
            $nameCore = $nameCores[$index];
            if (mb_strlen($nameCore) >= self::MINIMUM_LENGTH && $this->containsWords($wholeCore, $nameCore)) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, true> */
    private static function medicineDrop(): array
    {
        return self::$medicineDrop ??= array_fill_keys([...self::UNIT_WORDS, ...self::FORM_WORDS, ...self::SALT_WORDS], true);
    }

    /** @return array<string, true> */
    private static function allergyDrop(): array
    {
        return self::$allergyDrop ??= array_fill_keys([...self::UNIT_WORDS, ...self::FORM_WORDS, ...self::SALT_WORDS, ...self::WRAPPER_WORDS, ...self::ALLERGY_FILLER_WORDS], true);
    }

    /**
     * DSP-14: the text without dropped words and without words that start with a digit
     * ("amoxicillin 250mg capsule" → "amoxicillin").
     *
     * @param  array<string, true>  $dropped
     */
    private function core(string $normalised, array $dropped): string
    {
        return implode(' ', array_filter(explode(' ', $normalised), fn (string $word): bool => $word !== '' && ! isset($dropped[$word]) && preg_match('/^\p{N}/u', $word) !== 1));
    }

    /** DSP-14: the needle appears in the haystack as whole words. */
    private function containsWords(string $haystack, string $needle): bool
    {
        return str_contains(' '.$haystack.' ', ' '.$needle.' ');
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
