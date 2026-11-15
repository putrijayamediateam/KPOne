<?php

namespace Tests\Unit\Reports;

use App\Domain\Shared\Services\ReportCsv;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ReportCsvTest extends TestCase
{
    public function test_formula_neutralisation_and_numeric_precision(): void
    {
        foreach (['=SUM(A1)', '+CMD()', '-CMD()', '@SUM(A1)', " \t=1+1", "\n=1+1", "\u{FEFF}=1+1"] as $text) {
            $this->assertSame("'".$text, ReportCsv::safeCell($text));
        }
        foreach (['-12.50', '0.00', '123.456', 'Normal text'] as $text) {
            $this->assertSame($text, ReportCsv::safeCell($text));
        }
        $this->assertSame('90071992547409.93', ReportCsv::money(9007199254740993));
        $this->assertSame('-0.01', ReportCsv::money(-1));
        $this->assertSame('-12.50', ReportCsv::money(-1250));
    }

    public function test_csv_round_trips_unicode_quotes_commas_and_newlines_and_caps_without_partial_output(): void
    {
        $value = "Ujian, \"teks\"\nBaris kedua";
        $csv = ReportCsv::encode(['Metric', 'Value'], [[$value, '12.30']]);
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $stream = fopen('php://memory', 'w+');
        fwrite($stream, substr($csv, 3));
        rewind($stream);
        $this->assertSame(['Metric', 'Value'], fgetcsv($stream, escape: ''));
        $this->assertSame([$value, '12.30'], fgetcsv($stream, escape: ''));
        fclose($stream);
        $this->assertStringContainsString('Metric', ReportCsv::encode(['Metric'], []));
        $this->assertNotEmpty(ReportCsv::encode(['Metric'], array_fill(0, ReportCsv::MAX_ROWS, ['1'])));
        $this->expectException(ValidationException::class);
        ReportCsv::encode(['Metric'], array_fill(0, ReportCsv::MAX_ROWS + 1, ['1']));
    }
}
