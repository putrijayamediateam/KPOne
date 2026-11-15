<?php

namespace Tests\Unit\Reports;

use App\Domain\Shared\Services\ReportCsv;
use Tests\TestCase;

class DailyReportCsvTest extends TestCase
{
    public function test_header_block_uses_the_same_csv_safety_without_consuming_the_data_cap(): void
    {
        $csv = ReportCsv::encode(['Date', 'Net (MYR)'], array_fill(0, 5000, ['2026-12-31', ReportCsv::money(9007199254740993)]), [['Report', " \t=FORMULA()"], ['Basis', "Ujian—雪, \"teks\"\nBaris kedua"]]);
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $stream = fopen('php://memory', 'w+');
        fwrite($stream, substr($csv, 3));
        rewind($stream);
        $this->assertSame(['Report', "' \t=FORMULA()"], fgetcsv($stream, escape: ''));
        $this->assertSame(['Basis', "Ujian—雪, \"teks\"\nBaris kedua"], fgetcsv($stream, escape: ''));
        $this->assertSame([null], fgetcsv($stream, escape: ''));
        $this->assertSame(['Date', 'Net (MYR)'], fgetcsv($stream, escape: ''));
        $this->assertSame(['2026-12-31', '90071992547409.93'], fgetcsv($stream, escape: ''));
        fclose($stream);
    }
}
