<?php

namespace Tests\Unit\Inventory;

use App\Domain\Organisation\Inventory\Services\StockValuationReportService;
use App\Domain\Organisation\Inventory\Services\WeightedAverageCostService;
use PHPUnit\Framework\TestCase;

/** REL-STK: the cost pages say the accountant approved the policy (owner-confirmed, verbal, 25 Nov 2026). */
final class CostPolicyWordingTest extends TestCase
{
    public function test_the_disclaimer_and_banner_name_the_verbal_approval_and_stay_a_management_report(): void
    {
        $this->assertSame(
            "Weighted average cost, as approved verbally by the clinic's accountant on 25 Nov 2026; not a statutory financial statement.",
            WeightedAverageCostService::DISCLAIMER,
        );
        $this->assertSame('Management report — '.WeightedAverageCostService::DISCLAIMER, StockValuationReportService::BANNER);
        $this->assertStringNotContainsString('to be confirmed', StockValuationReportService::BANNER);
    }
}
