<?php

namespace Tests\Feature\Patient;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Organisation\Services\PublicCheckInLinkService;
use App\Domain\Patient\Models\PublicPatientIntake;
use App\Domain\Patient\Services\PatientAdministrationService;
use App\Domain\Patient\Services\PatientGuardianService;
use App\Domain\Patient\Services\PublicIntakeReviewService;
use App\Domain\Queue\Display\QueueDisplayFeedService;
use App\Domain\Visit\Billing\Services\BillingDirectoryService;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Feature\Visit\VisitTestCase;
use Tests\Support\GuardianFixture;

class GuardianIntakeTest extends VisitTestCase
{
    private function incoming(): PublicPatientIntake
    {
        $director = $this->actor('director');
        $this->selectBranch($director);
        $link = app(PublicCheckInLinkService::class)->issue($director, $this->branch, 'Synthetic guardian intake');
        $response = $this->postJson(route('public-intake.exchange'), ['link_token' => $link['rawToken']])->assertCreated();
        $this->withCookies([config('public-intake.submission_cookie') => $response->getCookie(config('public-intake.submission_cookie'))->getValue(), config('public-intake.status_cookie') => $response->getCookie(config('public-intake.status_cookie'))->getValue()])->withCredentials();
        $this->postJson(route('public-intake.submit'), ['submission_type' => 'guardian', 'full_name' => 'Synthetic child only guardian phone', 'date_of_birth' => '2015-03-03', 'sex' => 'unknown', 'nationality_code' => 'MY', 'mobile_phone' => '', 'phone_country' => 'US', 'identifier_type' => 'passport', 'identifier_value' => 'SYN-GUARD-'.Str::random(8), 'identifier_issuing_country_code' => 'MY', 'visit_kind' => 'otc', 'coverage_type' => 'self_pay', 'guardian_name' => 'Synthetic guardian sentinel', 'guardian_relationship' => 'mother', 'guardian_contact_number' => '+12025550120', 'guardian_attestation' => true, 'consent_confirmed' => true, 'privacy_notice_version' => config('public-intake.privacy_notice_version')])->assertCreated();

        return PublicPatientIntake::query()->latest('id')->firstOrFail();
    }

    private function acceptance(PublicPatientIntake $intake, array $extra = []): array
    {
        return ['lock_version' => $intake->lock_version, 'idempotency_key' => (string) Str::uuid(), 'resolution' => 'create', 'duplicate_override' => true, 'priority' => 'normal', 'coverage_type' => 'self_pay', 'coverage_verified' => true, ...$extra];
    }

