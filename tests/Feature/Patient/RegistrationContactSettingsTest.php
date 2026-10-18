<?php

namespace Tests\Feature\Patient;

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Organisation\Models\PublicCheckInLink;
use App\Domain\Organisation\Services\PublicCheckInLinkService;
use App\Domain\Patient\Models\Patient;
use App\Domain\Patient\Models\PublicIntakeSession;
use App\Domain\Patient\Models\PublicPatientIntake;
use App\Domain\Patient\Models\RegistrationSetting;
use App\Domain\Patient\Services\PatientAdministrationService;
use App\Domain\Patient\Services\PublicIntakeReviewService;
use App\Domain\Patient\Services\PublicPatientIntakeService;
use App\Domain\Patient\Services\RegistrationSettingsService;
use App\Domain\Visit\Services\VisitRegistrationService;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Visit\VisitTestCase;

class RegistrationContactSettingsTest extends VisitTestCase
{
    private function command(array $extra = []): array
    {
        return ['expected_branch_id' => $this->branch->id, 'idempotency_key' => (string) Str::uuid(), 'lock_version' => 0,
            'require_date_of_birth' => false, 'require_nationality' => false, 'require_email' => false, 'require_address' => false, ...$extra];
    }

    private function configure(bool $required = true): void
    {
        $actor = $this->actor('director');
        $this->selectBranch($actor);
        app(RegistrationSettingsService::class)->save($actor, $this->command(['lock_version' => app(RegistrationSettingsService::class)->policy($actor->organisation_id)['lockVersion'], 'require_email' => $required, 'require_address' => $required]));
    }

    private function contact(): array
    {
        return ['email' => 'synthetic.contact@set04b.test', 'address_line_1' => '42 Synthetic Street', 'address_line_2' => '', 'city' => 'Synthetic City', 'state' => 'Synthetic State', 'postcode' => '50000', 'country_code' => 'MY'];
    }

    private function patientData(array $extra = []): array
    {
        return ['full_name' => 'Synthetic Contact Patient', 'date_of_birth' => '1990-01-01', 'sex' => 'unknown', 'mobile_phone' => '+60123456789', 'identifiers' => [['identifier_type' => 'passport', 'issuing_country_code' => 'MY', 'value' => 'SYN-'.Str::upper(Str::random(12))]], 'duplicate_override' => true, ...$this->contact(), ...$extra];
    }

    private function intakePayload(array $extra = []): array
    {
        return ['submission_type' => 'patient', 'full_name' => 'Synthetic SET04 Patient', 'date_of_birth' => '1990-01-01', 'sex' => 'unknown',
            'nationality_code' => '', 'mobile_phone' => '+60123456789', 'phone_country' => 'MY', 'identifier_type' => 'passport',
            'identifier_value' => 'SYN-'.Str::upper(Str::random(12)), 'identifier_issuing_country_code' => 'MY', 'visit_kind' => 'otc',
            'coverage_type' => 'self_pay', 'consent_confirmed' => true, 'privacy_notice_version' => config('public-intake.privacy_notice_version'), ...$this->contact(), ...$extra];
    }

    private function open(): PublicIntakeSession
    {
        $director = $this->actor('director');
        $this->selectBranch($director);
        $link = PublicCheckInLink::query()->where('branch_id', $this->branch->id)->where('is_active', true)->first();
        $link ??= app(PublicCheckInLinkService::class)->issue($director, $this->branch, 'Synthetic SET04b link')['link'];

        return app(PublicPatientIntakeService::class)->openSession($link)['session'];
    }

    public function test_optional_defaults_and_existing_policy_snapshots_do_not_require_contact(): void
    {
        $policy = app(RegistrationSettingsService::class)->policy($this->organisation->id);
        $this->assertFalse($policy['requireEmail']);
        $this->assertFalse($policy['requireAddress']);
        $session = $this->open();
        $session->forceFill(['registration_policy' => ['requireDateOfBirth' => false, 'requireNationality' => false, 'lockVersion' => 1]])->save();
        $this->configure();
        $stored = app(RegistrationSettingsService::class)->sessionPolicy($session);
        $this->assertFalse($stored['requireEmail']);
        $this->assertFalse($stored['requireAddress']);
        $payload = $this->intakePayload(array_fill_keys(array_keys($this->contact()), ''));
        app(PublicPatientIntakeService::class)->submit($session, $payload, true);
        $this->assertDatabaseCount('patients', 0);
    }

