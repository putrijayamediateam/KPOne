<?php

namespace Tests\Unit\Billing;

use App\Domain\Visit\Billing\Services\PanelStatementService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * PNL-18: which statement invoice numbers count as a near match. Only differences that cannot change the number
 * count, because KPOne invoice numbers run in sequence and a wrong digit may be another real invoice.
 */
class PanelStatementNearInvoiceTest extends TestCase
{
    /** @return array<string, array{string, string, string|null}> */
    public static function pairs(): array
    {
        return [
            'spaces and capitals' => ['inv 000012', 'INV-000012', 'format'],
            'other punctuation' => ['INV/000012.', 'INV-000012', 'format'],
            'leading zeros dropped' => ['INV-12', 'INV-000012', 'format'],
            'letter O for zero' => ['INV-0O0012', 'INV-000012', 'lookalike'],
            'letters I and S' => ['INV-00OOI5', 'INV-000015', 'lookalike'],
            'lower-case l for one' => ['inv-00001l', 'INV-000011', 'lookalike'],
            'a digit typed wrongly' => ['INV-000013', 'INV-000012', null],
            'two digits swapped' => ['INV-000021', 'INV-000012', null],
            'a digit missing' => ['INV-00001', 'INV-000012', null],
            'a different prefix' => ['ABC-000012', 'INV-000012', null],
            'nothing to compare' => ['---', 'INV-000012', null],
            'zero is not dropped entirely' => ['INV-0', 'INV-', null],
            // KPOne's real format, KPI-%08d.
            'real format, spaces and capitals' => ['kpi 00000012', 'KPI-00000012', 'format'],
            'real format, leading zeros dropped' => ['KPI-12', 'KPI-00000012', 'format'],
            'real format, letter O for zero' => ['KPI-0OOOOO12', 'KPI-00000012', 'lookalike'],
            'real format, lower-case l in the prefix' => ['KPl-12', 'KPI-00000012', 'lookalike'],
            'a suffix is never glued on' => ['KPI-00000012-1', 'KPI-00000121', null],
            'a slash suffix is never glued on' => ['KPI-00000012/2', 'KPI-00000122', null],
            'a bracketed suffix is never glued on' => ['KPI 00000012 (3)', 'KPI-00000123', null],
            'split digits are never joined' => ['KPI-1-2', 'KPI-00000012', null],
            'a suffix is not the same invoice' => ['KPI-00000012-1', 'KPI-00000012', null],
        ];
    }

    #[DataProvider('pairs')]
    public function test_near_invoice(string $statement, string $claim, ?string $expected): void
    {
        $this->assertSame($expected, PanelStatementService::nearInvoice($statement, $claim));
    }
}
