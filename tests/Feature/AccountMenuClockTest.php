<?php

namespace Tests\Feature;

use App\Domain\DepartmentSpace\AccountMenuLinks;
use App\Domain\Shared\SafeReturnPath;
use Carbon\CarbonImmutable;
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
            ->where('workspace.accountMenu.links', fn ($links) => collect($links)->pluck('title')->all() === ['Me', 'My roster', 'My leave', 'My attendance', 'My claims', 'My credentials', 'My SOPs']));

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

    public function test_a_press_from_the_top_bar_returns_to_the_page_it_came_from_and_keeps_the_attendance_default(): void
    {
        $ca = $this->actor('ca');
        $this->selectBranch($ca);

        $this->post(route('hr.attendance.clock-in'), ['idempotency_key' => (string) Str::uuid(), 'from' => '/spaces/billing?x=1'])
            ->assertRedirect('/spaces/billing?x=1');

        $this->travelTo(CarbonImmutable::parse('2027-03-08 09:00', $this->branch->timezone));
        $this->post(route('hr.attendance.clock-out'), ['idempotency_key' => (string) Str::uuid()])
            ->assertRedirect(route('hr.attendance.index', ['tab' => 'my']));
    }

    public function test_the_return_path_accepts_only_real_internal_pages(): void
    {
        $this->assertSame('/spaces/me', SafeReturnPath::from('/spaces/me'));
        $this->assertSame('/hr/attendance?tab=my', SafeReturnPath::from('/hr/attendance?tab=my'));
        foreach (['//evil.test/x', 'https://evil.test/', '/\\evil.test', 'javascript:alert(1)', '/no/such/page', '', '/spaces/me#frag', '../x', null, ['/spaces/me']] as $bad) {
            $this->assertNull(SafeReturnPath::from($bad), json_encode($bad));
        }
        $this->assertNull(SafeReturnPath::from(str_repeat('/a', 150)));
    }
}