    public function test_settings_contact_save_retry_conflicts_stale_version_and_numeric_free_audit(): void
    {
        $actor = $this->actor('administrator');
        $this->selectBranch($actor);
        $data = $this->command(['require_email' => true, 'require_address' => true]);
        $this->post('/registration-settings', $data)->assertSessionHasNoErrors();
        $this->post('/registration-settings', $data)->assertSessionHasNoErrors();
        $this->assertDatabaseCount('registration_setting_commands', 1);
        $this->assertSame(['changed_fields' => ['require_email', 'require_address'], 'record_version' => 1], AuditLog::query()->where('event', 'registration_settings.updated')->sole()->metadata);
        $this->post('/registration-settings', [...$data, 'require_email' => false])->assertSessionHasErrors('settings');
        $this->post('/registration-settings', $this->command())->assertSessionHasErrors('lock_version');
        $this->assertTrue(RegistrationSetting::query()->sole()->require_email);
        $legacy = $this->command(['lock_version' => 1]);
        unset($legacy['require_email'], $legacy['require_address']);
        $this->post('/registration-settings', $legacy)->assertSessionHasNoErrors();
        $this->assertTrue(RegistrationSetting::query()->sole()->require_email);
        $this->assertTrue(RegistrationSetting::query()->sole()->require_address);
    }

    public function test_each_required_contact_field_is_enforced_in_patient_and_quick_http_and_domain_without_partial_records(): void
    {
        $this->configure();
        $ca = $this->actor('ca');
        $this->selectBranch($ca);
        foreach (['email', 'address_line_1', 'city', 'state', 'postcode', 'country_code'] as $field) {
            $data = $this->patientData([$field => '']);
            $this->post('/patients', $data)->assertSessionHasErrors($field);
            try {
                app(PatientAdministrationService::class)->create($ca, $data);
                $this->fail('Direct creation bypassed '.$field);
            } catch (ValidationException $e) {
                $this->assertArrayHasKey($field, $e->errors());
            }
            $quick = ['expected_branch_id' => $this->branch->id, 'idempotency_key' => (string) Str::uuid(), 'quick_patient' => $data, 'visit_type' => 'otc', 'priority' => 'normal', 'coverage_type' => 'self_pay'];
            $this->post('/registration', $quick)->assertSessionHasErrors('quick_patient.'.$field);
            try {
                app(VisitRegistrationService::class)->register($ca, $quick);
                $this->fail('Direct quick creation bypassed '.$field);
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('quick_patient.'.$field, $e->errors());
            }
        }
        $this->assertDatabaseCount('patients', 0);
        $data = $this->patientData();
        $this->post('/patients', $data)->assertSessionHasNoErrors();
        $this->assertDatabaseHas('patients', ['email' => $data['email'], 'address_line_2' => null]);
        $quick = ['expected_branch_id' => $this->branch->id, 'idempotency_key' => (string) Str::uuid(), 'quick_patient' => $this->patientData(), 'visit_type' => 'otc', 'priority' => 'normal', 'coverage_type' => 'self_pay'];
        $this->post('/registration', $quick)->assertSessionHasNoErrors();
        $this->assertDatabaseCount('patients', 2);
    }

