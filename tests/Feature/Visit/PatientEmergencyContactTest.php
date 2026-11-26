<?php

namespace Tests\Feature\Visit;

use App\Domain\Patient\Services\PatientEmergencyContactService;
use App\Domain\Queue\Services\OtcQueueService;
use App\Domain\Visit\Services\RegisterAndQueueService;
use App\Domain\Visit\Services\VisitRegistrationService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PatientEmergencyContactTest extends VisitTestCase
{
    private function command(array $overrides = []): array
    {
        return ['operation' => 'set', 'lock_version' => 0, 'idempotency_key' => (string) Str::uuid(), 'full_name' => 'Synthetic Contact', 'relationship' => 'Friend', 'phone' => '+60123456789', ...$overrides];
    }

    public function test_contact_is_encrypted_and_clear_keeps_version_for_next_save(): void
    {
        $actor = $this->actor();
        $patient = $this->patient();
        $this->selectBranch($actor);
        $service = app(PatientEmergencyContactService::class);
        $saved = $service->update($actor, $patient, ['expected_branch_id' => $this->branch->id, 'emergency_contact_command' => $this->command()]);
        $this->assertSame(1, $saved['lock_version']);
        $row = DB::table('patient_emergency_contacts')->first();
        $this->assertStringNotContainsString('Synthetic Contact', $row->encrypted_contact);
        $this->assertStringContainsString('Synthetic Contact', Crypt::decryptString($row->encrypted_contact));
        $clear = ['operation' => 'clear', 'lock_version' => 1, 'idempotency_key' => (string) Str::uuid()];
        $cleared = $service->update($actor, $patient, ['expected_branch_id' => $this->branch->id, 'emergency_contact_command' => $clear]);
        $this->assertSame(['values' => null, 'lock_version' => 2], $cleared);
        $again = $service->update($actor, $patient, ['expected_branch_id' => $this->branch->id, 'emergency_contact_command' => $this->command(['lock_version' => 2])]);
        $this->assertSame(3, $again['lock_version']);
    }

    public function test_exact_retry_returns_current_contact_without_reverting_a_later_update(): void
    {
        $actor = $this->actor();
        $patient = $this->patient();
        $this->selectBranch($actor);
        $service = app(PatientEmergencyContactService::class);
        $first = $this->command();
        $service->update($actor, $patient, ['expected_branch_id' => $this->branch->id, 'emergency_contact_command' => $first]);
        $service->update($actor, $patient, ['expected_branch_id' => $this->branch->id, 'emergency_contact_command' => $this->command(['lock_version' => 1, 'full_name' => 'Synthetic Later'])]);
        $replay = $service->update($actor, $patient, ['expected_branch_id' => $this->branch->id, 'emergency_contact_command' => $first]);
        $this->assertSame('Synthetic Later', $replay['values']['full_name']);
        $this->assertSame(2, $replay['lock_version']);
        $this->assertDatabaseCount('patient_emergency_contact_commands', 2);
    }

    public function test_stale_write_and_same_key_different_payload_are_refused(): void
    {
        $actor = $this->actor();
        $patient = $this->patient();
        $this->selectBranch($actor);
        $service = app(PatientEmergencyContactService::class);
        $first = $this->command();
        $service->update($actor, $patient, ['expected_branch_id' => $this->branch->id, 'emergency_contact_command' => $first]);
        foreach ([$this->command(), [...$first, 'full_name' => 'Changed']] as $command) {
            try {
                $service->update($actor, $patient, ['expected_branch_id' => $this->branch->id, 'emergency_contact_command' => $command]);
                $this->fail('Expected conflict');
            } catch (ValidationException) {
                $this->assertDatabaseCount('patient_emergency_contact_commands', 1);
            }
        }
    }

    public function test_partial_contact_and_invalid_phone_are_refused(): void
    {
        $service = app(PatientEmergencyContactService::class);
        foreach (['full_name', 'relationship', 'phone'] as $field) {
            try {
                $service->validate($this->command([$field => '']));
                $this->fail('Expected required field');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('emergency_contact_command.'.$field, $e->errors());
            }
        }
        $this->expectException(ValidationException::class);
        $service->validate($this->command(['phone' => '123']));
    }

    public function test_registration_optional_and_contact_write_rolls_back_on_later_failure(): void
    {
        $actor = $this->actor();
        $patient = $this->patient();
        $this->selectBranch($actor);
        $registration = app(VisitRegistrationService::class);
        $registration->register($actor, $this->visitAttributes($patient));
        $this->assertDatabaseCount('patient_emergency_contacts', 0);
        $attributes = $this->visitAttributes($patient);
        $attributes['emergency_contact_command'] = $this->command(['idempotency_key' => $attributes['idempotency_key']]);
        try {
            $registration->register($actor, $attributes);
            $this->fail('Expected repeat attendance failure');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('confirm_repeat', $e->errors());
        }
        $this->assertDatabaseCount('patient_emergency_contacts', 0);
        $this->assertDatabaseCount('patient_emergency_contact_commands', 0);
    }

    public function test_registration_replay_cannot_add_change_or_omit_contact(): void
    {
        $actor = $this->actor();
        $patient = $this->patient();
        $this->selectBranch($actor);
        $registration = app(VisitRegistrationService::class);
        $attributes = $this->visitAttributes($patient);
        $attributes['emergency_contact_command'] = $this->command(['idempotency_key' => $attributes['idempotency_key']]);
        $visit = $registration->register($actor, $attributes);
        $this->assertSame($visit->id, $registration->register($actor, $attributes)->id);
        $changed = $attributes;
        $changed['emergency_contact_command']['full_name'] = 'Different';
        $omitted = $attributes;
        unset($omitted['emergency_contact_command']);
        foreach ([$changed, $omitted] as $replay) {
            try {
                $registration->register($actor, $replay);
                $this->fail('Expected replay conflict');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('emergency_contact_command', $e->errors());
            }
        }
        $this->assertDatabaseCount('patient_emergency_contact_commands', 1);
        $other = $this->visitAttributes($this->patient());
        $registration->register($actor, $other);
        $other['emergency_contact_command'] = $this->command(['idempotency_key' => $other['idempotency_key']]);
        $this->expectException(ValidationException::class);
        $registration->register($actor, $other);
    }

    public function test_doctor_can_view_but_cannot_update_contact(): void
    {
        $actor = $this->actor('resident_doctor');
        $patient = $this->patient();
        $this->selectBranch($actor);
        $service = app(PatientEmergencyContactService::class);
        $this->assertSame(['values' => null, 'lock_version' => 0], $service->contact($actor, $patient));
        $this->expectException(AuthorizationException::class);
        $service->update($actor, $patient, ['expected_branch_id' => $this->branch->id, 'emergency_contact_command' => $this->command()]);
    }

    public function test_contact_endpoint_is_private_and_not_part_of_search_results(): void
    {
        $actor = $this->actor();
        $patient = $this->patient();
        $this->selectBranch($actor);
        app(PatientEmergencyContactService::class)->update($actor, $patient, ['expected_branch_id' => $this->branch->id, 'emergency_contact_command' => $this->command()]);
        $this->getJson('/patients/'.$patient->patient_number.'/emergency-contact')->assertOk()->assertJsonPath('values.full_name', 'Synthetic Contact')->assertHeader('Cache-Control', 'max-age=0, must-revalidate, no-store, private');
        $this->postJson('/patients/search', ['query' => $patient->patient_number])->assertDontSee('Synthetic Contact');
    }

    public function test_quick_create_saves_contact_atomically_and_audit_contains_no_values(): void
    {
        $actor = $this->actor();
        $this->selectBranch($actor);
        $key = (string) Str::uuid();
        $attributes = ['idempotency_key' => $key, 'expected_branch_id' => $this->branch->id,
            'quick_patient' => ['full_name' => 'Synthetic Emergency Quick', 'date_of_birth' => '1990-01-01', 'sex' => 'unknown', 'mobile_phone' => '+60123456789', 'identifiers' => [['identifier_type' => 'passport', 'issuing_country_code' => 'MY', 'value' => 'SYN-EC-QUICK']], 'duplicate_override' => true],
            'visit_type' => 'otc', 'priority' => 'normal', 'coverage_type' => 'self_pay',
            'emergency_contact_command' => $this->command(['idempotency_key' => $key])];
        $registration = app(VisitRegistrationService::class);
        $visit = $registration->register($actor, $attributes);
        $this->assertSame($visit->id, $registration->register($actor, $attributes)->id);
        $this->assertSame('Synthetic Contact', app(PatientEmergencyContactService::class)->contact($actor, $visit->patient)['values']['full_name']);
        $metadata = DB::table('audit_logs')->where('event', 'patient.emergency_contact.updated')->value('metadata');
        foreach (['Synthetic Contact', '+60123456789', 'Friend'] as $value) {
            $this->assertStringNotContainsString($value, $metadata);
        }
        $this->assertDatabaseCount('patient_emergency_contact_commands', 1);
    }

    public function test_foreign_patient_and_wrong_branch_are_refused_without_writes(): void
    {
        $actor = $this->actor();
        $patient = $this->patient();
        $this->selectBranch($actor);
        $service = app(PatientEmergencyContactService::class);
        try {
            $service->update($actor, $patient, ['expected_branch_id' => -1, 'emergency_contact_command' => $this->command()]);
            $this->fail('Expected wrong branch refusal');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('expected_branch_id', $e->errors());
        }
        $foreign = clone $patient;
        $foreign->organisation_id = $actor->organisation_id + 999;
        try {
            $service->contact($actor, $foreign);
            $this->fail('Expected foreign patient refusal');
        } catch (AuthorizationException) {
            $this->assertDatabaseCount('patient_emergency_contacts', 0);
        }
    }

    public function test_revoked_update_authority_refuses_retry(): void
    {
        $actor = $this->actor();
        $patient = $this->patient();
        $this->selectBranch($actor);
        $service = app(PatientEmergencyContactService::class);
        $command = $this->command();
        $input = ['expected_branch_id' => $this->branch->id, 'emergency_contact_command' => $command];
        $service->update($actor, $patient, $input);
        $actor->syncRoles(['resident_doctor']);
        $this->expectException(AuthorizationException::class);
        $service->update($actor, $patient, $input);
    }

    public function test_registration_contact_retry_binds_patient_and_creation_mode(): void
    {
        $actor = $this->actor();
        $patient = $this->patient();
        $other = $this->patient();
        $this->selectBranch($actor);
        $registration = app(VisitRegistrationService::class);
        $attributes = $this->visitAttributes($patient);
        $attributes['emergency_contact_command'] = $this->command(['idempotency_key' => $attributes['idempotency_key']]);
        $registration->register($actor, $attributes);
        $changedPatient = [...$attributes, 'patient_number' => $other->patient_number];
        $changedMode = $attributes;
        unset($changedMode['patient_number']);
        $changedMode['quick_patient'] = ['full_name' => 'Synthetic switched mode'];
        foreach ([$changedPatient, $changedMode] as $replay) {
            try {
                $registration->register($actor, $replay);
                $this->fail('Expected target mismatch');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('emergency_contact_command', $e->errors());
            }
        }
        $this->assertSame(1, app(PatientEmergencyContactService::class)->contact($actor, $patient)['lock_version']);
        $this->assertSame(0, app(PatientEmergencyContactService::class)->contact($actor, $other)['lock_version']);
    }

    public function test_queue_failure_rolls_back_both_registration_and_emergency_contact(): void
    {
        $actor = $this->actor();
        $patient = $this->patient();
        $this->selectBranch($actor);
        $attributes = $this->visitAttributes($patient);
        $attributes['emergency_contact_command'] = $this->command(['idempotency_key' => $attributes['idempotency_key']]);
        $this->mock(OtcQueueService::class)->shouldReceive('enter')->once()
            ->andThrow(ValidationException::withMessages(['queue' => 'Synthetic queue refusal.']));
        try {
            app(RegisterAndQueueService::class)->register($actor, $attributes);
            $this->fail('Expected queue refusal');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('queue', $e->errors());
        }
        $this->assertDatabaseCount('visits', 0);
        $this->assertDatabaseCount('patient_emergency_contacts', 0);
        $this->assertDatabaseCount('patient_emergency_contact_commands', 0);
        $this->assertSame(0, DB::table('audit_logs')->where('event', 'patient.emergency_contact.updated')->count());
    }
}
