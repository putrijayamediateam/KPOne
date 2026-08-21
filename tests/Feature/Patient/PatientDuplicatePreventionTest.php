<?php

namespace Tests\Feature\Patient;

use App\Domain\Patient\Services\PatientAdministrationService;
use Illuminate\Validation\ValidationException;

class PatientDuplicatePreventionTest extends PatientTestCase
{
    public function test_exact_and_normalized_nric_duplicates_are_blocked(): void
    {
        $actor = $this->actor();
        $service = app(PatientAdministrationService::class);
        $service->create($actor, $this->patientAttributes([
            'identifiers' => [['identifier_type' => 'nric', 'value' => '900101-01-1234']],
        ]));

        foreach (['900101011234', '900101 01 1234'] as $duplicate) {
            try {
                $service->create($actor, $this->patientAttributes([
                    'full_name' => 'Distinct Synthetic Person',
                    'date_of_birth' => '1980-02-02',
                    'identifiers' => [['identifier_type' => 'nric', 'value' => $duplicate]],
                ]));
                $this->fail('Duplicate NRIC should fail.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('identifiers', $exception->errors());
                $this->assertStringNotContainsString($duplicate, $exception->getMessage());
            }
        }
        $this->assertDatabaseCount('patients', 1);
    }

    public function test_passport_case_and_space_duplicate_and_retired_identifier_reuse_are_blocked(): void
    {
        $actor = $this->actor();
        $service = app(PatientAdministrationService::class);
        $patient = $service->create($actor, $this->patientAttributes([
            'identifiers' => [['identifier_type' => 'passport', 'issuing_country_code' => 'MY', 'value' => 'Syn P 123']],
        ]));
        $identifier = $patient->identifiers->firstOrFail();
        $service->retireIdentifier($patient, $identifier, $actor);

        $this->expectException(ValidationException::class);
        $service->create($actor, $this->patientAttributes([
            'full_name' => 'Distinct Synthetic Person',
            'date_of_birth' => '1980-02-02',
            'identifiers' => [['identifier_type' => 'passport', 'issuing_country_code' => 'my', 'value' => 's y n p 1 2 3']],
        ]));
    }

    public function test_same_name_and_shared_phone_are_allowed_only_after_soft_warning_confirmation(): void
    {
        $actor = $this->actor();
        $service = app(PatientAdministrationService::class);
        $service->create($actor, $this->patientAttributes());

        try {
            $service->create($actor, $this->patientAttributes(['date_of_birth' => '1981-01-01', 'duplicate_override' => false]));
            $this->fail('Expected soft duplicate warning.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('duplicate_override', $exception->errors());
        }

        $service->create($actor, $this->patientAttributes(['date_of_birth' => '1981-01-01', 'duplicate_override' => true]));
        $service->create($actor, $this->patientAttributes([
            'full_name' => 'Synthetic Twin Sibling',
            'date_of_birth' => '2015-03-03',
            'duplicate_override' => true,
        ]));
        $this->assertDatabaseCount('patients', 3);
    }
}