    public function test_optional_values_still_validate_email_country_and_lengths_and_qr_checks_each_required_field(): void
    {
        $session = $this->open();
        $service = app(PublicPatientIntakeService::class);
        foreach ([['email', 'not-an-email'], ['email', str_repeat('x', 250).'@set04b.test'], ['address_line_1', str_repeat('x', 256)], ['address_line_2', str_repeat('x', 256)], ['city', str_repeat('x', 101)], ['state', str_repeat('x', 101)], ['postcode', str_repeat('x', 21)], ['country_code', '12']] as [$field, $value]) {
            try {
                $service->submit($session, $this->intakePayload([$field => $value]), true);
                $this->fail('Invalid optional '.$field.' accepted.');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey($field, $e->errors());
            }
        }
        $this->configure();
        $required = $this->open();
        foreach (['email', 'address_line_1', 'city', 'state', 'postcode', 'country_code'] as $field) {
            try {
                $service->submit($required, $this->intakePayload([$field => '']), true);
                $this->fail('Missing required '.$field.' accepted.');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey($field, $e->errors());
            }
        }
        $this->assertDatabaseCount('public_patient_intakes', 0);
        $intake = $service->submit($required, $this->intakePayload(), true);
        $this->assertNull($intake->encrypted_payload['patient']['address_line_2']);
    }

    public function test_contact_values_are_encrypted_reviewable_correctable_and_carried_into_new_patient_without_audit_or_flash_leaks(): void
    {
        $this->configure();
        $session = $this->open();
        $payload = $this->intakePayload();
        $intake = app(PublicPatientIntakeService::class)->submit($session, $payload, true);
        $raw = DB::table('public_patient_intakes')->value('encrypted_payload');
        $status = json_encode(app(PublicPatientIntakeService::class)->status($session));
        $this->assertStringNotContainsString($payload['email'], $status);
        $this->assertStringNotContainsString($payload['address_line_1'], $status);
        $decrypted = json_decode(Crypt::decryptString($raw), true, 512, JSON_THROW_ON_ERROR);
        foreach ($this->contact() as $field => $value) {
            $this->assertSame($value === '' ? null : $value, $decrypted['patient'][$field]);
            $this->assertStringNotContainsString('"'.$field.'":', $raw);
        }
        $this->assertStringNotContainsString($payload['email'], $raw);
        $this->assertStringNotContainsString($payload['address_line_1'], $raw);
        $ca = $this->actor('ca');
        $this->selectBranch($ca);
        $reviews = app(PublicIntakeReviewService::class);
        $detail = $reviews->detail($ca, $intake->public_id);
        $this->assertSame($payload['email'], $detail['fields']['email']);
        $this->assertSame($payload['address_line_1'], $detail['fields']['address_line_1']);
        $this->patch(route('registration-review.correct', $intake->public_id), [...$payload, 'email' => 'bad-email', 'lock_version' => $intake->lock_version])->assertSessionHasErrors('email')->assertSessionMissing('_old_input.email')->assertSessionMissing('_old_input.address_line_1');
        $corrected = $reviews->correct($ca, $intake->public_id, [...$payload, 'email' => 'corrected@set04b.test', 'address_line_1' => '43 Synthetic Corrected Street', 'lock_version' => $intake->lock_version]);
        $this->accept($ca, $corrected);
        $patient = Patient::query()->sole();
        $this->assertSame('corrected@set04b.test', $patient->email);
        $this->assertSame('43 Synthetic Corrected Street', $patient->address_line_1);
        $this->assertNull($patient->address_line_2);
        $audit = AuditLog::query()->get()->pluck('metadata')->toJson();
        foreach ([$payload['email'], $payload['address_line_1'], $patient->email, $patient->address_line_1] as $value) {
            $this->assertStringNotContainsString($value, $audit);
        }
        $this->selectBranch($this->actor('administrator'));
        $this->get(route('registration-review.show', $intake->public_id))->assertForbidden();
        $this->selectBranch($this->actor('ca', Branch::query()->where('organisation_id', $this->organisation->id)->whereKeyNot($this->branch->id)->firstOrFail()));
        $this->get(route('registration-review.show', $intake->public_id))->assertNotFound();
    }

