<?php

namespace Tests\Unit;

use App\Domain\Visit\Billing\Services\PanelClaimDueRule;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

class PanelClaimDueRuleTest extends TestCase
{
    private function rule(string $kind, ?string $time = null, ?int $offset = null, int $days = 30): object
    {
        return (object) ['claim_due_rule' => $kind, 'claim_due_time' => $time, 'claim_due_offset' => $offset, 'claim_due_days' => $days];
    }

    private function at(string $local): CarbonImmutable
    {
        return CarbonImmutable::parse($local, 'Asia/Kuala_Lumpur');
    }

    public function test_days_after_visit_counts_whole_days_from_the_visit_date(): void
    {
        $due = PanelClaimDueRule::dueAt($this->rule('days_after_visit', days: 7), $this->at('2026-10-01 15:30'));
        $this->assertSame('2026-10-08 00:00', $due->format('Y-m-d H:i'));
        $this->assertFalse(PanelClaimDueRule::isOverdue($this->rule('days_after_visit'), $due, $this->at('2026-10-08 23:59')));
        $this->assertTrue(PanelClaimDueRule::isOverdue($this->rule('days_after_visit'), $due, $this->at('2026-10-09 00:00')));
    }

    public function test_daily_rule_is_a_time_on_the_same_or_a_later_day(): void
    {
        $visit = $this->at('2026-10-01 09:00');
        $this->assertSame('2026-10-01 17:00', PanelClaimDueRule::dueAt($this->rule('daily', '17:00', 0), $visit)->format('Y-m-d H:i'));
        $this->assertSame('2026-10-04 12:30', PanelClaimDueRule::dueAt($this->rule('daily', '12:30', 3), $visit)->format('Y-m-d H:i'));
        $this->assertTrue(PanelClaimDueRule::isOverdue($this->rule('daily'), PanelClaimDueRule::dueAt($this->rule('daily', '17:00', 0), $visit), $this->at('2026-10-01 17:01')));
        $this->assertFalse(PanelClaimDueRule::isOverdue($this->rule('daily'), PanelClaimDueRule::dueAt($this->rule('daily', '17:00', 0), $visit), $this->at('2026-10-01 16:59')));
    }

    public function test_weekly_rule_picks_the_next_named_weekday_after_the_visit(): void
    {
        // 2026-10-01 is a Thursday.
        $thursday = $this->at('2026-10-01 10:00');
        $this->assertSame('2026-10-05', PanelClaimDueRule::dueAt($this->rule('weekly', '10:00', 1), $thursday)->toDateString());
        $this->assertSame('2026-10-08', PanelClaimDueRule::dueAt($this->rule('weekly', '10:00', 4), $thursday)->toDateString());
        $this->assertSame('2026-10-04', PanelClaimDueRule::dueAt($this->rule('weekly', '10:00', 7), $thursday)->toDateString());
    }

    public function test_monthly_rule_uses_a_day_of_next_month_or_the_last_day_of_the_visit_month(): void
    {
        $visit = $this->at('2026-01-31 10:00');
        $this->assertSame('2026-02-03 18:00', PanelClaimDueRule::dueAt($this->rule('monthly', '18:00', 3), $visit)->format('Y-m-d H:i'));
        $this->assertSame('2026-01-31 18:00', PanelClaimDueRule::dueAt($this->rule('monthly', '18:00', 0), $visit)->format('Y-m-d H:i'));
        $this->assertSame('2026-02-28', PanelClaimDueRule::dueAt($this->rule('monthly', '18:00', 0), $this->at('2026-02-10 10:00'))->toDateString());
        $this->assertSame('2026-11-01', PanelClaimDueRule::dueAt($this->rule('monthly', '09:00', 1), $this->at('2026-10-31 23:00'))->toDateString());
    }

    public function test_descriptions_read_in_plain_english(): void
    {
        $this->assertSame('Submit 14 days after the visit', PanelClaimDueRule::describe($this->rule('days_after_visit', days: 14)));
        $this->assertSame('Submit by 5:00 PM the day after the visit', PanelClaimDueRule::describe($this->rule('daily', '17:00', 1)));
        $this->assertSame('Submit by 9:30 AM on the next Friday after the visit', PanelClaimDueRule::describe($this->rule('weekly', '09:30', 5)));
        $this->assertSame('Submit by 6:00 PM on day 3 of the month after the visit', PanelClaimDueRule::describe($this->rule('monthly', '18:00', 3)));
        $this->assertSame('Submit by 6:00 PM on the last day of the visit month', PanelClaimDueRule::describe($this->rule('monthly', '18:00', 0)));
    }
}
