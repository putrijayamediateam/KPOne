<?php

namespace Tests\Unit;

use App\Domain\Organisation\Inventory\Support\Gs1ElementString;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** DSP-18: the shared GS1 reader (Dispensary scan, and Inventory receiving in STK-26). */
final class Gs1ElementStringTest extends TestCase
{
    private const GS = "\x1D";

    private const GTIN = '09556123000010';

    private static function day(int $year, int $month = 11, int $day = 11): CarbonImmutable
    {
        return CarbonImmutable::create($year, $month, $day, 0, 0, 0, 'UTC') ?? throw new \RuntimeException('date');
    }

    /** @return array{gtin: string, batch: string|null, batch_unconfirmed: string|null, expiry: string|null, complete: bool} */
    private static function read(?string $batch, ?string $expiry, bool $complete = true, ?string $unconfirmed = null): array
    {
        return ['gtin' => self::GTIN, 'batch' => $batch, 'batch_unconfirmed' => $unconfirmed, 'expiry' => $expiry, 'complete' => $complete];
    }

    public function test_reads_the_bracketed_form_and_ignores_spaces_a_label_printer_adds(): void
    {
        foreach (['(01)09556123000010(17)271031(10)AB12', '(01) 09556123000010 (17) 271031 (10) AB12 '] as $code) {
            $this->assertSame(self::read('AB12', '2027-10-31'), Gs1ElementString::parse($code, self::day(2026)), $code);
        }
    }

    public function test_reads_a_raw_element_string_with_c1_d2_and_other_prefixes_and_gs_separators(): void
    {
        foreach (['', ']C1', ']d2', ']e0', ']Q3', self::GS] as $prefix) {
            $code = $prefix.'01'.self::GTIN.'10AB-12/x'.self::GS.'17271031'.'21SERIAL9';
            $this->assertSame(self::read('AB-12/x', '2027-10-31'), Gs1ElementString::parse($code, self::day(2026)), "prefix {$prefix}");
        }
    }

    public function test_a_missing_gs_after_a_variable_field_leaves_the_batch_empty(): void
    {
        // The scanner dropped the GS: the batch and the serial run together, so no batch is given, only its unconfirmed text.
        $this->assertSame(
            self::read(null, null, false, 'AB1221SERIAL9'),
            Gs1ElementString::parse('01'.self::GTIN.'10AB12'.'21SERIAL9', self::day(2026)),
        );
        // A batch last in a code with no GS at all cannot be told apart from that case either.
        $this->assertSame(
            self::read(null, '2027-10-31', false, 'AB12'),
            Gs1ElementString::parse('01'.self::GTIN.'17271031'.'10AB12', self::day(2026)),
        );
        // With a GS anywhere in the code the scanner keeps separators, so a batch ending the code is known.
        $this->assertSame(self::read('AB12', null), Gs1ElementString::parse('01'.self::GTIN.'21S9'.self::GS.'10AB12', self::day(2026)));
    }

    public function test_dd_00_is_the_last_day_of_that_month_including_february_in_a_leap_year(): void
    {
        $this->assertSame('2028-02-29', Gs1ElementString::parse('(01)'.self::GTIN.'(17)280200', self::day(2026))['expiry'] ?? null);
        $this->assertSame('2027-02-28', Gs1ElementString::parse('(01)'.self::GTIN.'(17)270200', self::day(2026))['expiry'] ?? null);
        $this->assertSame('2027-04-30', Gs1ElementString::parse('(01)'.self::GTIN.'(17)270400', self::day(2026))['expiry'] ?? null);
    }

