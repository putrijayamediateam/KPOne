<?php

namespace Tests\Feature\Patient;

use App\Domain\Organisation\Models\Organisation;
use App\Domain\Patient\Services\PatientAdministrationService;
use App\Domain\Patient\Services\PatientDirectoryService;
use App\Domain\Patient\Services\PatientIdentityService;
use App\Domain\Patient\Services\PatientPhoneNormalizer;
use Illuminate\Validation\ValidationException;

class RegistrationValidationTest extends PatientTestCase
{
    public function test_review_vectors_preserve_passport_unicode_compatibility_and_phone_region_parity(): void
    {
        $identity = app(PatientIdentityService::class);
        foreach ([["ABC\u{0085}123", 'MY'], ['ABC123', 'ＭＹ'], ["\0ABC123\0", 'MY']] as [$value, $issuer]) {
            $normalized = $identity->normalizeIdentifier(['identifier_type' => 'passport', 'value' => $value, 'issuing_country_code' => $issuer]);
            $this->assertSame('ABC123', $normalized['normalized_value']);
            $this->assertSame('MY', $normalized['issuing_country_code']);
        }
        foreach (["ABC\u{FEFF}123", "\f\0ABC123"] as $value) {
            try {
                $identity->normalizeIdentifier(['identifier_type' => 'passport', 'value' => $value, 'issuing_country_code' => 'MY']);
                $this->fail('Unsupported passport characters were silently removed.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('value', $exception->errors());
            }
        }
        $phone = app(PatientPhoneNormalizer::class);
        foreach ([['01112345678', 'MY', '+601112345678'], ['81234567', 'SG', '+6581234567'], ['2025550123', 'US', '+12025550123'], ['0412345678', 'AU', '+61412345678']] as [$value, $country, $expected]) {
            $this->assertSame($expected, $phone->normalize($value, $country, true));
        }
        foreach (['0991234567', '0123456789012'] as $value) {
            try {
                $phone->normalize($value, 'MY', true);
                $this->fail('Invalid Malaysian prefix or length accepted.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('mobile_phone', $exception->errors());
            }
        }
    }

    public function test_unchanged_legacy_phone_is_retained_but_changed_invalid_phone_is_rejected(): void
    {
        $actor = $this->actor('ca');
        $patient = $this->legacyPatient($actor);
        $patient->forceFill(['mobile_phone' => '123'])->save();
        $updated = app(PatientAdministrationService::class)->update($patient, $this->patientAttributes(['mobile_phone' => '123', 'lock_version' => 1]), $actor);
        $this->assertSame('123', $updated->mobile_phone);
        $this->assertSame(2, $updated->lock_version);
        $this->expectException(ValidationException::class);
        app(PatientAdministrationService::class)->update($updated, $this->patientAttributes(['mobile_phone' => '124', 'lock_version' => 2]), $actor);
    }

    public function test_identifier_uniqueness_remains_organisation_type_and_issuer_scoped(): void
    {
        $actor = $this->actor('ca');
        $other = Organisation::query()->create(['code' => 'SYN_R1A_OTHER', 'name' => 'Synthetic R1A Organisation']);
        $outsider = $this->actor('ca', $other);
        foreach ([['identifier_type' => 'nric', 'value' => '000101011234'], ['identifier_type' => 'passport', 'issuing_country_code' => 'MY', 'value' => 'SYN-PASS']] as $identifier) {
            $first = $this->createPatient($actor, ['identifiers' => [$identifier]]);
            $second = $this->createPatient($outsider, ['identifiers' => [$identifier]]);
            $this->assertNotSame($first->organisation_id, $second->organisation_id);
            $this->assertSame($first->identifiers()->sole()->normalized_value, $second->identifiers()->sole()->normalized_value);
        }
        $distinctIssuer = $this->createPatient($actor, ['identifiers' => [['identifier_type' => 'passport', 'issuing_country_code' => 'GB', 'value' => 'SYN-PASS']]]);
        $this->assertSame('GB', $distinctIssuer->identifiers()->sole()->issuing_country_code);
        $this->assertDatabaseCount('patients', 5);
    }

    public function test_phone_metadata_normalization_and_search_agree(): void
    {
        $normalizer = app(PatientPhoneNormalizer::class);
        foreach (['+600123456789', '+4402079460018'] as $ambiguous) {
            try {
                $normalizer->normalize($ambiguous, 'MY', true);
                $this->fail('The parser must not silently remove international digits.');
            } catch (ValidationException $exception) {
                $this->assertSame(['Semak kod negara dan nombor telefon.'], $exception->errors()['mobile_phone']);
            }
        }
        foreach ([['0123456789', 'MY', '+60123456789'], [' (012) 345-6789 ', 'MY', '+60123456789'], ['+60 12-3456789', 'MY', '+60123456789'], ['020 7946 0018', 'GB', '+442079460018'], ['+44 20 7946 0018', 'MY', '+442079460018']] as [$raw, $country, $expected]) {
            $this->assertSame($expected, $normalizer->normalize($raw, $country, true));
            $this->assertSame($expected, app(PatientIdentityService::class)->normalizeSearchPhone($expected));
        }
    }

    public function test_direct_creation_cannot_bypass_required_phone_or_identity(): void
    {
        $actor = $this->actor('ca');
        foreach ([null, '', ' ', 123456789, [], '0123', '0123456789 ext 1', '0123456789a', "0123456789\n", "\t0123456789", '+60+123456789', '012.3456789'] as $value) {
            try {
                $this->createPatient($actor, ['mobile_phone' => $value]);
                $this->fail('Invalid phone accepted.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('mobile_phone', $exception->errors());
            }
        }
        foreach ([null, [], [['identifier_type' => 'nric', 'value' => '']], [['identifier_type' => 'nric', 'value' => '000101011234'], ['identifier_type' => 'passport', 'issuing_country_code' => 'MY', 'value' => 'SYN-A']]] as $identifiers) {
            try {
                $this->createPatient($actor, ['identifiers' => $identifiers]);
                $this->fail('Missing or ambiguous identification accepted.');
            } catch (ValidationException $exception) {
                $this->assertStringStartsWith('identifiers', array_key_first($exception->errors()));
            }
        }
        $this->assertDatabaseCount('patients', 0);
    }

    public function test_ic_is_ascii_string_and_passport_retains_existing_compatibility(): void
    {
        $identity = app(PatientIdentityService::class);
        $this->assertSame('000101011234', $identity->normalizeIdentifier(['identifier_type' => 'nric', 'value' => '000101-01-1234'])['normalized_value']);
        $this->assertSame('SYN-P123', $identity->normalizeIdentifier(['identifier_type' => 'passport', 'issuing_country_code' => 'my', 'value' => ' syn - p123 '])['normalized_value']);
        foreach (['123', '1234567890123', '00010101123a', 101011234, "000101011234\n", '０００１０１０１１２３４'] as $value) {
            try {
                $identity->normalizeIdentifier(['identifier_type' => 'nric', 'value' => $value]);
                $this->fail('Invalid IC accepted.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('value', $exception->errors());
            }
        }
        foreach ([['value' => 'AB', 'issuing_country_code' => 'MY'], ['value' => 'ABC!', 'issuing_country_code' => 'MY'], ['value' => str_repeat('A', 33), 'issuing_country_code' => 'MY'], ['value' => 'SYN-P123']] as $invalid) {
            try {
                $identity->normalizeIdentifier(['identifier_type' => 'passport', ...$invalid]);
                $this->fail('Invalid passport accepted.');
            } catch (ValidationException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_shared_phone_is_not_unique_patient_identity(): void
    {
        $actor = $this->actor('ca');
        $first = $this->createPatient($actor, ['full_name' => 'Synthetic Family A', 'duplicate_override' => false, 'date_of_birth' => '1990-01-01']);
        $second = $this->createPatient($actor, ['full_name' => 'Synthetic Family B', 'duplicate_override' => false, 'date_of_birth' => '1991-02-02']);
        $this->assertSame($first->mobile_phone, $second->mobile_phone);
        $this->assertNotSame($first->id, $second->id);
    }

    public function test_http_rejects_missing_values_and_does_not_trim_away_phone_controls(): void
    {
        $actor = $this->actor('ca');
        $this->actingAs($actor)->post(route('patients.store'), $this->patientAttributes(['mobile_phone' => '', 'identifiers' => []]))->assertSessionHasErrors(['mobile_phone', 'identifiers']);
        $this->post(route('patients.store'), $this->patientAttributes(['mobile_phone' => "0123456789\n"]))->assertSessionHasErrors('mobile_phone');
        $this->post(route('patients.store'), $this->patientAttributes(['identifiers' => [['identifier_type' => 'nric', 'value' => "000101011234\n"]]]))->assertSessionHasErrors('identifiers.0.value');
        $this->assertDatabaseCount('patients', 0);
    }

    public function test_formatted_ic_creation_search_and_changed_phone_share_normalization(): void
    {
        $actor = $this->actor('ca');
        $patient = $this->createPatient($actor, ['identifiers' => [['identifier_type' => 'nric', 'value' => '000101-01-1234']]]);
        $rows = app(PatientDirectoryService::class)->search($actor, ['search_type' => 'nric', 'query' => '000101 01 1234']);
        $this->assertSame(1, $rows['total']);
        $this->assertSame('000101011234', $patient->identifiers()->sole()->normalized_value);
        $updated = app(PatientAdministrationService::class)->update($patient, $this->patientAttributes(['mobile_phone' => '020 7946 0018', 'phone_country' => 'GB', 'lock_version' => 1]), $actor);
        $this->assertSame('+442079460018', $updated->mobile_phone);
        $this->expectException(ValidationException::class);
        app(PatientAdministrationService::class)->update($updated, $this->patientAttributes(['mobile_phone' => '', 'lock_version' => 2]), $actor);
    }

    public function test_legacy_missing_contact_and_identifier_allow_demographic_edit_but_new_phone_must_validate(): void
    {
        $actor = $this->actor('ca');
        $patient = $this->legacyPatient($actor);
        $updated = app(PatientAdministrationService::class)->update($patient, $this->patientAttributes(['mobile_phone' => '', 'lock_version' => 1]), $actor);
        $this->assertNull($updated->mobile_phone);
        $this->assertSame(0, $updated->identifiers()->count());
        $this->assertSame('female', $updated->sex);
        $this->expectException(ValidationException::class);
        app(PatientAdministrationService::class)->update($updated, $this->patientAttributes(['mobile_phone' => '123', 'lock_version' => 2]), $actor);
    }
}
