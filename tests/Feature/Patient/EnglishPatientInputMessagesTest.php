<?php

namespace Tests\Feature\Patient;

use App\Domain\Patient\Services\PatientAdministrationService;
use App\Domain\Patient\Services\PatientIdentityService;
use App\Domain\Patient\Services\PatientPhoneNormalizer;
use App\Domain\Patient\Services\PublicIntakePayloadValidator;
use Illuminate\Validation\ValidationException;

/**
 * REG-2 (owner decision 2026-11-05): the phone and IC/Passport validation messages are English everywhere,
 * on staff screens and on the public QR form (an explicit owner exception to the Bahasa Melayu patient-facing
 * default, for these messages only).
 */
class EnglishPatientInputMessagesTest extends PatientTestCase
{
    /** @return list<string> */
    private function messagesFrom(callable $action): array
    {
        try {
            $action();
        } catch (ValidationException $exception) {
            return array_merge(...array_values($exception->errors()));
        }

        $this->fail('Expected a validation error.');
    }

    public function test_staff_patient_creation_reports_phone_and_identity_errors_in_english(): void
    {
        $actor = $this->actor('ca');

        $this->assertSame(['Phone number is required.'], $this->messagesFrom(fn () => $this->createPatient($actor, ['mobile_phone' => null])));
        $this->assertSame(['Check the country code and phone number.'], $this->messagesFrom(fn () => $this->createPatient($actor, ['mobile_phone' => '+600123456789'])));
        $this->assertSame(['Enter a valid phone number for the selected country.'], $this->messagesFrom(fn () => $this->createPatient($actor, ['mobile_phone' => '0991234567'])));
        $this->assertSame(['IC number must have 12 digits.'], $this->messagesFrom(fn () => $this->createPatient($actor, ['identifiers' => [['identifier_type' => 'nric', 'value' => '1234']]])));
        $this->assertSame(['Passport issuing country is required.'], $this->messagesFrom(fn () => $this->createPatient($actor, ['identifiers' => [['identifier_type' => 'passport', 'value' => 'SYN-PASS']]])));
        $this->assertSame(['Passport number is incomplete.'], $this->messagesFrom(fn () => $this->createPatient($actor, ['identifiers' => [['identifier_type' => 'passport', 'issuing_country_code' => 'MY', 'value' => 'A1']]])));
        $this->assertSame(['Check the Passport number format.'], $this->messagesFrom(fn () => $this->createPatient($actor, ['identifiers' => [['identifier_type' => 'passport', 'issuing_country_code' => 'MY', 'value' => '-ABC']]])));
        $this->assertSame(['Choose IC number or Passport.'], $this->messagesFrom(fn () => $this->createPatient($actor, ['identifiers' => []])));
        $this->assertDatabaseCount('patients', 0);
    }

    public function test_staff_http_requests_report_missing_phone_and_identity_in_english(): void
    {
        $actor = $this->actor('ca');

        $this->actingAs($actor)->post('/patients', $this->patientAttributes(['mobile_phone' => null, 'identifiers' => null]))
            ->assertSessionHasErrors([
                'mobile_phone' => 'Phone number is required.',
                'identifiers' => 'Choose IC number or Passport.',
            ]);
        $this->assertDatabaseCount('patients', 0);
    }

    public function test_shared_services_and_the_public_qr_validator_answer_in_english(): void
    {
        $identity = app(PatientIdentityService::class);
        $phone = app(PatientPhoneNormalizer::class);

        $this->assertSame(['IC number must have 12 digits.'], $this->messagesFrom(fn () => $identity->normalizeIdentifier(['identifier_type' => 'nric', 'value' => '1234'])));
        $this->assertSame(['Passport issuing country is required.'], $this->messagesFrom(fn () => $identity->normalizeIdentifier(['identifier_type' => 'passport', 'value' => 'SYN-PASS'])));
        $this->assertSame(['IC or Passport number is required.'], $this->messagesFrom(fn () => $identity->normalizeIdentifier(['identifier_type' => 'nric'])));
        $this->assertSame(['Phone number is required.'], $this->messagesFrom(fn () => $phone->normalize(null, 'MY', true)));
        $this->assertSame(['Enter a valid phone number for the selected country.'], $this->messagesFrom(fn () => $phone->normalize('0991234567', 'MY', true)));

        $validator = app(PublicIntakePayloadValidator::class);
        $this->assertContains('Enter a valid phone number for the selected country.', $this->messagesFrom(fn () => $validator->validate($this->publicPayload(['mobile_phone' => '0991234567']), $this->organisation->id)));
        $this->assertContains('IC number must have 12 digits.', $this->messagesFrom(fn () => $validator->validate($this->publicPayload(['identifier_type' => 'nric', 'identifier_value' => '1234']), $this->organisation->id)));
    }

    public function test_staff_patient_edit_reports_phone_errors_in_english(): void
    {
        $actor = $this->actor('ca');
        $patient = $this->createPatient($actor);

        $this->assertSame(['Check the country code and phone number.'], $this->messagesFrom(fn () => app(PatientAdministrationService::class)->update($patient, $this->patientAttributes(['mobile_phone' => '+600123456789', 'lock_version' => 1]), $actor)));
        $this->assertSame(1, $patient->fresh()->lock_version);
    }

    /** @return array<string, mixed> */
    private function publicPayload(array $overrides = []): array
    {
        return [
            'submission_type' => 'patient',
            'full_name' => 'Synthetic Public Person',
            'date_of_birth' => '1990-01-01',
            'sex' => 'unknown',
            'nationality_code' => 'MY',
            'mobile_phone' => '+60123456789',
            'phone_country' => 'MY',
            'identifier_type' => 'passport',
            'identifier_value' => 'SYNREG2PUBLIC',
            'identifier_issuing_country_code' => 'MY',
            'visit_purpose' => 'doctor_illness',
            'chief_complaint' => 'Synthetic cough',
            'complaint_duration' => 'One day',
            'coverage_type' => 'self_pay',
            'panel_id' => null,
            'coverage_member_reference' => null,
            'guardian_name' => null,
            'guardian_relationship' => null,
            'guardian_contact_number' => null,
            'guardian_attestation' => false,
            'consent_confirmed' => true,
            'privacy_notice_version' => config('public-intake.privacy_notice_version'),
            ...$overrides,
        ];
    }
}
