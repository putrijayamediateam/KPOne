<?php

namespace Tests\Feature;

use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Visit\VisitTestCase;

/** DS-10: the Management space shows only what the person's own pages already show, as counts and short names. */
class ManagementSpaceTest extends VisitTestCase
{
    public function test_the_director_sees_staff_counts_a_documents_summary_and_the_hub_links(): void
    {
        $this->selectBranch($this->actor('director'));

        $this->get('/spaces/management')->assertOk()->assertInertia(fn (Assert $p) => $p
            ->component('Spaces/Show')
            ->has('management.staff.active')
            ->has('management.staff.inactive')
            ->has('management.documents.text')
            ->where('management.primaryAction', ['title' => 'Open Staff directory', 'href' => '/staff'])
            ->has('management.documents.warning')
            ->where('management.links', fn ($l) => collect($l)->pluck('title')->all() === ['Branches', 'Roles and access', 'Clinician credentials', 'Registration settings'])
            ->has('questions', 2));
    }

    public function test_an_inactive_account_shows_its_date_for_a_viewer_who_manages_staff(): void
    {
        $director = $this->actor('director');
        $gone = $this->actor('ca');
        $gone->forceFill(['is_active' => false, 'deactivated_at' => '2026-11-05 03:00:00'])->save();
        $this->selectBranch($director);

        $this->postJson('/spaces/management/ask', ['question' => 'management.inactive_staff'])->assertOk()
            ->assertJsonPath('columns', ['Staff', 'Role', 'Branch', 'Inactive since'])
            ->assertJsonPath('rows.0.3', '5 Nov 2026');
    }

    public function test_the_open_rows_follow_each_pages_own_permission(): void
    {
        $this->selectBranch($this->actor('administrator'));
        $this->get('/spaces/management')->assertOk()->assertInertia(fn (Assert $p) => $p
            ->where('management.links', fn ($l) => ! collect($l)->pluck('title')->contains('Roles and access')));

        $this->selectBranch($this->actor('technical_admin'));
        $this->get('/spaces/management')->assertOk()->assertInertia(fn (Assert $p) => $p
            ->where('management.documents', null)
            ->where('management.links', fn ($l) => ! collect($l)->pluck('title')->contains('Registration settings')));
    }

    public function test_the_questions_answer_in_plain_sentences_and_a_ca_is_refused_the_space(): void
    {
        $this->selectBranch($this->actor('director'));
        $this->postJson('/spaces/management/ask', ['question' => 'management.documents_attention'])
            ->assertOk()->assertJsonPath('href', '/business-details');
        $this->postJson('/spaces/management/ask', ['question' => 'management.inactive_staff'])
            ->assertOk()->assertJsonPath('sentence', 'No staff account is inactive.');

        $this->selectBranch($this->actor('ca'));
        $this->get('/spaces/management')->assertForbidden();
        $this->postJson('/spaces/management/ask', ['question' => 'management.inactive_staff'])->assertForbidden();
    }
}
