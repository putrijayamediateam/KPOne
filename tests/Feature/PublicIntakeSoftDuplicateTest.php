<?php

namespace Tests\Feature;

use App\Domain\Organisation\Services\PublicCheckInLinkService;
use App\Domain\Patient\Models\PublicPatientIntake;
use App\Domain\Patient\Services\PatientAdministrationService;
use App\Domain\Visit\Services\VisitReasonService;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Visit\VisitTestCase;

/**
 * A soft duplicate (same name and date of birth, or name and phone, or date of birth and phone) that Accept refuses on
 * must also be listed on the review page, so the person sees the record the refusal is about.
 */
class PublicIntakeSoftDuplicateTest extends VisitTestCase
{
    private static int $ip = 80;

    public function test_a_soft_match_that_accept_refuses_is_also_listed_on_the_review_page(): void
    {
        $intake = $this->submittedIntake('Synthetic Soft Match', '1990-01-01', '+60123456711');

        $reviewer = $this->actor('ca');
        $this->selectBranch($reviewer);
        $existing = app(PatientAdministrationService::class)->create($reviewer, [
            'full_name' => 'Synthetic Soft Match',
            'sex' => 'unknown',
            'date_of_birth' => '1990-01-01',
            'mobile_phone' => '+60123456722',
            'phone_country' => 'MY',
            'identifiers' => [['identifier_type' => 'passport', 'issuing_country_code' => 'MY', 'value' => 'SYNSOFT'.Str::upper(Str::random(6))]],
            'expected_branch_id' => $this->branch->id,
        ]);

        // Accept refuses on this match when the duplicate is not confirmed.
        $doctor = $this->actor('resident_doctor');
        $reason = app(VisitReasonService::class)->create($reviewer, 'Synthetic soft duplicate review');
        $this->post(route('registration-review.accept', $intake->public_id), [
            'lock_version' => $intake->lock_version,
            'idempotency_key' => (string) Str::uuid(),
            'resolution' => 'create',
            'patient_number' => null,
            'duplicate_override' => false,
            'assigned_doctor_user_id' => $doctor->id,
            'visit_reason_public_ids' => [$reason->public_id],
            'priority' => 'normal',
            'coverage_type' => 'self_pay',
            'panel_id' => null,
            'coverage_member_reference' => null,
            'coverage_verified' => true,
            'confirm_repeat' => false,
        ])->assertSessionHasErrors('duplicate_override');

        // The same match is listed on the review page, masked as before (no raw phone, no full identifier).
        $this->get(route('registration-review.show', $intake->public_id))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('intake.duplicateCandidates', 1)
                ->where('intake.duplicateCandidates.0.patientNumber', $existing->patient_number)
                ->where('intake.duplicateCandidates.0.fullName', 'Synthetic Soft Match')
                ->where('intake.duplicateCandidates.0.maskedPhone', fn ($value) => is_string($value) && ! str_contains($value, '0123456722'))
                ->missing('intake.duplicateCandidates.0.mobilePhone'));
    }

    public function test_no_soft_match_lists_no_candidate(): void
    {
        $intake = $this->submittedIntake('Synthetic Unique Person', '1988-02-02', '+60123456733');
        $reviewer = $this->actor('ca');
        $this->selectBranch($reviewer);

        $this->get(route('registration-review.show', $intake->public_id))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('intake.duplicateCandidates', 0));
    }

    private function submittedIntake(string $name, string $dateOfBirth, string $phone): PublicPatientIntake
    {
        $director = $this->actor('director');
        $issued = app(PublicCheckInLinkService::class)->issue($director, $this->branch, 'Soft duplicate test');
        $this->withServerVariables(['REMOTE_ADDR' => '198.19.0.'.self::$ip++]);
        $response = $this->postJson(route('public-intake.exchange'), ['link_token' => $issued['rawToken']])->assertCreated();
        $nonce = $response->getCookie((string) config('public-intake.submission_cookie'))->getValue();
        $receipt = $response->getCookie((string) config('public-intake.status_cookie'))->getValue();
        $this->withCookies([
            (string) config('public-intake.submission_cookie') => $nonce,
            (string) config('public-intake.status_cookie') => $receipt,
        ])->withCredentials();

        $this->postJson(route('public-intake.submit'), [
            'submission_type' => 'patient',
            'full_name' => $name,
            'date_of_birth' => $dateOfBirth,
            'sex' => 'unknown',
            'nationality_code' => 'MY',
            'mobile_phone' => $phone,
            'phone_country' => 'MY',
            'identifier_type' => 'passport',
            'identifier_value' => 'SYNSI'.Str::upper(Str::random(8)),
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
            ...$response->json(),
        ])->assertCreated();

        return PublicPatientIntake::query()->latest('id')->firstOrFail();
    }
}
