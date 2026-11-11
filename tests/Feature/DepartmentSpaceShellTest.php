<?php

namespace Tests\Feature;

use App\Domain\DepartmentSpace\DepartmentSpaceNavigation;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Visit\VisitTestCase;

/** DS-1: the shell shows each role only its own spaces and refuses the rest on the server. */
class DepartmentSpaceShellTest extends VisitTestCase
{
    public function test_a_ca_sees_front_desk_pharmacy_and_billing_and_is_refused_stock(): void
    {
        $ca = $this->actor('ca');
        $this->selectBranch($ca);

        $this->get('/spaces/front_desk')->assertOk()->assertInertia(fn (Assert $p) => $p
            ->component('Spaces/Show')
            ->where('space.key', 'front_desk')
            ->where('spaces.0.key', 'front_desk')
            ->has('spaces', 4));
        $this->get('/spaces/stock')->assertForbidden();
        $this->get('/spaces/management')->assertForbidden();
        $this->get('/spaces/nonsense')->assertNotFound();
    }

    public function test_index_redirects_to_the_first_space_and_a_doctor_gets_consultation(): void
    {
        $doctor = $this->actor('resident_doctor');
        $this->selectBranch($doctor);

        $this->get('/spaces')->assertRedirect('/spaces/consultation');
        $this->get('/spaces/front_desk')->assertForbidden();
        $this->get('/spaces/me')->assertOk();
    }

    public function test_the_director_enters_all_eight_spaces_and_me(): void
    {
        $director = $this->actor('director');
        $this->selectBranch($director);

        foreach (array_keys(DepartmentSpaceNavigation::SPACES) as $key) {
            $this->get("/spaces/$key")->assertOk();
        }
        $this->get('/spaces/me')->assertOk();
    }

    public function test_the_tv_account_and_guests_get_no_space(): void
    {
        $this->get('/spaces')->assertRedirect('/login');
        $tv = $this->actor('queue_display');
        $this->selectBranch($tv);
        $this->get('/spaces')->assertForbidden();
    }
}