    public function test_the_century_follows_the_gs1_rule_within_minus_49_and_plus_50_years(): void
    {
        // In 2026, YY 76 is +50 years (2076) and YY 77 would be +51, so it is 1977; YY 77 is -49 years from 2026.
        $this->assertSame('2076-01-31', Gs1ElementString::parse('(01)'.self::GTIN.'(17)760131', self::day(2026))['expiry'] ?? null);
        $this->assertSame('1977-01-31', Gs1ElementString::parse('(01)'.self::GTIN.'(17)770131', self::day(2026))['expiry'] ?? null);
        // In 2075, YY 25 is +50 years (2125) and YY 24 is -51, so it moves to 2124.
        $this->assertSame('2125-01-31', Gs1ElementString::parse('(01)'.self::GTIN.'(17)250131', self::day(2075))['expiry'] ?? null);
        $this->assertSame('2124-01-31', Gs1ElementString::parse('(01)'.self::GTIN.'(17)240131', self::day(2075))['expiry'] ?? null);
        $this->assertSame('2026-12-31', Gs1ElementString::parse('(01)'.self::GTIN.'(17)261231', self::day(2026, 12, 31))['expiry'] ?? null);
    }

    public function test_an_impossible_expiry_gives_no_expiry_but_keeps_the_gtin(): void
    {
        foreach (['271331', '270431', '270231'] as $value) {
            $this->assertSame(self::read(null, null, false), Gs1ElementString::parse('(01)'.self::GTIN.'(17)'.$value, self::day(2026)), $value);
        }
    }

    /** @return iterable<string, array{string}> */
    public static function notGs1(): iterable
    {
        yield 'EAN-13' => ['9556123000010'];
        yield 'GTIN-14 alone, not an element string' => [self::GTIN];
        yield 'internal SKU code' => ['PARA-500'];
        yield 'bad check digit' => ['(01)09556123000017(10)AB12'];
        yield 'raw, bad check digit' => ['0109556123000017'.'10AB12'];
        yield 'batch and expiry, no GTIN' => ['(10)AB12(17)271031'];
        yield 'raw, no GTIN' => ['10AB12'.self::GS.'17271031'];
        yield 'bracketed text around the fields' => ['x(01)'.self::GTIN];
        yield 'empty' => [''];
    }

    #[DataProvider('notGs1')]
    public function test_codes_without_a_valid_gtin_are_not_gs1_reads(string $code): void
    {
        $this->assertNull(Gs1ElementString::parse($code, self::day(2026)));
    }

    public function test_an_unknown_or_bad_field_is_skipped_when_bracketed_and_stops_a_raw_read_and_is_not_complete(): void
    {
        // Bracketed fields are delimited, so an unknown AI (a weight, 3103) is stepped over and the batch is still read.
        $this->assertSame(self::read('AB12', '2027-10-31', false), Gs1ElementString::parse('(01)'.self::GTIN.'(17)271031(3103)000500(10)AB12', self::day(2026)));
        $this->assertSame(self::read(null, '2027-10-31', false), Gs1ElementString::parse('01'.self::GTIN.'17271031'.'99X', self::day(2026)));
        $this->assertSame(self::read(null, null, false), Gs1ElementString::parse('(01)'.self::GTIN.'(10)'.str_repeat('A', 21), self::day(2026)));
        // A batch with brackets cannot be split safely: not a GS1 read, so the plain lookup takes it.
        $this->assertNull(Gs1ElementString::parse('(01)'.self::GTIN.'(10)A(B)', self::day(2026)));
        $this->assertSame(self::read(null, null), Gs1ElementString::parse('01'.self::GTIN, self::day(2026)));
    }

    public function test_barcode_forms_cover_the_stored_gtin_14_13_12_and_8_forms_in_that_order(): void
    {
        $this->assertSame([self::GTIN, '9556123000010'], Gs1ElementString::barcodeForms(self::GTIN));
        $this->assertSame(['00012345678905', '0012345678905', '012345678905'], Gs1ElementString::barcodeForms('00012345678905'));
        $this->assertSame(['00000012345670', '0000012345670', '000012345670', '12345670'], Gs1ElementString::barcodeForms('00000012345670'));
    }
}