    private function accept($ca, PublicPatientIntake $intake, ?string $patientNumber = null): void
    {
        app(PublicIntakeReviewService::class)->accept($ca, $intake->public_id, ['lock_version' => $intake->lock_version, 'idempotency_key' => (string) Str::uuid(), 'resolution' => $patientNumber === null ? 'create' : 'match', 'patient_number' => $patientNumber, 'duplicate_override' => true, 'priority' => 'normal', 'coverage_type' => 'self_pay', 'coverage_verified' => true]);
    }

    public function test_notice_is_session_bound_and_legacy_sessions_keep_old_notice_and_collection_scope(): void
    {
        $session = $this->open();
        $original = $session->privacy_notice_version;
        config(['public-intake.privacy_notice_version' => 'uat-draft-next-test-only']);
        $payload = $this->intakePayload(['privacy_notice_version' => $original]);
        $service = app(PublicPatientIntakeService::class);
        $intake = $service->submit($session, $payload, true);
        $this->assertSame($original, $intake->privacy_notice_version);
        $this->assertSame($intake->id, $service->submit($session->refresh(), $payload, true)->id);
        $legacy = $this->open();
        $legacy->forceFill(['privacy_notice_version' => null, 'registration_policy' => null])->save();
        $legacyPayload = $this->intakePayload(['privacy_notice_version' => PublicPatientIntakeService::LEGACY_PRIVACY_NOTICE_VERSION, ...array_fill_keys(array_keys($this->contact()), '')]);
        $this->assertFalse($service->collectsContactDetails($legacy));
        $this->assertSame(PublicPatientIntakeService::LEGACY_PRIVACY_NOTICE_VERSION, $service->privacyVersion($legacy));
        try {
            $service->submit($legacy, [...$legacyPayload, 'email' => 'not-consented@set04b.test'], true);
            $this->fail('Old notice allowed new data collection.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('email', $e->errors());
        }
        $service->submit($legacy, $legacyPayload, true);
        $this->configure();
        $ca = $this->actor('ca');
        $this->selectBranch($ca);
        $legacyIntake = PublicPatientIntake::query()->where('public_intake_session_id', $legacy->id)->sole();
        $corrected = app(PublicIntakeReviewService::class)->correct($ca, $legacyIntake->public_id, [...$legacyPayload, 'lock_version' => $legacyIntake->lock_version]);
        $this->accept($ca, $corrected);
        $this->assertNull(Patient::query()->sole()->email);
    }

    public function test_existing_patients_still_register_and_matching_does_not_silently_overwrite_contacts(): void
    {
        $ca = $this->actor('ca');
        $this->selectBranch($ca);
        $patient = $this->patient($ca);
        $this->configure();
        $this->selectBranch($ca);
        $this->register($ca, $patient);
        $session = $this->open();
        $intake = app(PublicPatientIntakeService::class)->submit($session, $this->intakePayload(), true);
        $this->selectBranch($ca);
        app(PublicIntakeReviewService::class)->accept($ca, $intake->public_id, ['lock_version' => $intake->lock_version, 'idempotency_key' => (string) Str::uuid(), 'resolution' => 'match', 'patient_number' => $patient->patient_number, 'priority' => 'normal', 'coverage_type' => 'self_pay', 'coverage_verified' => true, 'confirm_repeat' => true]);
        $this->assertNull($patient->refresh()->email);
        $this->assertNull($patient->address_line_1);
        $this->assertSame(1, Patient::query()->count());
    }

    public function test_contact_payload_follows_existing_thirty_day_purge_and_no_patient_is_created_before_acceptance(): void
    {
        $session = $this->open();
        $intake = app(PublicPatientIntakeService::class)->submit($session, $this->intakePayload(), true);
        $this->assertDatabaseCount('patients', 0);
        $this->assertSame(30, (int) $intake->submitted_at->diffInDays($intake->payload_purge_at));
        $intake->forceFill(['expires_at' => now()->subDay(), 'payload_purge_at' => now()->subDay()])->save();
        $this->artisan('public-intakes:cleanup')->assertSuccessful();
        $this->assertNull($intake->refresh()->encrypted_payload);
        $this->assertNotNull($intake->payload_purged_at);
    }
}
