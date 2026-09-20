<?php

namespace Tests\Feature;

use App\Domain\Access\BranchAccessService;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Organisation\Models\PublicCheckInLink;
use App\Domain\Organisation\Services\PublicCheckInLinkService;
use App\Domain\Patient\Models\Patient;
use App\Domain\Patient\Models\PublicIntakeSession;
use App\Domain\Patient\Models\PublicPatientIntake;
use App\Domain\Patient\Services\PublicIntakeReviewService;
use App\Domain\Patient\Services\PublicPatientIntakeService;
use App\Domain\Queue\Models\QueueEntry;
use App\Domain\Visit\Models\Visit;
use App\Domain\Visit\Services\VisitReasonService;
use App\Http\Controllers\PublicCheckInController;
use App\Http\Middleware\ValidatePublicIntakeProxy;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use Tests\Feature\Visit\VisitTestCase;

class PublicPatientIntakeTest extends VisitTestCase
{
    private static int $publicIpSequence = 10;

    public function test_public_proxy_policy_is_request_scoped_even_when_downstream_throws(): void
    {
        $originalProxies = ['127.0.0.9'];
        $originalHeaders = Request::HEADER_X_FORWARDED_FOR;
        Request::setTrustedProxies($originalProxies, $originalHeaders);
        config(['public-intake.trusted_proxies' => ['127.0.0.1']]);

        try {
            $response = app(ValidatePublicIntakeProxy::class)->handle(
                Request::create('/check-in'),
                function (): Response {
                    $this->assertSame(['127.0.0.1'], Request::getTrustedProxies());

                    return new Response('ok');
                },
            );

            $this->assertSame('ok', $response->getContent());
            $this->assertSame($originalProxies, Request::getTrustedProxies());
            $this->assertSame($originalHeaders, Request::getTrustedHeaderSet());

            try {
                app(ValidatePublicIntakeProxy::class)->handle(
                    Request::create('/check-in'),
                    fn (): never => throw new RuntimeException('synthetic downstream failure'),
                );
                $this->fail('The synthetic downstream exception was not raised.');
            } catch (RuntimeException $exception) {
                $this->assertSame('synthetic downstream failure', $exception->getMessage());
            }

            $this->assertSame($originalProxies, Request::getTrustedProxies());
            $this->assertSame($originalHeaders, Request::getTrustedHeaderSet());
        } finally {
            Request::setTrustedProxies([], -1);
        }
    }

    public function test_submission_is_encrypted_staged_idempotent_and_privacy_safe(): void
    {
        [$token, $session] = $this->publicSession();
        $payload = $this->payload($session);
        $before = [Patient::count(), Visit::count(), QueueEntry::count()];

        $response = $this->postJson(route('public-intake.submit'), $payload)
            ->assertCreated()
            ->assertJsonPath('state', PublicPatientIntake::STATUS_PENDING);

        $this->assertSame($before, [Patient::count(), Visit::count(), QueueEntry::count()]);
        $this->assertDatabaseCount('public_patient_intakes', 1);
        $intake = PublicPatientIntake::query()->sole();
        $rawStored = (string) DB::table('public_patient_intakes')->where('id', $intake->id)->value('encrypted_payload');
        $this->assertStringNotContainsString('Synthetic Intake Person', $rawStored);
        $this->assertStringNotContainsString('SYNQ1B2', $rawStored);
        $this->assertSame('Synthetic Intake Person', $intake->encrypted_payload['patient']['full_name']);

        // The browser deletes the submission cookie after success; the protected
        // status context alone must support exact replay without a second write.
        $this->withCookie((string) config('public-intake.submission_cookie'), str_repeat('x', 43));
        $this->postJson(route('public-intake.submit'), $payload)->assertCreated();
        $this->assertDatabaseCount('public_patient_intakes', 1);
        $this->assertSame(1, AuditLog::query()->where('event', 'public_intake.submitted')->count());

        $changed = $payload;
        $changed['full_name'] = 'Changed Synthetic Person';
        $this->postJson(route('public-intake.submit'), $changed)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('submission');
        $this->assertDatabaseCount('public_patient_intakes', 1);

        $receipt = $session['statusReceipt'];
        $this->get(route('public-intake.status'))
            ->assertOk()
            ->assertHeaderContains('Cache-Control', 'no-store')
            ->assertHeader('Referrer-Policy', 'no-referrer')
            ->assertInertia(fn (Assert $page) => $page
                ->component('PublicCheckIn/Status')
                ->where('status.state', 'pending')
                ->where('status.queueNumber', null)
                ->missing('patient')->missing('visit')->missing('organisationId'));

        $auditJson = AuditLog::query()->where('event', 'public_intake.submitted')->sole()->toJson();
        foreach (['Synthetic Intake Person', 'SYNQ1B2', '+60123456789', $session['nonce'], $receipt] as $secret) {
            $this->assertStringNotContainsString($secret, $auditJson);
        }
    }

