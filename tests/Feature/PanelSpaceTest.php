<?php

namespace Tests\Feature;

use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Visit\VisitTestCase;

/** DS-7: the Panel space shows only what the person's own Panel pages already show. */
class PanelSpaceTest extends VisitTestCase
{
    public function test_a_panel_officer_sees_the_claim_widgets_links_and_primary_action(): void
    {
        $this->selectBranch($this->actor('panel_officer'));

        $this->get('/spaces/panel')->assertOk()->assertInertia(fn (Assert $p) => $p
            ->component('Spaces/Show')
            ->where('panel.toClaim.total', 0)
            ->where('panel.toClaim.rows', [])
            ->where('panel.followUp.total', 0)
            ->where('panel.primaryAction', ['title' => 'Open Panel Claims', 'href' => '/panel-claims'])
            ->has('panel.links')
            ->has('questions', 2));
    }

    public function test_a_report_only_viewer_gets_the_summary_link_and_no_claim_widgets_or_questions(): void
    {
        $this->selectBranch($this->actor('director'));

        $props = $this->get('/spaces/panel')->assertOk()->viewData('page')['props'];
        $this->assertNull($props['panel']['toClaim']);
        $this->assertNull($props['panel']['followUp']);
        $this->assertSame([], $props['questions']);
        $this->assertContains(['title' => 'Panel summary', 'href' => '/reports/panel-summary'], $props['panel']['links']);
    }

    public function test_the_ask_follows_the_claim_permission(): void
    {
        $this->selectBranch($this->actor('panel_officer'));
        $this->postJson('/spaces/panel/ask', ['question' => 'panel.most_unclaimed'])
            ->assertOk()->assertJsonPath('sentence', 'No approved Panel invoice is waiting for a claim.')->assertJsonPath('href', '/panel-claims');
        $this->postJson('/spaces/panel/ask', ['question' => 'panel.overdue_by_panel'])
            ->assertOk()->assertJsonPath('sentence', 'No Panel has overdue claims to make.');

        $this->selectBranch($this->actor('director'));
        $this->postJson('/spaces/panel/ask', ['question' => 'panel.most_unclaimed'])->assertForbidden();

        $this->selectBranch($this->actor('ca'));
        $this->get('/spaces/panel')->assertForbidden();
    }
}
