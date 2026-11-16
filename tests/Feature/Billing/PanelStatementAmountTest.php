<?php

namespace Tests\Feature\Billing;

use App\Domain\Visit\Billing\Services\PanelStatementService;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Reading a ringgit amount from a Panel statement cell. The statement only suggests: the person still ticks each row.
 * Anything that is not clearly one amount reads as nothing, never as a different amount.
 */
class PanelStatementAmountTest extends TestCase
{
    /** @return array<string, array{0: string, 1: int|null}> */
    public static function amounts(): array
    {
        return [
            'plain' => ['25', 2500],
            'with sen' => ['25.50', 2550],
            'rm prefix' => ['RM 1,234.50', 123450],
            'comma grouping' => ['1,000.00', 100000],
            'comma sen' => ['10,00', 1000],
            'european thousands' => ['1.000,00', 100000],
            'european larger' => ['12.345.678,90', 1234567890],
            'european with rm' => ['RM 1.234,50', 123450],
            // Not clearly an amount: never guessed into another one.
            'dot thousands without sen' => ['1.000', null],
            'one decimal group' => ['1.5', 150],
            'broken grouping' => ['1.00,00', null],
            'three sen digits' => ['1.000,000', null],
            'zero' => ['0,00', null],
            'letters' => ['abc', null],
        ];
    }

    /** @dataProvider amounts */
    #[DataProvider('amounts')]
    public function test_a_statement_amount_is_read_exactly_or_not_at_all(string $text, ?int $expected): void
    {
        $method = new ReflectionMethod(PanelStatementService::class, 'amount');
        $service = (new \ReflectionClass(PanelStatementService::class))->newInstanceWithoutConstructor();

        $this->assertSame($expected, $method->invoke($service, $text));
    }
}
