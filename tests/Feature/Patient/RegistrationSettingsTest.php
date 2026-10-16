<?php

namespace Tests\Feature\Patient;

use App\Domain\Access\PermissionCatalogue;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Organisation\Models\Organisation;
use App\Domain\Organisation\Services\PublicCheckInLinkService;
use App\Domain\Patient\Models\PublicIntakeSession;
use App\Domain\Patient\Models\PublicPatientIntake;
use App\Domain\Patient\Models\RegistrationSetting;
use App\Domain\Patient\Services\PatientAdministrationService;
use App\Domain\Patient\Services\PublicIntakeReviewService;
use App\Domain\Patient\Services\PublicPatientIntakeService;
use App\Domain\Patient\Services\RegistrationSettingsService;
use App\Domain\Visit\Services\VisitRegistrationService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Visit\VisitTestCase;

class RegistrationSettingsTest extends VisitTestCase
{
    private function command(array $extra = []): array
    {
        return ['expected_branch_id' => $this->branch->id, 'idempotency_key' => (string) Str::uuid(), 'lock_version' => 0,
            'require_date_of_birth' => false, 'require_nationality' => false, ...$extra];
    }

    private function configure(bool $dob = true, bool $nationality = true): void
    {
        $actor = $this->actor('director');
        $this->selectBranch($actor);
        app(RegistrationSettingsService::class)->save($actor, $this->command(['lock_version' => app(RegistrationSettingsService::class)->policy($actor->organisation_id)['lockVersion'],
            'require_date_of_birth' => $dob, 'require_nationality' => $nationality]));
    }

    private function intakePayload(array $extra = []): array
    {
        return ['submission_type' => 'patient', 'full_name' => 'Synthetic SET04 Patient', 'date_of_birth' => '1990-01-01', 'sex' => 'unknown',
            'nationality_code' => '', 'mobile_phone' => '+60123456789', 'phone_country' => 'MY', 'identifier_type' => 'passport',
            'identifier_value' => 'SYN-'.Str::upper(Str::random(12)), 'identifier_issuing_country_code' => 'MY', 'visit_kind' => 'otc',
            'coverage_type' => 'self_pay', 'consent_confirmed' => true, 'privacy_notice_version' => config('public-intake.privacy_notice_version'), ...$extra];
    }

    private function open(): PublicIntakeSession
    {
        $director = $this->actor('director');
        $this->selectBranch($director);
        $issued = app(PublicCheckInLinkService::class)->issue($director, $this->branch, 'Synthetic SET04 link');

        return app(PublicPatientIntakeService::class)->openSession($issued['link'])['session'];
    }

    public function test_permission_matrix_and_patient_free_settings_and_hub(): void
    {
        foreach (array_keys(PermissionCatalogue::roles()) as $role) {
            $actor = $this->actor($role);
            $this->selectBranch($actor);
            $allowed = in_array($role, ['director', 'administrator', 'finance_officer', 'panel_officer'], true);
            DB::flushQueryLog();
            DB::enableQueryLog();
            $response = $this->get('/registration-settings');
            $queries = DB::getQueryLog();
            DB::disableQueryLog();
            if ($allowed) {
                $response->assertOk()->assertInertia(fn (Assert $p) => $p->component('RegistrationSettings/Index')->missing('patients')->missing('visits')->where('requirements.requireNationality', false));
                foreach ($queries as $query) {
                    $patientTable = preg_match('/from\s+"?(patients|visits|public_patient_intakes)"?\b/i', $query['query']) === 1;
                    if ($role === 'administrator') {
                        $this->assertFalse($patientTable, 'Administrator must not query patient tables.');
                    } elseif ($patientTable) {
                        $this->assertMatchesRegularExpression('/select count\(\*\) as "aggregate"/i', $query['query']);
                    }
                }
                $this->post('/registration-settings', $this->command(['lock_version' => app(RegistrationSettingsService::class)->policy($actor->organisation_id)['lockVersion']]))->assertSessionHasNoErrors()->assertRedirect('/registration-settings');
            } else {
                $response->assertForbidden();
                $this->post('/registration-settings', $this->command())->assertForbidden();
            }
        }
    }

