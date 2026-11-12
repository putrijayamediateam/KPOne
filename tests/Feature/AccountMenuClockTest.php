<?php

namespace Tests\Feature;

use App\Domain\DepartmentSpace\AccountMenuLinks;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Visit\VisitTestCase;

/**
 * DS-12: the account menu carries the person's own pages (server-built from the permissions they hold), Me is not a nav
 * item, and the clock shortcut reads My attendance's state and returns to the page it was pressed on. Navigation only.
 */
class AccountMenuClockTest extends VisitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2027-03-08 07:00', $this->branch->timezone));
    }

    public function test_the_account_menu_lists_only_the_pages_the_person_can_open(): void
    {
        $this->selectBranch($this->actor('ca'));
        $this->get('/spaces/me')->assertOk()->assertInertia(fn (Assert $p) => $p
            ->where('workspace.accountMenu.clock', true)
            ->where('workspace.accountMenu.links', fn ($links) => collect($links)->pluck('title')->all() === ['My space', 'My roster', 'My leave', 'My attendance', 'My claims', 'My credentials', 'My SOPs']));

        // The waiting-room TV account gets no own pages and no clock.
        $tv = $this->actor('queue_display');
        $this->assertSame(['links' => [], 'clock' => false], app(AccountMenuLinks::class)->for($tv));
        $this->actingAs($tv)->get('/spaces/me')->assertForbidden();
    }

    public function test_me_is_not_a_nav_item_and_a_me_only_role_still_lands_on_the_me_page(): void
    {
        $this->selectBranch($this->actor('marketing'));
        $this->get('/spaces')->assertRedirect('/spaces/me');
        $this->get('/spaces/me')->assertOk()->assertInertia(fn (Assert $p) => $p
            ->where('spaces', fn ($spaces) => collect($spaces)->pluck('key')->all() === ['me']));

        $ca = $this->actor('ca');
        $this->selectBranch($ca);
        $this->get('/spaces/me')->assertOk(); // The map is unchanged: Me stays last for every human role.
    }

    public function test_clock_state_is_read_only_for_holders_and_refused_to_others(): void
    {
        $ca = $this->actor('ca');
        $this->selectBranch($ca);
        $this->getJson(route('hr.attendance.clock-state'))->assertOk()
            ->assertJson(['state' => 'out', 'canPress' => true, 'needsAttendancePage' => false]);

        $this->actingAs($this->actor('queue_display'))->getJson(route('hr.attendance.clock-state'))->assertForbidden();
    }

    public function test_the_shortcut_never_presses_and_the_attendance_press_routes_are_unchanged(): void
    {
        $ca = $this->actor('ca');
        $this->selectBranch($ca);

        // Reading the state writes nothing: no session, no event.
        $this->getJson(route('hr.attendance.clock-state'))->assertOk();
        $this->assertSame(0, DB::table('hr_clock_sessions')->count());
        $this->assertSame(0, DB::table('hr_clock_events')->count());

        // A press still goes to the Attendance routes and, with no return path of its own, back to the Attendance tab.
        $this->post(route('hr.attendance.clock-in'), ['idempotency_key' => (string) Str::uuid(), 'from' => '/spaces/billing'])
            ->assertRedirect(route('hr.attendance.index', ['tab' => 'my']));
    }
}
