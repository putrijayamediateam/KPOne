<?php

namespace Tests\Unit;

use App\Domain\HR\Payroll\Services\MtdCalculator;
use PHPUnit\Framework\TestCase;

/**
 * HR-7d MTD worked out by hand (HR-PLAN 18.4). Every figure below is synthetic: the bands, reliefs and limits are made up
 * for the test and are not LHDN's. All amounts are in sen.
 */
final class MtdCalculatorTest extends TestCase
{
    /** @return array{rounding:string, minimum_sen:int, individual_relief_sen:int, spouse_relief_sen:int, disabled_self_relief_sen:int, disabled_spouse_relief_sen:int, child_relief_sen:int, epf_relief_cap_sen:int} */
    private function schedule(string $rounding = 'up_5_sen'): array
    {
        return ['rounding' => $rounding, 'minimum_sen' => 1000, 'individual_relief_sen' => 900000, 'spouse_relief_sen' => 400000,
            'disabled_self_relief_sen' => 700000, 'disabled_spouse_relief_sen' => 600000, 'child_relief_sen' => 200000, 'epf_relief_cap_sen' => 400000];
    }

    /** @return list<array{from:int, to:?int, rate_bps:int, base_cat13:int, base_cat2:int}> */
    private function bands(): array
    {
        return [
            ['from' => 0, 'to' => 499999, 'rate_bps' => 0, 'base_cat13' => 0, 'base_cat2' => 0],
            ['from' => 500000, 'to' => 1999999, 'rate_bps' => 100, 'base_cat13' => -40000, 'base_cat2' => -80000],
            ['from' => 2000000, 'to' => 3499999, 'rate_bps' => 300, 'base_cat13' => -25000, 'base_cat2' => -65000],
            ['from' => 3500000, 'to' => null, 'rate_bps' => 800, 'base_cat13' => 60000, 'base_cat2' => 60000],
        ];
    }

    /** @return array{category:int, children:int, disabled_self:bool, disabled_spouse:bool} */
    private function person(int $category = 1, int $children = 0, bool $disabledSelf = false, bool $disabledSpouse = false): array
    {
        return ['category' => $category, 'children' => $children, 'disabled_self' => $disabledSelf, 'disabled_spouse' => $disabledSpouse];
    }

    public function test_january_category_1(): void
    {
        // n = 11. K2 = min(55,000, (400,000 − 55,000) ÷ 11 = 31,363). P = 445,000 + 468,637 × 11 − 900,000 = 4,700,007.
        // ((4,700,007 − 3,500,000) × 8% + 60,000 − 0) ÷ 12 = 13,000.05 → up to the next 5 sen: 13,005.
        $r = MtdCalculator::month($this->schedule(), $this->bands(), $this->person(), ['y' => 0, 'k' => 0, 'x' => 0, 'y1' => 500000, 'k1' => 55000], 1);
        $this->assertSame(['amount' => 13005, 'chargeable' => 4700007, 'band' => 3, 'monthsLeft' => 11], $r);
    }

    public function test_category_2_with_children_uses_spouse_relief_and_category_2_base(): void
    {
        // Reliefs 900,000 + 400,000 + 2 × 200,000 = 1,700,000, so P = 3,900,007; (400,007 × 8% + 60,000) ÷ 12 = 7,666.71 → 7,670.
        $r = MtdCalculator::month($this->schedule(), $this->bands(), $this->person(2, 2), ['y' => 0, 'k' => 0, 'x' => 0, 'y1' => 500000, 'k1' => 55000], 1);
        $this->assertSame(['amount' => 7670, 'chargeable' => 3900007, 'band' => 3, 'monthsLeft' => 11], $r);
    }

    public function test_disability_reliefs(): void
    {
        // Category 2, disabled and disabled spouse: reliefs 900,000 + 400,000 + 700,000 + 600,000 = 2,600,000; P = 3,000,007 (band 2, base −65,000).
        // (1,000,007 × 3% − 65,000) ÷ 12 = −2,916.65 → nothing.
        $r = MtdCalculator::month($this->schedule(), $this->bands(), $this->person(2, 0, true, true), ['y' => 0, 'k' => 0, 'x' => 0, 'y1' => 500000, 'k1' => 55000], 1);
        $this->assertSame(['amount' => 0, 'chargeable' => 3000007, 'band' => 2, 'monthsLeft' => 11], $r);
        // A disabled spouse counts only in category 2.
        $single = MtdCalculator::month($this->schedule(), $this->bands(), $this->person(1, 0, false, true), ['y' => 0, 'k' => 0, 'x' => 0, 'y1' => 500000, 'k1' => 55000], 1);
        $this->assertSame(4700007, $single['chargeable'] ?? null);
    }

    public function test_below_the_smallest_mtd_deducts_nothing(): void
    {
        // RM 3,500 a month: P = 2,900,007; (900,007 × 3% − 25,000) ÷ 12 = 166.68 → 170 sen, below RM 10.00, so 0.
        $r = MtdCalculator::month($this->schedule(), $this->bands(), $this->person(), ['y' => 0, 'k' => 0, 'x' => 0, 'y1' => 350000, 'k1' => 38500], 1);
        $this->assertSame(0, $r['amount'] ?? null);
        $this->assertSame(2900007, $r['chargeable'] ?? null);
    }

    public function test_december_settles_the_year_exactly_with_the_epf_limit_reached(): void
    {
        // Eleven months of 500,000 and 55,000 EPF already paid; the EPF relief limit (400,000) is used up, so K1 counts 0.
        // P = 5,500,000 − 400,000 + 500,000 − 900,000 = 4,700,000. Year tax 1,200,000 × 8% + 60,000 = 156,000; 156,000 − 143,055 paid = 12,945.
        $r = MtdCalculator::month($this->schedule(), $this->bands(), $this->person(), ['y' => 5500000, 'k' => 605000, 'x' => 143055, 'y1' => 500000, 'k1' => 55000], 12);
        $this->assertSame(['amount' => 12945, 'chargeable' => 4700000, 'band' => 3, 'monthsLeft' => 0], $r);
    }

    public function test_rounding_rules(): void
    {
        $figures = ['y' => 0, 'k' => 0, 'x' => 0, 'y1' => 500000, 'k1' => 55000];
        $this->assertSame(13000, MtdCalculator::month($this->schedule('none'), $this->bands(), $this->person(), $figures, 1)['amount'] ?? null);
        $this->assertSame(13000, MtdCalculator::month($this->schedule('nearest_sen'), $this->bands(), $this->person(), $figures, 1)['amount'] ?? null);
        $this->assertSame(13005, MtdCalculator::month($this->schedule('up_5_sen'), $this->bands(), $this->person(), $figures, 1)['amount'] ?? null);
    }

    public function test_no_band_for_the_chargeable_income_gives_null(): void
    {
        $bands = [['from' => 100000, 'to' => null, 'rate_bps' => 100, 'base_cat13' => 0, 'base_cat2' => 0]];
        $this->assertNull(MtdCalculator::month($this->schedule(), $bands, $this->person(), ['y' => 0, 'k' => 0, 'x' => 0, 'y1' => 10000, 'k1' => 0], 1));
    }
}
