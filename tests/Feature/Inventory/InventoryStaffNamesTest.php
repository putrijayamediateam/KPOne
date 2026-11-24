<?php

namespace Tests\Feature\Inventory;

use App\Domain\Organisation\Inventory\Services\InventoryStaffNames;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TIDY-4 (owner, "tidy top ones"): Inventory screens name people by the BIL-2 staff-visibility rule instead of
 * "staff #ID". A name reaches only a viewer who may already see that person in the staff directory.
 */
final class InventoryStaffNamesTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_viewer_who_may_see_staff_gets_names_and_you_for_themselves(): void
    {
        $viewer = User::factory()->create(['name' => 'Synthetic Viewer']);
        $viewer->givePermissionTo('staff.view.organisation');
        $other = User::factory()->create(['organisation_id' => $viewer->organisation_id, 'name' => 'Synthetic Receiver']);

        $name = app(InventoryStaffNames::class)->resolver($viewer, [$viewer->id, $other->id, null]);

        $this->assertSame('You', $name($viewer->id));
        $this->assertSame('Synthetic Receiver', $name($other->id));
        $this->assertNull($name(null));
    }

    public function test_a_viewer_who_may_not_see_staff_reads_staff_member_and_never_the_name(): void
    {
        $viewer = User::factory()->create(['name' => 'Synthetic Viewer']);
        $other = User::factory()->create(['organisation_id' => $viewer->organisation_id, 'name' => 'Synthetic Receiver']);

        $name = app(InventoryStaffNames::class)->resolver($viewer, [$other->id]);

        $this->assertSame('Staff member', $name($other->id));
        $this->assertSame('You', $name($viewer->id));
    }
}
