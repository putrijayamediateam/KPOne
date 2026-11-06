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
        ];
    }

    #[DataProvider('pairs')]
    public function test_near_invoice(string $statement, string $claim, ?string $expected): void
    {
        $this->assertSame($expected, PanelStatementService::nearInvoice($statement, $claim));
    }
}
