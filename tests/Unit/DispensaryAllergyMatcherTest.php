<?php

namespace Tests\Unit;

use App\Domain\Clinical\Dispensary\Services\DispensaryAllergyMatcher;
use App\Domain\Clinical\Models\PatientAllergyRecord;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** DSP-1 and DSP-14: the free-text allergy matcher. Synthetic text only; no database. */
class DispensaryAllergyMatcherTest extends TestCase
{
    private function record(string $allergen, string $status = PatientAllergyRecord::STATUS_ACTIVE): PatientAllergyRecord
    {
        return (new PatientAllergyRecord)->forceFill(['allergen_text' => $allergen, 'status' => $status]);
    }

    /** @return array<string, array{string, list<string|null>}> */
    public static function matching(): array
    {
        return [
            'exact name' => ['Paracetamol', ['Paracetamol', null]],
            'name inside the medicine name' => ['Paracetamol', ['Paracetamol 500mg Tablet', null]],
            'case insensitive' => ['PARACETAMOL', ['paracetamol 500mg', null]],
            'surrounding and inner whitespace' => ["  synthetic   OTC\tmedicine  ", ['Synthetic OTC medicine', null]],
            'punctuation is ignored' => ['Co-amoxiclav', ['Co amoxiclav 625mg', null]],
            'generic name' => ['Amoxicillin', ['Synthetic brand capsule', 'Amoxicillin']],
            'one part of a list' => ['Seafood, penicillin; latex', ['Penicillin V 250mg', null]],
            'one part joined by and' => ['Seafood and ibuprofen', ['Ibuprofen 200mg', null]],
            'one part joined by dan' => ['Udang dan aspirin', ['Aspirin 100mg', null]],
            'reaction in brackets' => ['Diclofenac (rash)', ['Diclofenac sodium 50mg', null]],
            'medicine name inside the allergy text' => ['Allergic to amoxicillin - rash', ['Capsule brand', 'Amoxicillin']],
            'substring inside a longer word' => ['Penicillin', ['Benzylpenicillin injection', null]],
            'allergic to wording' => ['Allergic to aspirin', ['Aspirin 100mg Tablet', null]],
            'allergy suffix wording' => ['Aspirin allergy', ['Aspirin 100mg Tablet', null]],
            'malay wording' => ['Alahan aspirin', ['Aspirin 100mg Tablet', null]],
            'decomposed accent kept in one word' => ["Cafe\u{0301}ine", ["Cafe\u{0301}ine citrate", null]],
        ];
    }

    /** @param list<string|null> $names */
    #[DataProvider('matching')]
    public function test_a_matching_allergy_is_found(string $allergen, array $names): void
    {
        $record = $this->record($allergen);

        $this->assertSame([$record], (new DispensaryAllergyMatcher)->matches([$record], $names));
    }

    /** @return array<string, array{string, list<string|null>}> */
    public static function notMatching(): array
    {
        return [
            'different medicine' => ['Seafood', ['Paracetamol 500mg', null]],
            'drug class is not known (documented miss)' => ['Penicillin', ['Amoxicillin 250mg', 'Amoxicillin']],
            'misspelling is not known (documented miss)' => ['Paracetamool', ['Paracetamol', null]],
            'parts shorter than three characters are ignored' => ['ab, xy', ['Abacavir xylometazoline', null]],
            'empty allergy text' => ['   ', ['Paracetamol', null]],
        ];
    }

    /** @param list<string|null> $names */
    #[DataProvider('notMatching')]
    public function test_a_non_matching_allergy_is_not_found(string $allergen, array $names): void
    {
        $this->assertSame([], (new DispensaryAllergyMatcher)->matches([$this->record($allergen)], $names));
    }

