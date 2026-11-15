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

    public function test_quantities_take_a_plural_unit_unless_exactly_one_and_dues_take_a_label(): void
    {
        $this->assertSame('1 tablet', SpaceDisplay::quantityText('1.000', 'tablet'));
        $this->assertSame('225 tablets', SpaceDisplay::quantityText('225.000', 'tablet'));
        $this->assertSame('0 capsules', SpaceDisplay::quantityText('0', 'capsule'));
        $this->assertSame('8 sachets', SpaceDisplay::quantityText('8', 'sachet'));
        $this->assertSame('2 boxes', SpaceDisplay::quantityText('2', 'box'));
        $this->assertSame('250 ml', SpaceDisplay::quantityText('250', 'ml'));
        $this->assertSame('0.5 tablets', SpaceDisplay::quantityText('0.5', 'tablet'));
        $this->assertSame('Review due 8 Nov 2026, 3 days overdue', SpaceDisplay::dueText('8 Nov 2026', 3, 'Review due'));
        $this->assertSame('Review due 1 Oct 2026, 38 days overdue (very overdue)', SpaceDisplay::dueText('1 Oct 2026', 38, 'Review due'));
    }
}
