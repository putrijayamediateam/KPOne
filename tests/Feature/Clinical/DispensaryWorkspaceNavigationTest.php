<?php

namespace Tests\Feature\Clinical;

use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Visit\VisitTestCase;

class DispensaryWorkspaceNavigationTest extends VisitTestCase
{
    public function test_ca_cannot_see_returns_navigation_and_the_destination_refuses_access(): void
    {
        $actor = $this->actor('ca');
        $this->selectBranch($actor);
        $this->actingAs($actor)->get(route('dispensary.index'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('canViewReturns', false));
        $this->get(route('dispensary.returns-to-check'))->assertForbidden();
    }

    public function test_ca_supervisor_sees_returns_navigation_and_can_open_destination(): void
    {
        $actor = $this->actor('ca_supervisor');
        $this->selectBranch($actor);
        $this->actingAs($actor)->get(route('dispensary.index'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('canViewReturns', true));
        $this->get(route('dispensary.returns-to-check'))->assertOk();
    }
}