    public function test_save_retries_stale_versions_and_values_free_audit(): void
    {
        $actor = $this->actor('administrator');
        $this->selectBranch($actor);
        $data = $this->command(['require_date_of_birth' => true, 'require_nationality' => true]);
        $this->post('/registration-settings', $data)->assertSessionHasNoErrors();
        $this->post('/registration-settings', $data)->assertSessionHasNoErrors();
        $this->assertDatabaseCount('registration_settings', 1);
        $this->assertDatabaseCount('registration_setting_commands', 1);
        $this->assertSame(1, AuditLog::query()->where('event', 'registration_settings.updated')->count());
        $this->assertSame(['changed_fields' => ['require_date_of_birth', 'require_nationality'], 'record_version' => 1], AuditLog::query()->where('event', 'registration_settings.updated')->sole()->metadata);
        $this->post('/registration-settings', array_replace($data, ['require_nationality' => false]))->assertSessionHasErrors('settings');
        $this->post('/registration-settings', $this->command())->assertSessionHasErrors('lock_version');
        $this->assertTrue(RegistrationSetting::query()->sole()->require_nationality);
    }

    public function test_scope_permission_revocation_and_organisation_wide_policy(): void
    {
        $actor = $this->actor('ca');
        $actor->givePermissionTo(RegistrationSettingsService::PERMISSION);
        $this->selectBranch($actor);
        $this->post('/registration-settings', $this->command(['require_nationality' => true]))->assertSessionHasNoErrors();
        $other = Branch::query()->where('organisation_id', $actor->organisation_id)->whereKeyNot($this->branch->id)->firstOrFail();
        $this->post('/registration-settings', $this->command(['expected_branch_id' => $other->id, 'lock_version' => 1]))->assertSessionHasErrors('expected_branch_id');
        $otherActor = $this->actor('finance_officer', $other);
        $this->selectBranch($otherActor, $other);
        $this->get('/registration-settings')->assertOk()->assertInertia(fn (Assert $p) => $p->where('requirements.requireNationality', true));
        $foreign = Organisation::query()->create(['code' => 'SYN_SET04', 'name' => 'Synthetic Other Organisation']);
        $this->assertFalse(app(RegistrationSettingsService::class)->policy($foreign->id)['requireNationality']);
        $actor->revokePermissionTo(RegistrationSettingsService::PERMISSION);
        $this->selectBranch($actor);
        $this->get('/registration-settings')->assertForbidden();
    }

