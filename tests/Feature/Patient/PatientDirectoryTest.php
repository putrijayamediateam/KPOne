<?php

namespace Tests\Feature\Patient;

use App\Domain\Identity\Models\StaffProfile;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Organisation\Models\Department;
use Tests\Support\StaffBranchAssignmentBootstrapper;

class PatientDirectoryTest extends PatientTestCase
{
    public function test_directory_is_search_only_post_paginated_and_minimized(): void
    {
        $director = $this->actor();
        $patient = $this->createPatient($director, [
            'identifiers' => [['identifier_type' => 'nric', 'value' => '900101011234']],
        ]);
        $ca = $this->actor('ca');

        $this->actingAs($ca)->get(route('patients.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Patient/Index')->missing('patients'));

        $response = $this->actingAs($ca)->postJson(route('patients.search'), [
            'query' => 'Synthetic Patient',
            'search_type' => 'name',
        ])->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.patientNumber', $patient->patient_number)
            ->assertJsonPath('data.0.identifier.maskedValue', '******-**-1234')
            ->assertJsonMissingPath('data.0.id')
            ->assertJsonMissingPath('data.0.email')
            ->assertJsonMissingPath('data.0.address')
            ->assertJsonMissingPath('data.0.identifiers.0.normalized_value');

        $this->assertStringNotContainsString('900101011234', $response->getContent());
        $this->assertStringNotContainsString('synthetic.patient@kpone.test', $response->getContent());
        $this->actingAs($ca)->get('/patients/search?query=900101011234')->assertNotFound();
    }

    public function test_identity_search_is_exact_and_post_body_only(): void
    {
        $director = $this->actor();
        $this->createPatient($director, [
            'identifiers' => [['identifier_type' => 'passport', 'issuing_country_code' => 'MY', 'value' => 'Syn P 123']],
        ]);

        $this->actingAs($this->actor('resident_doctor'))->postJson(route('patients.search'), [
            'query' => 'syn p 123',
            'search_type' => 'passport',
            'issuing_country_code' => 'my',
        ])->assertOk()->assertJsonPath('total', 1);
    }

    public function test_branch_assignment_does_not_limit_authorized_organisation_search(): void
    {
        $director = $this->actor();
        $this->createPatient($director);
        $ca = $this->actor('ca');
        $branch = Branch::query()->where('organisation_id', $this->organisation->id)->firstOrFail();
        $profile = new StaffProfile;
        $profile->forceFill([
            'user_id' => $ca->id,
            'department_id' => Department::query()->where('organisation_id', $this->organisation->id)->firstOrFail()->id,
        ])->save();
        StaffBranchAssignmentBootstrapper::create($profile, $branch, ['is_primary' => true]);

        $this->actingAs($ca)->postJson(route('patients.search'), [
            'query' => 'Synthetic Patient',
            'search_type' => 'name',
        ])->assertOk()->assertJsonPath('total', 1);
    }

    public function test_search_requires_meaningful_input_and_has_private_no_store_headers(): void
    {
        $ca = $this->actor('ca');
        $this->actingAs($ca)->postJson(route('patients.search'), ['query' => 'ab', 'search_type' => 'name'])
            ->assertUnprocessable()->assertJsonValidationErrors('query');
        $response = $this->actingAs($ca)->get(route('patients.index'));
        $response->assertOk();
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('private', (string) $response->headers->get('Cache-Control'));
    }

    public function test_name_search_treats_like_metacharacters_as_literals(): void
    {
        $director = $this->actor();
        $this->createPatient($director);
        $ca = $this->actor('ca');

        foreach (['%%%', '___', 'Syn%'] as $query) {
            $this->actingAs($ca)->postJson(route('patients.search'), [
                'query' => $query,
                'search_type' => 'name',
            ])->assertOk()->assertJsonPath('total', 0);
        }

        $this->actingAs($ca)->postJson(route('patients.search'), [
            'query' => 'Synthetic',
            'search_type' => 'name',
        ])->assertOk()->assertJsonPath('total', 1);
    }
}
