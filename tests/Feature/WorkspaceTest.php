<?php

namespace Tests\Feature;

use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Visit\VisitTestCase;

class WorkspaceTest extends VisitTestCase
{
    public function test_workspace_landing_is_role_aware_without_implying_clinical_authority(): void
    {
        foreach ([
            'ca' => 'registration.index',
            'ca_supervisor' => 'registration.index',
            'resident_doctor' => 'queue.index',
            'director' => 'dashboard',
            'technical_admin' => 'dashboard',
            'hr_manager' => 'dashboard',
        ] as $role => $destination) {
            $actor = $this->actor($role);
            $this->selectBranch($actor);

            $this->get(route('workspace'))->assertRedirectToRoute($destination);
        }
    }

    public function test_resident_doctor_authority_takes_precedence_for_a_multi_role_director(): void
    {
        $actor = $this->actor('director');
        $actor->assignRole('resident_doctor');
        $this->selectBranch($actor);

        $this->get(route('workspace'))->assertRedirectToRoute('queue.index');
    }

    public function test_root_and_shared_workspace_navigation_converge_on_workspace(): void
    {
        $ca = $this->actor('ca');
        $this->selectBranch($ca);

        $this->get('/')->assertRedirectToRoute('workspace');
        $this->get(route('registration.index'))->assertInertia(fn (Assert $page) => $page
            ->where('workspace.defaultUrl', '/workspace')
            ->where('workspace.canEnterClinic', true)
            ->where('workspace.navigation.registration', true)
            ->where('workspace.navigation.consultation', true)
            ->where('workspace.navigation.placeholders', true));
    }

    public function test_placeholder_modules_are_lightweight_and_clinic_authority_gated(): void
    {
        $ca = $this->actor('ca');
        $this->selectBranch($ca);

        foreach ([
            'clinic.reviews' => 'Reviews',
            'clinic.insight' => 'Insight',
            'clinic.purchase' => 'Purchase',
        ] as $route => $module) {
            $this->get(route($route))->assertOk()->assertInertia(fn (Assert $page) => $page
                ->component('Clinic/Placeholder')
                ->where('module', $module)
                ->missing('records')
                ->missing('analytics'));
        }

        $technical = $this->actor('technical_admin');
        $this->selectBranch($technical);
        $this->get(route('clinic.reviews'))->assertForbidden();
    }
}
