<?php

namespace Tests\Feature;

use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Visit\VisitTestCase;

/** DS-11: the Me space shows only the signed-in person's own HR data, with no amounts, reasons or references. */
class MeSpaceTest extends VisitTestCase
{
    public function test_a_ca_sees_their_own_empty_states_and_fixed_open_rows(): void
    {
        $this->selectBranch($this->actor('ca'));

        $this->get('/spaces/me')->assertOk()->assertInertia(fn (Assert $p) => $p
            ->component('Spaces/Show')
            ->where('me.shift.text', 'No shift planned.')
            ->where('me.clock.text', 'Not clocked in')
            ->where('me.leave', ['notSetUp' => true])
            ->where('me.claims.text', 'No claim is waiting.')
            ->where('me.credentials', null)
            ->where('me.payslip', null)
            ->where('me.primaryAction.title', 'Open attendance')
            ->where('me.links', fn ($l) => collect($l)->pluck('title')->all() === ['My roster', 'My leave', 'My claims', 'My HR details', 'My payslips'])
            ->has('questions', 3));
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
