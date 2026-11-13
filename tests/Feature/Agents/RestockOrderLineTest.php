<?php

namespace Tests\Feature\Agents;

use App\Domain\Agents\Proposals\ProposalInbox;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** AG-22: the inbox's restock "Suggested order" line is worded in plain English with the unit in the plural. */
class RestockOrderLineTest extends TestCase
{
    private function line(string $quantity, ?string $unit, ?string $maximum): string
    {
        $inbox = (new \ReflectionClass(ProposalInbox::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(ProposalInbox::class, 'orderLine');

        return $method->invoke($inbox, $quantity, $unit, $maximum);
    }

    /** @return array<string, array{0: string, 1: string|null, 2: string|null, 3: string}> */
    public static function lines(): array
    {
        return [
            'with a maximum' => ['22', 'tablet', '25', '22 tablets, to bring stock up to the maximum of 25'],
            'no maximum' => ['22', 'tablet', null, '22 tablets'],
            'one is singular' => ['1', 'tablet', null, '1 tablet'],
            'one with a maximum' => ['1', 'box', '5', '1 box, to bring stock up to the maximum of 5'],
            'decimals are plural' => ['0.5', 'bottle', null, '0.5 bottles'],
            'ch, sh, x and z take es' => ['3', 'box', null, '3 boxes'],
            'consonant y becomes ies' => ['3', 'supply', null, '3 supplies'],
            'vowel y just adds s' => ['3', 'tray', null, '3 trays'],
            'already plural is kept' => ['3', 'tablets', null, '3 tablets'],
            'unknown unit' => ['22', null, '25', '22, to bring stock up to the maximum of 25'],
        ];
    }

    #[DataProvider('lines')]
    public function test_the_order_line(string $quantity, ?string $unit, ?string $maximum, string $expected): void
    {
        $this->assertSame($expected, $this->line($quantity, $unit, $maximum));
    }
}
