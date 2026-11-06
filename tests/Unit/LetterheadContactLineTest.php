<?php

namespace Tests\Unit;

use App\Domain\Clinical\Documents\LetterheadContactLine;
use PHPUnit\Framework\TestCase;

/**
 * CAT-13 (B.E.R.N.I.S.S for the owner, 2026-11-06): the referral and attendance contact line prints phones through
 * PrintedPhone, as the drug label, invoice, receipt and Panel claim pack do, and leaves out an empty part.
 */
class LetterheadContactLineTest extends TestCase
{
    public function test_phones_print_in_the_shared_national_style(): void
    {
        $this->assertSame('03-2000 0001 / clinic@doc.test', LetterheadContactLine::format(['phone' => '+60320000001', 'email' => 'clinic@doc.test']));
        $this->assertSame('Tel: 03-2000 0001 / 012-345 6789 · clinic@doc.test', LetterheadContactLine::format(['phone' => '+60320000001', 'phone_secondary' => '+60123456789', 'email' => 'clinic@doc.test']));
        $this->assertSame('Tel: 03-2000 0001 / 011-2345 6789', LetterheadContactLine::format(['phone' => '+60320000001', 'phone_secondary' => '+601123456789', 'email' => null]));
    }

    public function test_a_second_phone_with_no_first_prints_tel_b_without_an_empty_part(): void
    {
        $this->assertSame('Tel: 012-345 6789 · clinic@doc.test', LetterheadContactLine::format(['phone' => null, 'phone_secondary' => '+60123456789', 'email' => 'clinic@doc.test']));
        $this->assertSame('Tel: 012-345 6789', LetterheadContactLine::format(['phone' => '', 'phone_secondary' => '+60123456789', 'email' => '']));
    }

    public function test_missing_parts_and_missing_keys_are_left_out(): void
    {
        $this->assertSame('', LetterheadContactLine::format([]));
        $this->assertSame('clinic@doc.test', LetterheadContactLine::format(['phone' => null, 'email' => 'clinic@doc.test']));
        $this->assertSame('03-2000 0001', LetterheadContactLine::format(['phone' => '+60320000001', 'phone_secondary' => null, 'email' => '']));
    }

    public function test_a_number_printed_phone_does_not_recognise_prints_as_stored(): void
    {
        $this->assertSame('Tel: +6563001234 / 012-345 6789', LetterheadContactLine::format(['phone' => '+6563001234', 'phone_secondary' => '+60123456789']));
    }
}
