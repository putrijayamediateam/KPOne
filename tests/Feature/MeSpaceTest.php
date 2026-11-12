<?php

namespace Tests\Feature;

use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Visit\VisitTestCase;

/** DS-11 and DS-13: the Me home shows only the signed-in person's own HR data, with no amounts, reasons or references. */
class MeSpaceTest extends VisitTestCase
{
    public function test_a_ca_sees_their_own_empty_states_and_fixed_open_rows(): void
    {
        $this->selectBranch($this->actor('ca'));

        $this->get('/spaces/me')->assertOk()->assertInertia(fn (Assert $p) => $p
            ->component('Spaces/Show')
            ->where('me.greeting.text', fn ($t) => str_starts_with((string) $t, 'Good '))
            ->where('me.ring.state', 'out')
            ->where('me.ring.shiftLabel', null)
            ->where('me.week.summary', 'Nothing clocked yet this week')
            ->where('me.tiles.leave.text', 'Not set up yet')
            ->where('me.tiles.claims.text', 'No claim is waiting.')
            ->where('me.tiles.roster.text', 'No shift planned.')
            ->where('me.tiles.attendance.text', 'Nothing clocked yet this week')
            ->where('me.credentials', null)
            ->where('me.payslip', null)
            ->where('me.primaryAction.title', 'Open attendance')
            ->where('me.links', fn ($l) => collect($l)->pluck('title')->all() === ['My roster', 'My leave', 'My claims', 'My HR details', 'My payslips'])
            ->has('questions', 3));
    }

    public function test_the_me_home_shows_the_ring_week_and_tiles_from_the_servers_facts_and_the_me_url_redirects(): void
    {
        $this->selectBranch($this->actor('ca'));

        $this->get('/spaces/me')->assertOk()->assertInertia(fn (Assert $p) => $p
            ->where('me.ring.canPress', true)
            ->where('me.ring.clockedInAt', null)
            ->where('me.ring.onTime', null)
            ->has('me.ring.serverNow')
            ->has('me.time.timezone')
            ->where('me.week.days', fn ($d) => count($d) === 5 && collect($d)->pluck('day')->all() === ['Mon', 'Tue', 'Wed', 'Thu', 'Fri']));
        $this->get('/me')->assertRedirect('/spaces/me');
    }

    public function test_the_greeting_follows_the_branch_clock_and_uses_only_the_signed_in_persons_first_name(): void
    {
        $ca = $this->actor('ca');
        $ca->forceFill(['name' => 'Aina Binti Ahmad'])->save();
        $this->selectBranch($ca);

        foreach (['2027-03-08 09:00' => 'Good morning, Aina', '2027-03-08 12:00' => 'Good afternoon, Aina', '2027-03-08 17:59' => 'Good afternoon, Aina', '2027-03-08 18:00' => 'Good evening, Aina'] as $at => $text) {
            $this->travelTo(CarbonImmutable::parse($at, $this->branch->timezone));
            $this->get('/spaces/me')->assertInertia(fn (Assert $p) => $p->where('me.greeting.text', $text));
        }
    }

    public function test_the_questions_answer_in_plain_sentences_without_repeating_the_cards(): void
    {
        $this->selectBranch($this->actor('ca'));

        $this->postJson('/spaces/me/ask', ['question' => 'me.next_day_off'])->assertOk()
            ->assertJsonPath('sentence', 'No shift is planned in the next two weeks, so there is no day off to show.')
            ->assertJsonPath('href', '/hr/roster');
        $this->postJson('/spaces/me/ask', ['question' => 'me.leave_taken'])->assertOk()
            ->assertJsonPath('sentence', 'Leave is not set up yet.');
        $this->postJson('/spaces/me/ask', ['question' => 'me.leave_requests'])->assertOk()
            ->assertJsonPath('sentence', 'You have no leave request waiting or coming up.');
    }

    public function test_the_tv_account_gets_no_me_space(): void
    {
        $this->selectBranch($this->actor('queue_display'));
        $this->get('/spaces/me')->assertForbidden();
        $this->postJson('/spaces/me/ask', ['question' => 'me.leave_taken'])->assertForbidden();
    }
}
