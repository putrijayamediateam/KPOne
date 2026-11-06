<?php

namespace Tests\Unit\Shared;

use App\Domain\Clinical\Dispensary\Labels\DrugLabelLayout;
use App\Domain\Shared\PrintedPhone;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Owner decision 2026-11-06 (Daniel's recommendation): one reading format for the clinic phone on every print. */
class PrintedPhoneTest extends TestCase
{
    /** @return array<string, array{?string, ?string}> */
    public static function numbers(): array
    {
        return [
            'KL landline, stored E.164' => ['+60321234567', '03-2123 4567'],
            'KL landline, 60 form' => ['60321234567', '03-2123 4567'],
            'KL landline, national with spaces' => ['03 2123 4567', '03-2123 4567'],
            'KL landline, already formatted' => ['03-2123 4567', '03-2123 4567'],
            'Penang landline' => ['+6041234567', '04-123 4567'],
            'Johor landline, national' => ['071234567', '07-123 4567'],
            'Sabah landline' => ['+6088123456', '088-123 456'],
            'Sarawak landline' => ['082 123 456', '082-123 456'],
            'mobile 012' => ['+60123456789', '012-345 6789'],
            'mobile 019, national' => ['0191234567', '019-123 4567'],
            'mobile 011' => ['+601123456789', '011-2345 6789'],
            'mobile 011, brackets and dashes' => ['(011) 2345-6789', '011-2345 6789'],
            'foreign number stays as stored' => ['+6589123456', '+6589123456'],
            'too short stays as stored' => ['+60312345', '+60312345'],
            'too long stays as stored' => ['+6032123456789', '+6032123456789'],
            'toll free stays as stored' => ['1-800-88-1234', '1-800-88-1234'],
            'letters stay as stored' => ['03-2123 4567 ext 2', '03-2123 4567 ext 2'],
            'surrounding spaces trimmed' => ['  +60321234567 ', '03-2123 4567'],
            'punctuation only stays as stored' => ['( ) -', '( ) -'],
            'empty is null' => ['  ', null],
            'null is null' => [null, null],
        ];
    }

    #[DataProvider('numbers')]
    public function test_it_prints_malaysian_numbers_in_national_form_and_leaves_others_as_stored(?string $stored, ?string $printed): void
    {
        $this->assertSame($printed, PrintedPhone::format($stored));
    }

    public function test_every_formatted_number_fits_the_drug_label_phone_budget(): void
    {
        foreach (self::numbers() as [$stored]) {
            $this->assertLessThanOrEqual(DrugLabelLayout::FIELD_BUDGETS['phone'], mb_strlen(PrintedPhone::format($stored) ?? ''));
        }
        // The longest national form is 13 characters, far inside the 41-character budget.
        $this->assertSame(13, mb_strlen((string) PrintedPhone::format('+601123456789')));
    }
}