    public function test_public_bearers_are_confined_to_fragment_exchange_and_protected_cookies(): void
    {
        $director = $this->actor('director');
        $issued = app(PublicCheckInLinkService::class)->issue($director, $this->branch, 'Bearer-free test');
        $rawToken = $issued['rawToken'];
        config(['public-intake.trusted_proxies' => ['127.0.0.1']]);
        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])
            ->withHeaders(['X-Forwarded-Proto' => 'https']);

        $exchange = $this->postJson(route('public-intake.exchange'), ['link_token' => $rawToken])
            ->assertCreated()
            ->assertJsonMissing(['link_token' => $rawToken])
            ->assertJsonMissingPath('nonce')
            ->assertJsonMissingPath('statusReceipt')
            ->assertJsonMissingPath('idempotencyKey');
        $session = $this->installPublicCookies($exchange);
        $statusReceipt = $session['statusReceipt'];
        foreach ([(string) config('public-intake.submission_cookie'), (string) config('public-intake.status_cookie')] as $cookieName) {
            $cookie = $exchange->getCookie($cookieName, false);
            $this->assertNotNull($cookie);
            $this->assertTrue($cookie->isHttpOnly(), $cookieName.' must be HttpOnly.');
            $this->assertTrue($cookie->isSecure(), $cookieName.' must be Secure on HTTPS.');
            $this->assertSame('lax', $cookie->getSameSite());
            $this->assertSame('/check-in', $cookie->getPath());
        }
        $exchangeWire = $exchange->getContent().json_encode($exchange->headers->all(), JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString($rawToken, $exchangeWire);
        $this->assertStringNotContainsString($statusReceipt, $exchangeWire);

        $submitted = $this->postJson(route('public-intake.submit'), $this->payload($session))
            ->assertCreated()
            ->assertJsonPath('statusUrl', route('public-intake.status'));
        $submissionWire = $submitted->getContent().json_encode($submitted->headers->all(), JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString($rawToken, $submissionWire);
        $this->assertStringNotContainsString($statusReceipt, $submissionWire);

        $this->get(route('public-checkin.show'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('PublicCheckIn/Show')
                ->where('statusAvailable', true)
                ->where('branch', null)
                ->where('intakeSession', null));
        $this->get(route('public-intake.status'))->assertOk();

        $this->get('/check-in/'.$rawToken)->assertNotFound();
        $this->get('/check-in/status/'.$statusReceipt)->assertNotFound();

        $auditBytes = AuditLog::query()->get()->toJson();
        $this->assertStringNotContainsString($rawToken, $auditBytes);
        $this->assertStringNotContainsString($statusReceipt, $auditBytes);
    }

    public function test_json_exchange_removes_the_bearer_from_the_active_request_input_source(): void
    {
        $director = $this->actor('director');
        $issued = app(PublicCheckInLinkService::class)->issue($director, $this->branch, 'JSON input redaction');
        $request = Request::create(
            route('public-intake.exchange'),
            'POST',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['link_token' => $issued['rawToken']], JSON_THROW_ON_ERROR),
        );

        app(PublicCheckInController::class)->exchange(
            $request,
            app(PublicCheckInLinkService::class),
            app(PublicPatientIntakeService::class),
        );

        $this->assertNull($request->input('link_token'));
        $this->assertFalse($request->json()->has('link_token'));
        $this->assertSame([], $request->all());
    }

    public function test_existing_status_context_does_not_block_a_fresh_qr_exchange(): void
    {
        [$rawToken, $session] = $this->publicSession();
        $this->postJson(route('public-intake.submit'), $this->payload($session))->assertCreated();

        $this->get(route('public-checkin.show'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('statusAvailable', true)
                ->where('intakeSession', null));

        $freshExchange = $this->postJson(route('public-intake.exchange'), ['link_token' => $rawToken])
            ->assertCreated();
        $freshSession = $this->installPublicCookies($freshExchange);
        $this->assertNotSame($session['nonce'], $freshSession['nonce']);
        $this->assertNotSame($session['statusReceipt'], $freshSession['statusReceipt']);
        $this->get(route('public-intake.status'))->assertNotFound();
        $this->assertDatabaseCount('public_patient_intakes', 1);
    }

    public function test_fresh_cross_branch_qr_exchange_replaces_an_active_resumable_session(): void
    {
        [, $oldSession] = $this->publicSession();
        $alternateBranch = Branch::query()
            ->where('organisation_id', $this->organisation->id)
            ->whereKeyNot($this->branch->id)
            ->where('is_active', true)
            ->orderBy('id')
            ->firstOrFail();
        $director = $this->actor('director');
        $issued = app(PublicCheckInLinkService::class)->issue($director, $alternateBranch, 'Fresh cross-branch QR');

        $freshExchange = $this->postJson(route('public-intake.exchange'), ['link_token' => $issued['rawToken']])
            ->assertCreated()
            ->assertJsonPath('branch.name', $alternateBranch->name);
        $freshSession = $this->installPublicCookies($freshExchange);

        $this->assertNotSame($oldSession['nonce'], $freshSession['nonce']);
        $this->assertNotSame($oldSession['statusReceipt'], $freshSession['statusReceipt']);
        $this->assertSame(2, PublicIntakeSession::query()->count());
        $this->assertSame(
            $alternateBranch->id,
            PublicIntakeSession::query()
                ->where('nonce_digest', hash('sha256', $freshSession['nonce']))
                ->value('branch_id'),
        );
        $this->assertDatabaseCount('public_patient_intakes', 0);
    }

    public function test_failed_qr_exchange_cannot_resume_stale_protected_cookies_after_reload(): void
    {
        [, $oldSession] = $this->publicSession();
        $attemptCookie = (string) config('public-intake.exchange_attempt_cookie');

        $this->withUnencryptedCookie($attemptCookie, '1');
        $this->get(route('public-intake.status'))->assertNotFound();

        $reload = $this->get(route('public-checkin.show'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('statusAvailable', false)
                ->where('branch', null)
                ->where('intakeSession', null));

        $reload->assertCookieExpired((string) config('public-intake.submission_cookie'));
        $reload->assertCookieExpired((string) config('public-intake.status_cookie'));
        $reload->assertCookieExpired($attemptCookie);
        $this->postJson(route('public-intake.submit'), $this->payload($oldSession))->assertNotFound();
        $this->assertDatabaseCount('public_patient_intakes', 0);
    }

    public function test_throttled_qr_exchange_cannot_revive_stale_protected_cookies(): void
    {
        [$rawToken, $oldSession] = $this->publicSession();

        for ($request = 1; $request <= 9; $request++) {
            $this->postJson(route('public-intake.exchange'), ['link_token' => $rawToken])
                ->assertCreated();
        }

        $attemptCookie = (string) config('public-intake.exchange_attempt_cookie');
        $this->withUnencryptedCookie($attemptCookie, '1');
        $this->postJson(route('public-intake.exchange'), ['link_token' => $rawToken])
            ->assertTooManyRequests();

        $reload = $this->get(route('public-checkin.show'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('statusAvailable', false)
                ->where('branch', null)
                ->where('intakeSession', null));

        $reload->assertCookieExpired((string) config('public-intake.submission_cookie'));
        $reload->assertCookieExpired((string) config('public-intake.status_cookie'));
        $reload->assertCookieExpired($attemptCookie);
        $this->postJson(route('public-intake.submit'), $this->payload($oldSession))->assertNotFound();
        $this->assertDatabaseCount('public_patient_intakes', 0);
    }

    public function test_expired_status_context_cannot_read_or_replay_an_intake(): void
    {
        [, $session] = $this->publicSession();
        $payload = $this->payload($session);
        $this->postJson(route('public-intake.submit'), $payload)->assertCreated();
        PublicIntakeSession::query()->update(['expires_at' => now()->subSecond()]);

        $this->get(route('public-intake.status'))->assertNotFound();
        $this->withCookie((string) config('public-intake.submission_cookie'), str_repeat('x', 43));
        $this->postJson(route('public-intake.submit'), $payload)->assertNotFound();
        $this->assertDatabaseCount('public_patient_intakes', 1);
        $this->assertSame(1, AuditLog::query()->where('event', 'public_intake.submitted')->count());
    }

    public function test_expiry_rotation_inactive_branch_nonce_and_proxy_boundaries_fail_closed(): void
    {
        [$token, $session, $link] = $this->publicSession(includeLink: true);
        $link->forceFill(['expires_at' => now()->subSecond()])->save();
        $this->get(route('public-checkin.show'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('branch', null)
                ->where('intakeSession', null)
                ->where('statusAvailable', false));
        $this->postJson(route('public-intake.submit'), $this->payload($session))->assertNotFound();
        $this->assertDatabaseCount('public_patient_intakes', 0);

        $director = $this->actor('director');
        $issued = app(PublicCheckInLinkService::class)->rotate($director, $link->refresh());
        $this->postJson(route('public-intake.exchange'), ['link_token' => $token])->assertNotFound();
        $exchange = $this->postJson(route('public-intake.exchange'), ['link_token' => $issued['rawToken']])->assertCreated();

        $newSession = $this->installPublicCookies($exchange);
        PublicIntakeSession::query()->where('nonce_digest', hash('sha256', $newSession['nonce']))
            ->update(['expires_at' => now()->subSecond()]);
        $this->postJson(route('public-intake.submit'), $this->payload($newSession))
            ->assertNotFound();

        $issued['link']->branch->forceFill(['is_active' => false])->save();
        $this->postJson(route('public-intake.exchange'), ['link_token' => $issued['rawToken']])->assertNotFound();

        $issued['link']->branch->forceFill(['is_active' => true])->save();
        for ($request = 1; $request <= 8; $request++) {
            $this->withHeaders(['X-Forwarded-For' => "198.51.100.{$request}"])
                ->postJson(route('public-intake.exchange'), ['link_token' => $issued['rawToken']])
                ->assertCreated();
        }
        $this->withHeaders(['X-Forwarded-For' => '198.51.100.200'])
            ->postJson(route('public-intake.exchange'), ['link_token' => $issued['rawToken']])
            ->assertTooManyRequests();
    }

    public function test_minor_requires_guardian_and_consent_and_guardian_submission_is_accepted_for_review(): void
    {
        [$token, $session] = $this->publicSession();
        $minor = $this->payload($session, ['date_of_birth' => now()->subYears(10)->format('Y-m-d')]);
        $this->postJson(route('public-intake.submit'), $minor)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('submission_type');

        $minor['consent_confirmed'] = false;
        $this->postJson(route('public-intake.submit'), $minor)
            ->assertUnprocessable()->assertJsonValidationErrors('consent_confirmed');

        $guardian = $minor;
        $guardian['submission_type'] = 'guardian';
        $guardian['guardian_name'] = 'Synthetic Guardian';
        $guardian['guardian_relationship'] = 'parent';
        $guardian['guardian_contact_number'] = '+60123456789';
        $guardian['guardian_attestation'] = true;
        $guardian['consent_confirmed'] = true;
        $this->postJson(route('public-intake.submit'), $guardian)->assertCreated();
        $this->assertDatabaseHas('public_patient_intakes', [
            'submission_type' => 'guardian',
            'privacy_notice_version' => config('public-intake.privacy_notice_version'),
        ]);
    }

    public function test_authorized_staff_acceptance_atomically_creates_patient_visit_and_one_queue_entry(): void
    {
        [$token, $session] = $this->publicSession();
        $this->postJson(route('public-intake.submit'), $this->payload($session))->assertCreated();
        $intake = PublicPatientIntake::query()->sole();
        $this->assertSame(0, Visit::query()->where('branch_id', $this->branch->id)->count());

        $ca = $this->actor('ca');
        $doctor = $this->actor('resident_doctor');
        $this->selectBranch($ca);
        $reason = app(VisitReasonService::class)->create($ca, 'Synthetic public intake review');

        $this->post(route('registration-review.start', $intake->public_id), ['lock_version' => 1])->assertRedirect();
        $intake->refresh();
        $accept = [
            'lock_version' => $intake->lock_version,
            'idempotency_key' => (string) Str::uuid(),
            'resolution' => 'create',
            'patient_number' => null,
            'duplicate_override' => true,
            'assigned_doctor_user_id' => $doctor->id,
            'visit_reason_public_ids' => [$reason->public_id],
            'priority' => 'normal',
            'coverage_type' => 'self_pay',
            'panel_id' => null,
            'coverage_member_reference' => null,
            'confirm_repeat' => false,
        ];
        $before = [Patient::count(), Visit::count(), QueueEntry::count()];
        $this->post(route('registration-review.accept', $intake->public_id), $accept)->assertRedirect();
        $this->assertSame([$before[0] + 1, $before[1] + 1, $before[2] + 1], [Patient::count(), Visit::count(), QueueEntry::count()]);

        $intake->refresh();
        $this->assertSame(PublicPatientIntake::STATUS_ACCEPTED, $intake->status);
        $this->assertNotNull($intake->patient_id);
        $this->assertNotNull($intake->visit_id);
        $this->assertNotNull($intake->queue_entry_id);
        $this->assertSame($ca->id, $intake->accepted_by_user_id);
        $this->assertDatabaseHas('audit_logs', ['event' => 'public_intake.accepted', 'actor_user_id' => $ca->id]);

        $this->post(route('registration-review.accept', $intake->public_id), $accept)->assertRedirect();
        $this->assertSame([$before[0] + 1, $before[1] + 1, $before[2] + 1], [Patient::count(), Visit::count(), QueueEntry::count()]);

        $changed = $accept;
        $changed['priority'] = 'urgent';
        $this->post(route('registration-review.accept', $intake->public_id), $changed)
            ->assertSessionHasErrors('idempotency_key');
        $this->assertSame([$before[0] + 1, $before[1] + 1, $before[2] + 1], [Patient::count(), Visit::count(), QueueEntry::count()]);

        $this->get(route('public-intake.status'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('status.state', 'accepted')
                ->where('status.queueNumber', '001')
                ->missing('status.patientId')->missing('status.visitId'));
    }

    public function test_patient_and_visit_acceptance_failures_leave_no_partial_conversion(): void
    {
        [$token, $session] = $this->publicSession();
        $this->postJson(route('public-intake.submit'), $this->payload($session))->assertCreated();
        $intake = PublicPatientIntake::query()->sole();
        $ca = $this->actor('ca');
        $this->selectBranch($ca);
        $reason = app(VisitReasonService::class)->create($ca, 'Synthetic rollback review');
        $before = [Patient::count(), Visit::count(), QueueEntry::count()];

        $match = [
            'lock_version' => $intake->lock_version,
            'idempotency_key' => (string) Str::uuid(),
            'resolution' => 'match',
            'patient_number' => 'KP-NOT-FOUND',
            'assigned_doctor_user_id' => $ca->id,
            'visit_reason_public_ids' => [$reason->public_id],
            'priority' => 'normal',
            'coverage_type' => 'self_pay',
        ];
        $this->post(route('registration-review.accept', $intake->public_id), $match)->assertNotFound();
        $this->assertSame($before, [Patient::count(), Visit::count(), QueueEntry::count()]);
        $this->assertSame(PublicPatientIntake::STATUS_PENDING, $intake->refresh()->status);

        $create = $match;
        $create['idempotency_key'] = (string) Str::uuid();
        $create['resolution'] = 'create';
        $create['patient_number'] = null;
        $create['duplicate_override'] = true;
        $this->post(route('registration-review.accept', $intake->public_id), $create)
            ->assertSessionHasErrors('assigned_doctor_user_id');
        $this->assertSame($before, [Patient::count(), Visit::count(), QueueEntry::count()]);
        $this->assertSame(PublicPatientIntake::STATUS_PENDING, $intake->refresh()->status);
    }

    public function test_queue_failure_rolls_back_new_patient_and_visit(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL trigger-backed rollback test.');
        }

        [$token, $session] = $this->publicSession();
        $this->postJson(route('public-intake.submit'), $this->payload($session))->assertCreated();
        $intake = PublicPatientIntake::query()->sole();
        $ca = $this->actor('ca');
        $doctor = $this->actor('resident_doctor');
        $this->selectBranch($ca);
        $reason = app(VisitReasonService::class)->create($ca, 'Synthetic queue rollback review');
        $before = [Patient::count(), Visit::count(), QueueEntry::count()];

        DB::unprepared(<<<'SQL'
CREATE FUNCTION q1b2_fail_queue_insert() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    RAISE EXCEPTION 'synthetic queue insert failure';
END;
$$;
CREATE TRIGGER q1b2_fail_queue_insert BEFORE INSERT ON queue_entries
FOR EACH ROW EXECUTE FUNCTION q1b2_fail_queue_insert();
SQL);

        try {
            app(PublicIntakeReviewService::class)->accept($ca, $intake->public_id, [
                'lock_version' => $intake->lock_version,
                'idempotency_key' => (string) Str::uuid(),
                'resolution' => 'create',
                'patient_number' => null,
                'duplicate_override' => true,
                'assigned_doctor_user_id' => $doctor->id,
                'visit_reason_public_ids' => [$reason->public_id],
                'priority' => 'normal',
                'coverage_type' => 'self_pay',
                'panel_id' => null,
                'coverage_member_reference' => null,
                'confirm_repeat' => false,
            ]);
            $this->fail('The synthetic queue insert failure was not raised.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('synthetic queue insert failure', $exception->getMessage());
        } finally {
            DB::unprepared('DROP TRIGGER IF EXISTS q1b2_fail_queue_insert ON queue_entries; DROP FUNCTION IF EXISTS q1b2_fail_queue_insert()');
        }

        $this->assertSame($before, [Patient::count(), Visit::count(), QueueEntry::count()]);
        $this->assertSame(PublicPatientIntake::STATUS_PENDING, $intake->refresh()->status);
        $this->assertNull($intake->acceptance_idempotency_key);
    }

    public function test_rejection_correction_and_unauthorized_branch_leave_no_patient_visit_or_queue(): void
    {
        [$token, $session] = $this->publicSession();
        $this->postJson(route('public-intake.submit'), $this->payload($session))->assertCreated();
        $intake = PublicPatientIntake::query()->sole();
        $before = [Patient::count(), Visit::count(), QueueEntry::count()];

        $doctor = $this->actor('resident_doctor');
        $this->actingAs($doctor)->get(route('registration-review.show', $intake->public_id))->assertForbidden();

        $foreignBranch = Branch::query()->where('organisation_id', $this->organisation->id)
            ->whereKeyNot($this->branch->id)->firstOrFail();
        $ca = $this->actor('ca', $foreignBranch);
        $this->actingAs($ca);
        session([BranchAccessService::SESSION_KEY => $foreignBranch->id]);
        $this->get(route('registration-review.show', $intake->public_id))->assertNotFound();

        $reviewer = $this->actor('ca');
        $this->selectBranch($reviewer);
        $this->post(route('registration-review.correction-required', $intake->public_id), ['lock_version' => 1])->assertRedirect();
        $intake->refresh();
        $this->post(route('registration-review.reject', $intake->public_id), [
            'lock_version' => $intake->lock_version,
            'category' => 'insufficient_information',
        ])->assertRedirect();

        $this->assertSame($before, [Patient::count(), Visit::count(), QueueEntry::count()]);
        $this->assertSame(PublicPatientIntake::STATUS_REJECTED, $intake->refresh()->status);
        $this->get(route('public-intake.status'))
            ->assertInertia(fn (Assert $page) => $page->where('status.state', 'rejected')->where('status.queueNumber', null));
    }

    public function test_staff_correction_changes_permitted_fields_but_preserves_consent_and_submitter_type(): void
    {
        [$token, $session] = $this->publicSession();
        $this->postJson(route('public-intake.submit'), $this->payload($session))->assertCreated();
        $intake = PublicPatientIntake::query()->sole();
        $originalConsent = $intake->encrypted_payload['consent'];

        $reviewer = $this->actor('ca');
        $this->selectBranch($reviewer);
        $correction = $this->payload($session, [
            'lock_version' => $intake->lock_version,
            'full_name' => 'Corrected Synthetic Intake Person',
            'submission_type' => 'guardian',
            'guardian_name' => 'Crafted Guardian',
            'guardian_relationship' => 'parent',
            'guardian_contact_number' => '+60123456789',
            'guardian_attestation' => true,
            'privacy_notice_version' => 'crafted-version',
        ]);

        $this->patch(route('registration-review.correct', $intake->public_id), $correction)->assertRedirect();

        $intake->refresh();
        $this->assertSame('Corrected Synthetic Intake Person', $intake->encrypted_payload['patient']['full_name']);
        $this->assertSame('patient', $intake->encrypted_payload['submission_type']);
        $this->assertNull($intake->encrypted_payload['guardian']);
        $this->assertSame($originalConsent, $intake->encrypted_payload['consent']);
        $this->assertSame('patient', $intake->submission_type);
    }

    public function test_cleanup_expires_and_purges_payload_idempotently(): void
    {
        [$token, $session] = $this->publicSession();
        $this->postJson(route('public-intake.submit'), $this->payload($session))->assertCreated();
        $intake = PublicPatientIntake::query()->sole();
        $intake->forceFill(['expires_at' => now()->subDay(), 'payload_purge_at' => now()->subDay()])->save();

        $this->artisan('public-intakes:cleanup')->assertSuccessful();
        $intake->refresh();
        $this->assertSame(PublicPatientIntake::STATUS_EXPIRED, $intake->status);
        $this->assertNull($intake->encrypted_payload);
        $this->assertNotNull($intake->payload_purged_at);
        $this->assertSame(1, AuditLog::query()->where('event', 'public_intake.expired')->count());
        $this->assertSame(1, AuditLog::query()->where('event', 'public_intake.payload_purged')->count());

        $this->artisan('public-intakes:cleanup')->assertSuccessful();
        $this->assertSame(1, AuditLog::query()->where('event', 'public_intake.expired')->count());
        $this->assertSame(1, AuditLog::query()->where('event', 'public_intake.payload_purged')->count());
    }

    public function test_feature_flag_disables_public_intake_outside_the_workflow(): void
    {
        [$token] = $this->publicSession();
        config()->set('public-intake.enabled', false);
        $this->get(route('public-checkin.show'))->assertNotFound();
        $this->postJson(route('public-intake.exchange'), ['link_token' => $token])->assertNotFound();
    }

    /** @return array{0: string, 1: array<string, mixed>, 2?: PublicCheckInLink} */
    private function publicSession(bool $includeLink = false): array
    {
        $director = $this->actor('director');
        $issued = app(PublicCheckInLinkService::class)->issue($director, $this->branch, 'Q1-B2 test');
        $this->withServerVariables(['REMOTE_ADDR' => '198.18.0.'.self::$publicIpSequence++]);
        $response = $this->postJson(route('public-intake.exchange'), ['link_token' => $issued['rawToken']])->assertCreated();
        $session = $this->installPublicCookies($response);

        return $includeLink
            ? [$issued['rawToken'], $session, $issued['link']]
            : [$issued['rawToken'], $session];
    }

    /** @return array<string, mixed> */
    private function installPublicCookies(TestResponse $response): array
    {
        $submissionName = (string) config('public-intake.submission_cookie');
        $statusName = (string) config('public-intake.status_cookie');
        $submissionCookie = $response->getCookie($submissionName);
        $statusCookie = $response->getCookie($statusName);
        $this->assertNotNull($submissionCookie);
        $this->assertNotNull($statusCookie);
        $this->assertTrue($submissionCookie->isHttpOnly());
        $this->assertTrue($statusCookie->isHttpOnly());
        $this->assertSame('lax', $submissionCookie->getSameSite());
        $this->assertSame('lax', $statusCookie->getSameSite());

        $nonce = $submissionCookie->getValue();
        $statusReceipt = $statusCookie->getValue();
        $this->withCookies([
            $submissionName => $nonce,
            $statusName => $statusReceipt,
        ])->withCredentials();

        return [
            ...$response->json(),
            'nonce' => $nonce,
            'statusReceipt' => $statusReceipt,
        ];
    }

    /** @param array<string, mixed> $session
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $session, array $overrides = []): array
    {
        return [
            'submission_type' => 'patient',
            'full_name' => 'Synthetic Intake Person',
            'date_of_birth' => '1990-01-01',
            'sex' => 'unknown',
            'nationality_code' => 'MY',
            'mobile_phone' => '+60123456789',
            'phone_country' => 'MY',
            'identifier_type' => 'passport',
            'identifier_value' => 'SYNQ1B2'.Str::upper(Str::random(8)),
            'identifier_issuing_country_code' => 'MY',
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