    /** @return array<string, array{string, list<string|null>}> */
    public static function strengthAndExtraWords(): array
    {
        return [
            'allergy with a strength and a reaction, medicine with another strength' => ['Amoxicillin 500mg rash', ['Amoxicillin 250mg Capsule', null]],
            'allergy with a dose form, no generic name' => ['Aspirin 100mg tablet, swelling', ['Aspirin 300mg', null]],
            'brand with extra words in the medicine name' => ['Panadol 500mg tablets rash', ['Panadol Actifast 500mg', null]],
            'allergic to wording with a strength and a reaction' => ['Allergic to ibuprofen 400mg causes swelling', ['Ibuprofen 200mg Tablet', null]],
            'malay wording with a strength and a reaction' => ['Alahan amoxicillin 250mg ruam gatal', ['Amoxicillin 500mg Capsule', null]],
            'strength written with a volume' => ['Paracetamol 120mg/5ml syrup rash', ['Paracetamol 250mg5ml Suspension', null]],
            'salt in the medicine name' => ['Diclofenac 75mg injection hives', ['Diclofenac Sodium 50mg Tablet', null]],
            'multi-word core inside the allergy text' => ['Co amoxiclav 625mg gives rash', ['Co-Amoxiclav 375mg Tablet', null]],
        ];
    }

    /** @param list<string|null> $names */
    #[DataProvider('strengthAndExtraWords')]
    public function test_dsp_14_strengths_forms_and_extra_words_do_not_hide_a_match(string $allergen, array $names): void
    {
        $record = $this->record($allergen);

        $this->assertSame([$record], (new DispensaryAllergyMatcher)->matches([$record], $names));
    }

    /** @return array<string, array{string, list<string|null>}> */
    public static function strengthAndExtraWordsNotMatching(): array
    {
        return [
            'a shared dose form is not a match' => ['Amoxicillin syrup rash', ['Paracetamol Syrup 120mg/5ml', null]],
            'a shared salt is not a match' => ['Diclofenac sodium 50mg rash', ['Naproxen Sodium 275mg', null]],
            'a shared strength is not a match' => ['Seafood 500mg', ['Paracetamol 500mg Tablet', null]],
            'reaction and form words alone are not a match' => ['Rash after injection', ['Ceftriaxone 1g Injection', null]],
            'malay reaction words alone are not a match' => ['Ruam gatal teruk', ['Cetirizine 10mg Tablet', null]],
            'a short word is not compared on its own' => ['Egg yolk', ['Eggplant extract', null]],
            'drug class is still not known (documented miss)' => ['Penicillin 500mg rash', ['Amoxicillin 250mg Capsule', 'Amoxicillin']],
        ];
    }

    /** @param list<string|null> $names */
    #[DataProvider('strengthAndExtraWordsNotMatching')]
    public function test_dsp_14_shared_forms_salts_strengths_and_reactions_do_not_match(string $allergen, array $names): void
    {
        $this->assertSame([], (new DispensaryAllergyMatcher)->matches([$this->record($allergen)], $names));
    }

    public function test_entered_in_error_records_are_ignored(): void
    {
        $active = $this->record('Ibuprofen');
        $error = $this->record('Ibuprofen', PatientAllergyRecord::STATUS_ENTERED_IN_ERROR);

        $this->assertSame([$active], (new DispensaryAllergyMatcher)->matches([$error, $active], ['Ibuprofen 400mg']));
        $this->assertSame([], (new DispensaryAllergyMatcher)->matches([$error], ['Ibuprofen 400mg']));
    }

    public function test_no_medicine_name_means_no_match(): void
    {
        $this->assertSame([], (new DispensaryAllergyMatcher)->matches([$this->record('Ibuprofen')], [null, '', '  ']));
    }

    public function test_every_matching_record_is_returned_in_order(): void
    {
        $first = $this->record('Ibuprofen');
        $other = $this->record('Seafood');
        $second = $this->record('Ibuprofen (NSAID)');

        $this->assertSame([$first, $second], (new DispensaryAllergyMatcher)->matches([$first, $other, $second], ['Ibuprofen 200mg']));
    }
}
