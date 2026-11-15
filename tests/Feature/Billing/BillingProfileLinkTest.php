<?php

namespace Tests\Feature\Billing;

use Inertia\Testing\AssertableInertia as Assert;

class BillingProfileLinkTest extends BillingTestCase
{
    public function test_profile_link_requires_existing_patient_permission_and_does_not_add_identity_or_clinical_fields(): void
    {
        [, $ca, $visit] = $this->finalizedFixture();
        $this->actingAs($ca)->get(route('billing.show', $visit))
            ->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('billing.patient.profileUrl', '/patients/'.$visit->patient->patient_number)
            ->missing('billing.patient.identifiers')->missing('billing.patient.dateOfBirth')
            ->missing('billing.clinicalNote')->missing('billing.consultation'));

        $ca->syncRoles([]);
        $ca->givePermissionTo('billing.summary.branch');
        $this->get(route('billing.show', $visit))->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('billing.patient.profileUrl', null)
                ->has('billing.invoice.lines', 0));
    }
}