    public function test_minor_with_only_guardian_phone_accepts_persists_after_purge_and_never_enters_status_tv_or_audit(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 27)->setTime(12, 0));
        $intake = $this->incoming();
        $ca = $this->actor('ca');
        $this->selectBranch($ca);
        $data = $this->acceptance($intake);
        $result = app(PublicIntakeReviewService::class)->accept($ca, $intake->public_id, $data);
        $patient = $result['patient'];
        $this->assertNull($patient->mobile_phone);
        $this->assertSame('Synthetic guardian sentinel', app(PatientGuardianService::class)->contact($patient)['full_name']);
        $this->assertSame($patient->id, app(PublicIntakeReviewService::class)->accept($ca, $intake->public_id, $data)['patient']->id);
        $status = $this->get(route('public-intake.status'))->assertOk()->getContent();
        $billing = json_encode(app(BillingDirectoryService::class)->detail($ca, $result['visit'], printing: true));
        $tv = json_encode(app(QueueDisplayFeedService::class)->feed($this->branch));
        $audit = json_encode(AuditLog::query()->pluck('metadata'));
        foreach (['Synthetic guardian sentinel', '+12025550120', 'guardian@kpone.test'] as $value) {
            $this->assertStringNotContainsString($value, $status);
            $this->assertStringNotContainsString($value, $tv);
            $this->assertStringNotContainsString($value, $audit);
            $this->assertStringNotContainsString($value, $billing);
        }
        $this->travel(31)->days();
        $this->artisan('public-intakes:cleanup')->assertSuccessful();
        $this->assertNull($intake->fresh()->encrypted_payload);
        $this->assertSame('Synthetic guardian sentinel', app(PatientGuardianService::class)->contact($patient)['full_name']);
    }

    public function test_match_shows_both_and_requires_explicit_choice_without_overwriting_own_contact(): void
    {
        $intake = $this->incoming();
        $ca = $this->actor('ca');
        $this->selectBranch($ca);
        $patient = app(PatientAdministrationService::class)->create($ca, ['full_name' => 'Synthetic child only guardian phone', 'date_of_birth' => '2015-03-03', 'sex' => 'unknown', 'mobile_phone' => '+12025550121', 'identifiers' => [['identifier_type' => 'passport', 'issuing_country_code' => 'MY', 'value' => $intake->encrypted_payload['patient']['identifiers'][0]['value']]], 'duplicate_override' => true, 'guardian' => GuardianFixture::contact()]);
        $detail = app(PublicIntakeReviewService::class)->detail($ca, $intake->public_id);
        $this->assertSame('Synthetic guardian sentinel', $detail['fields']['guardian_name']);
        $this->assertArrayNotHasKey('guardian', $detail['duplicateCandidates'][0]);
        $selected = app(PublicIntakeReviewService::class)->guardianForMatch($ca, $intake->public_id, $patient->patient_number);
        $this->assertSame('Synthetic Guardian', $selected['guardian']['full_name']);
        $this->getJson(route('registration-review.guardian-contact', $intake->public_id).'?patient_number='.$patient->patient_number)->assertOk()->assertJsonPath('guardian.full_name', 'Synthetic Guardian')->assertHeader('Cache-Control', 'max-age=0, must-revalidate, no-store, private');
        $data = $this->acceptance($intake, ['resolution' => 'match', 'patient_number' => $patient->patient_number]);
        try {
            app(PublicIntakeReviewService::class)->accept($ca, $intake->public_id, $data);
            $this->fail('Silent overwrite accepted');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('guardian_choice', $e->errors());
        }
        $this->assertSame('Synthetic Guardian', app(PatientGuardianService::class)->contact($patient)['full_name']);
        app(PublicIntakeReviewService::class)->accept($ca, $intake->public_id, [...$data, 'guardian_choice' => 'keep', 'guardian_lock_version' => 1]);
        $this->assertSame('Synthetic Guardian', app(PatientGuardianService::class)->contact($patient)['full_name']);
        $this->assertSame('+12025550121', $patient->fresh()->mobile_phone);
        $this->assertDatabaseHas('audit_logs', ['event' => 'public_intake.guardian_kept']);
    }

    public function test_keep_choice_audit_failure_rolls_back_acceptance(): void
    {
        $intake = $this->incoming();
        $ca = $this->actor('ca');
        $this->selectBranch($ca);
        $patient = app(PatientAdministrationService::class)->create($ca, ['full_name' => 'Synthetic audit rollback child', 'date_of_birth' => '2015-03-03', 'sex' => 'unknown', 'mobile_phone' => '+12025550121', 'identifiers' => [['identifier_type' => 'passport', 'issuing_country_code' => 'MY', 'value' => 'SYN-AUDIT-GUARD']], 'guardian' => GuardianFixture::contact()]);
        $before = $intake->getAttributes();
        $this->mock(AuditRecorder::class)->shouldReceive('record')->andReturn(null);
        try {
            app(PublicIntakeReviewService::class)->accept($ca, $intake->public_id, $this->acceptance($intake, ['resolution' => 'match', 'patient_number' => $patient->patient_number, 'guardian_choice' => 'keep', 'guardian_lock_version' => 1]));
            $this->fail('Unaudited guardian decision accepted');
        } catch (HttpException $e) {
            $this->assertSame(503, $e->getStatusCode());
        }
        $this->assertSame($before, $intake->fresh()->getAttributes());
        $this->assertDatabaseCount('visits', 0);
        $this->assertSame(1, app(PatientGuardianService::class)->contact($patient)['lock_version']);
    }

    public function test_explicit_incoming_choice_is_versioned_and_does_not_overwrite_own_phone(): void
    {
        $intake = $this->incoming();
        $ca = $this->actor('ca');
        $this->selectBranch($ca);
        $patient = app(PatientAdministrationService::class)->create($ca, ['full_name' => 'Synthetic existing child', 'date_of_birth' => '2015-03-03', 'sex' => 'unknown', 'mobile_phone' => '+12025550121', 'identifiers' => [['identifier_type' => 'passport', 'issuing_country_code' => 'MY', 'value' => 'SYN-REPLACE-GUARD']], 'guardian' => GuardianFixture::contact()]);
        $data = $this->acceptance($intake, ['resolution' => 'match', 'patient_number' => $patient->patient_number, 'guardian_choice' => 'use_incoming', 'guardian_relationship' => 'mother', 'guardian_lock_version' => 0]);
        try {
            app(PublicIntakeReviewService::class)->accept($ca, $intake->public_id, $data);
            $this->fail('Stale QR guardian accepted');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('guardian.lock_version', $e->errors());
        }
        app(PublicIntakeReviewService::class)->accept($ca, $intake->public_id, [...$data, 'guardian_lock_version' => 1]);
        $this->assertSame('Synthetic guardian sentinel', app(PatientGuardianService::class)->contact($patient)['full_name']);
        $this->assertSame(2, app(PatientGuardianService::class)->contact($patient)['lock_version']);
        $this->assertSame('+12025550121', $patient->fresh()->mobile_phone);
    }
}
