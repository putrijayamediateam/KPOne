<?php

namespace Tests\Unit;

use App\Domain\DepartmentSpace\SpaceDisplay;
use PHPUnit\Framework\TestCase;

/** DS-6: waits over 24 hours read as days and hours, with the words kept. */
class SpaceDisplayDurationTest extends TestCase
{
    public function test_waits_read_as_minutes_then_days_and_hours_and_keep_their_words(): void
    {
        $this->assertSame('Waiting 12 min', SpaceDisplay::waitText(12));
        $this->assertSame('Waiting 45 min, Long wait', SpaceDisplay::waitText(45));
        $this->assertSame('Waiting 1439 min, Very long wait', SpaceDisplay::waitText(1439));
        $this->assertSame('Waiting 1 day, Very long wait', SpaceDisplay::waitText(1440));
        $this->assertSame('Waiting 6 days 5 h, Very long wait', SpaceDisplay::waitText(6 * 1440 + 5 * 60 + 20));
    }
}