    public function test_default_and_required_patient_creation_and_legacy_edit(): void
    {
        $ca = $this->actor('ca');
        $this->selectBranch($ca);
        $legacy = $this->patient($ca, ['date_of_birth' => null, 'nationality_code' => null]);
        $this->assertNull($legacy->date_of_birth);
        $this->configure();
        $this->selectBranch($ca);
        $this->get('/patients/create')->assertOk()->assertInertia(fn (Assert $p) => $p->where('requirements.requireNationality', true)->where('requirements.requireDateOfBirth', true));
        $this->get('/registration/create')->assertOk()->assertInertia(fn (Assert $p) => $p->where('requirements.requireNationality', true));
        try {
            $this->patient($ca, ['date_of_birth' => null, 'nationality_code' => null]);
            $this->fail('Domain creation bypassed requirements.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('nationality_code', $e->errors());
            $this->assertArrayHasKey('date_of_birth', $e->errors());
        }
        $data = ['full_name' => 'Synthetic Required Patient', 'date_of_birth' => '1990-01-01', 'sex' => 'unknown', 'nationality_code' => 'SG', 'mobile_phone' => '+60123456789',
            'identifiers' => [['identifier_type' => 'passport', 'issuing_country_code' => 'SG', 'value' => 'SYN-SET04-HTTP']], 'duplicate_override' => true];
        $this->post('/patients', array_replace($data, ['nationality_code' => '']))->assertSessionHasErrors('nationality_code');
        $this->post('/patients', $data)->assertSessionHasNoErrors();
        app(PatientAdministrationService::class)->update($legacy, ['full_name' => $legacy->full_name, 'sex' => 'unknown', 'lock_version' => $legacy->lock_version, 'mobile_phone' => $legacy->mobile_phone], $ca);
        $this->assertNull($legacy->refresh()->date_of_birth);
        $this->register($ca, $legacy);
    }

    public function test_quick_create_http_and_domain_require_visible_nationality_without_partial_records(): void
    {
        $this->configure();
        $ca = $this->actor('ca');
        $this->selectBranch($ca);
        $quick = ['full_name' => 'Synthetic Quick SET04', 'date_of_birth' => '1990-01-01', 'sex' => 'unknown', 'mobile_phone' => '+60123456789',
            'identifiers' => [['identifier_type' => 'passport', 'issuing_country_code' => 'MY', 'value' => 'SYN-SET04-QUICK']], 'nationality_code' => ''];
        $data = $this->command(['quick_patient' => $quick, 'visit_type' => 'otc', 'priority' => 'normal', 'coverage_type' => 'self_pay']);
        unset($data['lock_version']);
        $this->post('/registration', $data)->assertSessionHasErrors('quick_patient.nationality_code');
        try {
            app(VisitRegistrationService::class)->register($ca, $data);
            $this->fail('Direct quick create bypassed requirements.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('quick_patient.nationality_code', $e->errors());
        }
        $this->assertDatabaseCount('patients', 0);
        $data['quick_patient']['nationality_code'] = 'MY';
        $this->post('/registration', $data)->assertSessionHasNoErrors();
        $this->assertDatabaseHas('patients', ['nationality_code' => 'MY']);
    }

    public function test_qr_policy_survives_settings_change_correction_acceptance_and_retry(): void
    {
        $session = $this->open();
        $payload = $this->intakePayload();
        $this->configure();
        $service = app(PublicPatientIntakeService::class);
        $intake = $service->submit($session, $payload, true);
        $this->assertNull($intake->encrypted_payload['patient']['nationality_code']);
        $this->assertSame($intake->id, $service->submit($session->refresh(), $payload, true)->id);
        $ca = $this->actor('ca');
        $this->selectBranch($ca);
        $corrected = app(PublicIntakeReviewService::class)->correct($ca, $intake->public_id, [...$payload, 'lock_version' => $intake->lock_version]);
        $result = app(PublicIntakeReviewService::class)->accept($ca, $intake->public_id, ['lock_version' => $corrected->lock_version, 'idempotency_key' => (string) Str::uuid(),
            'resolution' => 'create', 'duplicate_override' => true, 'priority' => 'normal', 'coverage_type' => 'self_pay', 'coverage_verified' => true]);
        $this->assertSame(PublicPatientIntake::STATUS_ACCEPTED, $intake->refresh()->status);
        $this->assertNull($intake->encrypted_payload['patient']['nationality_code']);
        $this->assertNotEmpty($result);
    }

    public function test_new_qr_session_requires_nationality_but_always_keeps_dob_and_guardian_rules(): void
    {
        $this->configure();
        $session = $this->open();
        $service = app(PublicPatientIntakeService::class);
        foreach ([['nationality_code' => ''], ['nationality_code' => 'MY', 'date_of_birth' => ''], ['nationality_code' => 'MY', 'date_of_birth' => now()->subYears(10)->toDateString()]] as $extra) {
            try {
                $service->submit($session, $this->intakePayload($extra), true);
                $this->fail('Public safety/required field bypass.');
            } catch (ValidationException $e) {
                $this->assertNotEmpty($e->errors());
            }
        }
        $intake = $service->submit($session, $this->intakePayload(['nationality_code' => 'SG']), true);
        $this->assertSame('SG', $intake->encrypted_payload['patient']['nationality_code']);
        $this->assertDatabaseCount('patients', 0);
    }

    public function test_pre_existing_sessions_keep_optional_policy_and_settings_version_is_database_enforced(): void
    {
        $session = $this->open();
        $session->forceFill(['registration_policy' => null])->save();
        $this->configure();
        $intake = app(PublicPatientIntakeService::class)->submit($session, $this->intakePayload(), true);
        $this->assertNotNull($intake);
        $this->expectException(QueryException::class);
        DB::table('registration_settings')->update(['lock_version' => 0]);
    }
}
