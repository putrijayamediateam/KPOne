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

    private static function today(): CarbonImmutable
    {
        return CarbonImmutable::create(2026, 11, 11, 0, 0, 0, 'UTC') ?? throw new \RuntimeException('date');
    }

    public function test_reads_the_bracketed_form_printed_under_a_barcode(): void
    {
        $this->assertSame(
            ['gtin' => '09556123000010', 'batch' => 'AB12', 'batch_certain' => true, 'expiry' => '2027-10-31'],
            Gs1ElementString::parse('(01)09556123000010(17)271031(10)AB12', self::today()),
        );
    }

    public function test_reads_a_raw_element_string_with_a_symbology_prefix_and_gs_separators(): void
    {
        foreach (['', ']d2', ']C1', ']e0', ']Q3', self::GS] as $prefix) {
            $code = $prefix.'0109556123000010'.'10AB-12/x'.self::GS.'17271031'.'21SERIAL9';
            $this->assertSame(
                ['gtin' => '09556123000010', 'batch' => 'AB-12/x', 'batch_certain' => true, 'expiry' => '2027-10-31'],
                Gs1ElementString::parse($code, self::today()),
                "prefix {$prefix}",
            );
        }
    }

    public function test_a_batch_at_the_end_of_a_raw_code_without_any_gs_is_read_but_not_certain(): void
    {
        $read = Gs1ElementString::parse('010955612300001017271031'.'10AB12', self::today());

        $this->assertSame('AB12', $read['batch'] ?? null);
        $this->assertFalse($read['batch_certain'] ?? true);
        $this->assertSame('2027-10-31', $read['expiry'] ?? null);

        // A scanner that dropped the GS joins the batch and the serial: the reader cannot tell, so it is not certain.
        $joined = Gs1ElementString::parse('0109556123000010'.'10AB12'.'21SERIAL9', self::today());
        $this->assertSame('AB1221SERIAL9', $joined['batch'] ?? null);
        $this->assertFalse($joined['batch_certain'] ?? true);
        $this->assertNull($joined['expiry'] ?? null);

        // With a GS anywhere in the code the scanner keeps separators, so a batch ending the code is certain.
        $this->assertTrue(Gs1ElementString::parse('0109556123000010'.'21S9'.self::GS.'10AB12', self::today())['batch_certain'] ?? false);
    }

    public function test_dd_00_is_the_last_day_of_that_month(): void
    {
        $this->assertSame('2028-02-29', Gs1ElementString::parse('(01)09556123000010(17)280200', self::today())['expiry'] ?? null);
        $this->assertSame('2027-04-30', Gs1ElementString::parse('(01)09556123000010(17)270400', self::today())['expiry'] ?? null);
    }

    public function test_an_impossible_expiry_is_left_out_and_the_century_follows_the_gs1_window(): void
    {
        $this->assertSame('09556123000010', Gs1ElementString::parse('(01)09556123000010(17)271331', self::today())['gtin'] ?? null);
        $this->assertNull(Gs1ElementString::parse('(01)09556123000010(17)271331', self::today())['expiry'] ?? null);
        $this->assertNull(Gs1ElementString::parse('(01)09556123000010(17)270231', self::today())['expiry'] ?? null);
        $this->assertSame('2075-01-31', Gs1ElementString::parse('(01)09556123000010(17)750131', self::today())['expiry'] ?? null);
        $this->assertSame('1977-01-31', Gs1ElementString::parse('(01)09556123000010(17)770131', self::today())['expiry'] ?? null);
    }

    /** @return iterable<string, array{string}> */
    public static function notGs1(): iterable
    {
        yield 'EAN-13' => ['9556123000010'];
        yield 'GTIN-14 alone, not an element string' => ['09556123000010'];
        yield 'internal SKU code' => ['PARA-500'];
        yield 'bad check digit' => ['(01)09556123000017(10)AB12'];
        yield 'raw, bad check digit' => ['0109556123000017'.'10AB12'];
        yield 'no GTIN' => ['(10)AB12(17)271031'];
        yield 'bracketed text around the fields' => ['x(01)09556123000010'];
        yield 'empty' => [''];
    }

    #[DataProvider('notGs1')]
    public function test_codes_that_are_not_gs1_element_strings_return_null(string $code): void
    {
        $this->assertNull(Gs1ElementString::parse($code, self::today()));
    }

    public function test_unknown_or_bad_fields_stop_the_read_and_keep_what_came_before(): void
    {
        $read = Gs1ElementString::parse('(01)09556123000010(17)271031(99)X(10)AB12', self::today());
        $this->assertSame(['gtin' => '09556123000010', 'batch' => null, 'batch_certain' => false, 'expiry' => '2027-10-31'], $read);

        $tooLong = Gs1ElementString::parse('(01)09556123000010(10)'.str_repeat('A', 21), self::today());
        $this->assertSame(['gtin' => '09556123000010', 'batch' => null, 'batch_certain' => false, 'expiry' => null], $tooLong);

        $this->assertSame(['gtin' => '09556123000010', 'batch' => null, 'batch_certain' => false, 'expiry' => null], Gs1ElementString::parse('0109556123000010', self::today()));
    }

    public function test_barcode_forms_cover_the_stored_ean_upc_and_ean8_forms(): void
    {
        $this->assertSame(['09556123000010', '9556123000010'], Gs1ElementString::barcodeForms('09556123000010'));
        $this->assertSame(['00012345678905', '0012345678905', '012345678905'], Gs1ElementString::barcodeForms('00012345678905'));
        $this->assertSame(['00000012345670', '0000012345670', '000012345670', '12345670'], Gs1ElementString::barcodeForms('00000012345670'));
    }
}
