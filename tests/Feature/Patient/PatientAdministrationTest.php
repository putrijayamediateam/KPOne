<?php

namespace Tests\Feature\Patient;

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Patient\Models\PatientIdentifier;
use App\Domain\Patient\Services\PatientAdministrationService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

class PatientAdministrationTest extends PatientTestCase
{
    public function test_ca_can_add_first_type_but_cannot_retire_or_replace_existing_identifier(): void
    {
        $ca = $this->actor('ca');
        $patient = $this->createPatient($ca, ['identifiers' => []]);
        $service = app(PatientAdministrationService::class);
        $identifier = $service->addIdentifier($patient, ['identifier_type' => 'nric', 'value' => '900101011234'], $ca);
        $this->assertSame('900101011234', $identifier->normalized_value);

        foreach (['replace', 'retire'] as $action) {
            try {
                $action === 'replace'
                    ? $service->replaceIdentifier($patient, $identifier, ['identifier_type' => 'nric', 'value' => '900101011235'], $ca)
                    : $service->retireIdentifier($patient, $identifier, $ca);
                $this->fail('CA correction must be denied.');
            } catch (AuthorizationException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_supervisor_correction_retires_history_and_audits_both_steps(): void
    {
        $supervisor = $this->actor('ca_supervisor');
        $patient = $this->createPatient($supervisor, [
            'identifiers' => [['identifier_type' => 'nric', 'value' => '900101011234']],
        ]);
        $original = $patient->identifiers->firstOrFail();
        $replacement = app(PatientAdministrationService::class)->replaceIdentifier(
            $patient,
            $original,
            ['identifier_type' => 'nric', 'value' => '900101011235'],
            $supervisor,
        );

        $this->assertNotNull($original->fresh()->retired_at);
        $this->assertNull($replacement->retired_at);
        $this->assertSame(1, PatientIdentifier::query()->where('patient_id', $patient->id)->whereNull('retired_at')->where('identifier_type', 'nric')->count());
        $this->assertDatabaseHas('audit_logs', ['event' => 'patient.identifier.retired', 'actor_user_id' => $supervisor->id]);
        $this->assertDatabaseHas('audit_logs', ['event' => 'patient.identifier.added', 'actor_user_id' => $supervisor->id]);
    }

    public function test_failed_replacement_rolls_back_retirement_and_audit(): void
    {
        $director = $this->actor();
        $first = $this->createPatient($director, ['identifiers' => [['identifier_type' => 'nric', 'value' => '900101011234']]]);
        $second = $this->createPatient($director, [
            'full_name' => 'Synthetic Patient Beta',
            'date_of_birth' => '1985-02-02',
            'identifiers' => [['identifier_type' => 'nric', 'value' => '850202022345']],
        ]);
        $beforeAudits = AuditLog::query()->count();

        try {
            app(PatientAdministrationService::class)->replaceIdentifier(
                $second,
                $second->identifiers->firstOrFail(),
                ['identifier_type' => 'nric', 'value' => '900101011234'],
                $director,
            );
            $this->fail('Duplicate replacement should fail.');
        } catch (ValidationException) {
            $this->assertNull($second->identifiers->firstOrFail()->fresh()->retired_at);
            $this->assertSame($beforeAudits, AuditLog::query()->count());
            $this->assertSame(2, PatientIdentifier::query()->whereNull('retired_at')->count());
            $this->assertNotNull($first);
        }
    }

    public function test_optimistic_lock_rejects_lost_update_and_patient_number_is_immutable(): void
    {
        $director = $this->actor();
        $patient = $this->createPatient($director);
        $service = app(PatientAdministrationService::class);
        $updated = $service->update($patient, $this->patientAttributes([
            'full_name' => 'Synthetic Updated Name',
            'lock_version' => 1,
            'patient_number' => 'ATTACKER',
        ]), $director);
        $this->assertSame('KP-00000001', $updated->patient_number);
        $this->assertSame(2, $updated->lock_version);

        $this->expectException(ValidationException::class);
        $service->update($patient, $this->patientAttributes(['lock_version' => 1]), $director);
    }

    public function test_later_identifier_addition_conflict_is_controlled_and_leaves_no_partial_row(): void
    {
        $director = $this->actor();
        $first = $this->createPatient($director, [
            'identifiers' => [['identifier_type' => 'passport', 'issuing_country_code' => 'MY', 'value' => 'SYN-P100']],
        ]);
        $second = $this->createPatient($director, [
            'full_name' => 'Synthetic Patient Beta',
            'date_of_birth' => '1980-02-02',
            'identifiers' => [],
        ]);

        try {
            app(PatientAdministrationService::class)->addIdentifier(
                $second,
                ['identifier_type' => 'passport', 'issuing_country_code' => 'MY', 'value' => 'syn - p100'],
                $director,
            );
            $this->fail('Expected reserved identifier conflict.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('identifiers', $exception->errors());
        }

        $this->assertSame(0, $second->identifiers()->count());
        $this->assertSame(1, $first->identifiers()->count());
    }

    public function test_database_constraint_rejects_a_second_current_nric_for_one_patient(): void
    {
        $director = $this->actor();
        $patient = $this->createPatient($director, [
            'identifiers' => [['identifier_type' => 'nric', 'value' => '900101011234']],
        ]);

        try {
            app(PatientAdministrationService::class)->addIdentifier(
                $patient,
                ['identifier_type' => 'nric', 'value' => '900101011235'],
                $director,
            );
            $this->fail('The current-NRIC unique constraint should reject a second row.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('identifiers', $exception->errors());
        }

        $this->assertSame(1, PatientIdentifier::query()
            ->where('patient_id', $patient->id)
            ->where('identifier_type', 'nric')
            ->whereNull('retired_at')
            ->count());
    }
}
