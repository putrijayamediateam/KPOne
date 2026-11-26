<?php

namespace Tests\Feature\Visit;

use App\Http\Middleware\HandleInertiaRequests;
use Inertia\Testing\AssertableInertia as Assert;

class RegistrationDialogProjectionTest extends VisitTestCase
{
    public function test_authorized_json_form_is_private_uncached_and_contains_only_existing_form_props(): void
    {
        $this->selectBranch($this->actor());
        $response = $this->getJson(route('registration.create'))->assertOk();
        $this->assertEqualsCanonicalizing(['guardianToday', 'options', 'requirements', 'recentPatients'], array_keys($response->json()));
        $response->assertJsonPath('options.branch.id', $this->branch->id);
        $this->assertTrue($response->headers->hasCacheControlDirective('private'));
        $this->assertTrue($response->headers->hasCacheControlDirective('no-store'));
        $key = $response->json('options.idempotencyKey');
        $this->assertNotEmpty($key);
        $this->assertNotSame($key, $this->getJson(route('registration.create'))->assertOk()->json('options.idempotencyKey'));
        $this->assertDatabaseCount('visits', 0);
    }

    public function test_inertia_request_retains_the_fullpage_component_even_with_json_accept_header(): void
    {
        $this->selectBranch($this->actor());
        $this->getJson(route('registration.create'), ['X-Inertia' => 'true', 'X-Inertia-Version' => app(HandleInertiaRequests::class)->version(request()) ?? ''])
            ->assertOk()
            ->assertHeader('X-Inertia', 'true')
            ->assertJsonPath('component', 'Registration/Create')
            ->assertJsonStructure(['props' => ['options', 'requirements', 'recentPatients']]);
        $this->get(route('registration.create'))
            ->assertInertia(fn (Assert $page) => $page->component('Registration/Create')->has('options')->has('requirements')->has('recentPatients'));
    }

    public function test_json_representation_does_not_grant_doctor_or_technical_admin_registration_access(): void
    {
        foreach (['resident_doctor', 'technical_admin'] as $role) {
            $this->selectBranch($this->actor($role));
            $this->getJson(route('registration.create'))->assertForbidden();
        }
        $this->assertDatabaseCount('visits', 0);
    }

    public function test_invalid_modal_submission_returns_errors_to_the_board_without_creating_a_visit(): void
    {
        $this->selectBranch($this->actor());
        $options = $this->getJson(route('registration.create'))->assertOk()->json('options');
        $this->from(route('registration.index'))->post(route('registration.store'), [
            'idempotency_key' => $options['idempotencyKey'],
            'expected_branch_id' => $this->branch->id,
            'visit_type' => 'consultation',
            'coverage_type' => 'self_pay',
        ])->assertRedirect(route('registration.index'))->assertSessionHasErrors('patient_number');
        $this->assertDatabaseCount('visits', 0);
    }
}
