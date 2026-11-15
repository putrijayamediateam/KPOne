<?php

namespace Tests\Feature;

use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Visit\VisitTestCase;

/** DS-9: the HR space shows only what the person's own HR pages already show, as counts and short names. */
class HrSpaceTest extends VisitTestCase
{
    public function test_an_hr_manager_sees_empty_approvals_away_and_roster_widgets_and_the_open_links(): void
    {
        $this->selectBranch($this->actor('hr_manager'));

        $this->get('/spaces/hr')->assertOk()->assertInertia(fn (Assert $p) => $p
            ->component('Spaces/Show')
            ->where('hr.approvals.total', 0)
            ->where('hr.approvals.kinds', [])
            ->where('hr.away.total', 0)
            ->where('hr.away.rows', [])
            ->where('hr.roster.shortDays', 0)
            ->where('hr.primaryAction.title', 'Open roster')
            ->has('hr.links')
            ->has('questions', 2));
    }

    public function test_the_questions_answer_in_plain_sentences_and_a_ca_is_refused_the_space(): void
    {
        $this->selectBranch($this->actor('hr_manager'));
        $this->postJson('/spaces/hr/ask', ['question' => 'hr.away_this_week'])
            ->assertOk()->assertJsonPath('sentence', 'Nobody is away this week.')->assertJsonPath('href', '/hr/leave?tab=calendar');
        $this->postJson('/spaces/hr/ask', ['question' => 'hr.credentials_due'])
            ->assertOk()->assertJsonPath('sentence', 'No credential is due for renewal soon.');

        $this->selectBranch($this->actor('ca'));
        $this->get('/spaces/hr')->assertForbidden();
        $this->postJson('/spaces/hr/ask', ['question' => 'hr.away_this_week'])->assertForbidden();
    }
}
