<?php

namespace Tests\Feature;

use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Visit\VisitTestCase;

/** DS-3: the Pharmacy space shows only what the person's own Dispensary pages already show. */
class PharmacySpaceTest extends VisitTestCase
{
    public function test_a_ca_sees_the_dispensary_widget_but_not_returns_to_check(): void
    {
        $ca = $this->actor('ca');
        $this->selectBranch($ca);

        $this->get('/spaces/pharmacy')->assertOk()->assertInertia(fn (Assert $p) => $p
            ->component('Spaces/Show')
            ->where('pharmacy.toDispense.total', 0)
            ->where('pharmacy.toDispense.rows', [])
            ->where('pharmacy.returnsToCheck', null));
    }

    public function test_a_ca_supervisor_also_sees_returns_to_check(): void
    {
        $sup = $this->actor('ca_supervisor');
        $this->selectBranch($sup);

        $this->get('/spaces/pharmacy')->assertInertia(fn (Assert $p) => $p
            ->where('pharmacy.returnsToCheck.total', 0)
            ->has('pharmacy.links', 2));
    }

    public function test_the_director_gets_the_space_but_no_widget_it_could_not_open_on_its_page(): void
    {
        $director = $this->actor('director');
        $this->selectBranch($director);

        $this->get('/spaces/pharmacy')->assertOk()->assertInertia(fn (Assert $p) => $p
            ->where('pharmacy.toDispense', null)
            ->where('pharmacy.returnsToCheck', null)
            ->where('pharmacy.links', []));
    }

    public function test_roles_without_dispensary_are_refused_and_other_spaces_carry_no_pharmacy_data(): void
    {
        foreach (['resident_doctor', 'panel_officer', 'finance_officer', 'marketing'] as $role) {
            $this->selectBranch($this->actor($role));
            $this->get('/spaces/pharmacy')->assertForbidden();
        }
        $this->selectBranch($this->actor('ca'));
        $this->get('/spaces/front_desk')->assertInertia(fn (Assert $p) => $p->where('pharmacy', null));
    }
}
