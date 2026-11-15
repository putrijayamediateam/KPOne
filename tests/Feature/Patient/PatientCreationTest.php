<?php

namespace Tests\Feature\Patient;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Patient\Models\Patient;
use App\Domain\Patient\Models\PatientIdentifier;
use App\Domain\Patient\Services\PatientAdministrationService;
use App\Domain\Patient\Services\PatientIdentityService;
use App\Domain\Patient\Services\PatientNumberGenerator;
use App\Models\User;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

class PatientCreationTest extends PatientTestCase
{
    public function test_authorised_ca_creates_organisation_patient_with_normalized_identity(): void
    {
        $ca = $this->actor('ca');
        $patient = $this->createPatient($ca, [
            'organisation_id' => 999999,
            'patient_number' => 'ATTACKER-1',
            'identifiers' => [[
                'identifier_type' => 'nric',
                'issuing_country_code' => 'SG',
                'value' => '900101-01-1234',
            ]],
        ]);

        $this->assertSame($ca->organisation_id, $patient->organisation_id);
        $this->assertSame('KP-00000001', $patient->patient_number);
        $this->assertSame('+60123456789', $patient->mobile_phone);
        $this->assertSame('synthetic.patient@kpone.test', $patient->email);
        $this->assertDatabaseHas('patient_identifiers', [
            'patient_id' => $patient->id,
            'issuing_country_code' => 'MY',
            'normalized_value' => '900101011234',
        ]);
        $this->assertDatabaseHas('audit_logs', ['event' => 'patient.created', 'actor_user_id' => $ca->id]);
    }

    public function test_http_payload_cannot_set_ownership_or_system_fields(): void
    {
        $ca = $this->actor('ca');
        $this->actingAs($ca)->post(route('patients.store'), [
            ...$this->patientAttributes(),
            'organisation_id' => 999,
            'patient_number' => 'ATTACKER',
            'created_by_user_id' => 999,
            'lock_version' => 99,
        ])->assertSessionHasErrors(['organisation_id', 'patient_number', 'created_by_user_id', 'lock_version']);
        $this->assertDatabaseCount('patients', 0);
    }

    public function test_models_are_not_generically_mass_assignable(): void
    {
        foreach ([
            fn () => (new Patient)->fill(['organisation_id' => $this->organisation->id]),
            fn () => (new PatientIdentifier)->fill(['normalized_value' => 'ATTACKER']),
        ] as $write) {
            try {
                $write();
                $this->fail('Protected Patient Master model accepted generic mass assignment.');
            } catch (MassAssignmentException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_patient_audit_metadata_is_structural_and_contains_no_pii(): void
    {
        $director = $this->actor();
        $patient = $this->createPatient($director, [
            'identifiers' => [['identifier_type' => 'passport', 'issuing_country_code' => 'MY', 'value' => 'SYN P12345']],
        ]);
        $encoded = AuditLog::query()->where('subject_type', $patient->getMorphClass())->get()->pluck('metadata')->toJson();

        foreach (['Synthetic Patient Alpha', 'SYN P12345', 'SYNP12345', '0123456789', '+60123456789', 'synthetic.patient@kpone.test', '1 Synthetic Street'] as $sensitive) {
            $this->assertStringNotContainsString($sensitive, $encoded);
        }
    }

    public function test_late_audit_failure_rolls_back_complete_creation_and_counter(): void
    {
        $actor = $this->actor();
        $failingAudit = new class extends AuditRecorder
        {
            public function record(
                string $event,
                ?Model $subject = null,
                array $metadata = [],
                ?User $actor = null,
                ?Branch $branch = null,
                ?int $organisationId = null,
            ): ?AuditLog {
                if ($event === 'patient.created') {
                    throw new RuntimeException('Injected patient audit failure.');
                }

                return parent::record($event, $subject, $metadata, $actor, $branch, $organisationId);
            }
        };
        $service = new PatientAdministrationService(
            app(PatientIdentityService::class),
            app(PatientNumberGenerator::class),
            $failingAudit,
        );
        $auditCountBefore = AuditLog::query()->count();

        try {
            $service->create($actor, $this->patientAttributes([
                'identifiers' => [['identifier_type' => 'nric', 'value' => '900101011234']],
            ]));
            $this->fail('Expected injected late failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Injected patient audit failure.', $exception->getMessage());
        }

        $this->assertDatabaseCount('patients', 0);
        $this->assertDatabaseCount('patient_identifiers', 0);
        $this->assertSame($auditCountBefore, AuditLog::query()->count());
        $this->assertDatabaseCount('patient_number_counters', 0);
    }

    public function test_audit_viewer_without_patient_view_receives_neutral_subject(): void
    {
        $patient = $this->createPatient($this->actor());
        $technical = $this->actor('technical_admin');

        $this->actingAs($technical)->get(route('audit-logs.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('logs.data', fn ($logs) => collect($logs)->contains(
                    fn (array $log) => $log['subjectType'] === 'Patient record' && $log['subjectId'] === null,
                )));
        $this->assertNotNull($patient);
    }
}
