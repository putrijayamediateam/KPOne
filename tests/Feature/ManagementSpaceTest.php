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
            ->has('management.links')
            ->has('questions', 2));
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
