<?php

namespace Tests\Feature\HR;

use App\Domain\HR\Applications\Services\CandidateLink;
use App\Domain\HR\Applications\Services\InterviewSlots;
use App\Domain\HR\Applications\Services\JobApplicationIntake;
use App\Domain\HR\Applications\Services\JobApplicationService;
use App\Mail\HiringMessageMail;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Visit\VisitTestCase;

/**
 * HR-17e result, talent pool and staff-account handoff (HR-PLAN 19.8): the optional pool tick and leaving the pool, the
 * clinic-entered pool period, inviting a pool candidate to a new position, the purge, and the staff-account request
 * that only whoever may create staff accounts closes. Synthetic `.test` data only; nothing is really sent.
 */
class TalentPoolTest extends VisitTestCase
{
    private const PDF = "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\nSYNTHETIC-CV-CONTENT\n%%EOF\n";

    private User $hr;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(JobApplicationIntake::DISK);
        Mail::fake();
        RateLimiter::clear('hr-apply-all');
        // A Tuesday.
        $this->travelTo(CarbonImmutable::parse('2026-11-10 10:00', $this->branch->timezone));
        $this->hr = $this->actor('hr_manager');
        foreach (DB::table('branches')->where('organisation_id', $this->hr->organisation_id)->pluck('id') as $branchId) {
            DB::table('hr_branch_working_days')->insert(['organisation_id' => $this->hr->organisation_id, 'branch_id' => $branchId,
                'weekdays' => json_encode([1, 2, 3, 4, 5]), 'updated_by_user_id' => $this->hr->id, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('hr_public_holidays')->insert(['organisation_id' => $this->hr->organisation_id, 'branch_id' => $branchId, 'holiday_date' => '2026-11-13',
                'name' => 'Synthetic holiday', 'created_by_user_id' => $this->hr->id, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    public function test_the_pool_tick_is_optional_and_the_candidate_leaves_once_for_good(): void
    {
        [$plainId, $plainToken] = $this->filedWithToken('Synthetic Assistant');
        [$poolId, $poolToken] = $this->filedWithToken('Synthetic Assistant Two', true);
        $this->assertFalse((bool) $this->row($plainId)->talent_pool_consent);
        $this->assertTrue((bool) $this->row($poolId)->talent_pool_consent);

        $this->statusPage($plainToken)->assertInertia(fn (Assert $p) => $p->where('application.pool', ['consented' => false, 'left' => false]));
        $this->leave($plainToken)->assertSessionHasErrors('pool');
        $this->statusPage($poolToken)->assertInertia(fn (Assert $p) => $p->where('application.pool', ['consented' => true, 'left' => false]));
        $this->leave($poolToken)->assertRedirect(route('hr-applications.status'))->assertSessionHasNoErrors();
        $this->statusPage($poolToken)->assertInertia(fn (Assert $p) => $p->where('application.pool.left', true));
        $this->leave($poolToken)->assertSessionHasErrors('pool');
        $row = $this->row($poolId);
        $this->assertSame(1, DB::table('hr_job_application_events')->where('application_id', $row->id)->where('action', 'pool_left')->count());

        // PostgreSQL: leaving is final, the tick is kept as sent, and a withdrawal needs a tick.
        $this->assertDatabaseRefuses(fn () => DB::table('hr_job_applications')->where('id', $row->id)->update(['pool_withdrawn_at' => null]));
        $this->assertDatabaseRefuses(fn () => DB::table('hr_job_applications')->where('id', $row->id)->update(['talent_pool_consent' => false]));
        $this->assertDatabaseRefuses(fn () => DB::table('hr_job_applications')->where('public_id', $plainId)->update(['pool_withdrawn_at' => now()]), '23514');
    }

    public function test_the_pool_period_is_clinic_entered_and_only_passed_or_failed_candidates_who_ticked_are_listed(): void
    {
        [$passed] = $this->closed('Synthetic Assistant', 'passed', true);
        [$failed] = $this->closed('Synthetic Assistant Two', 'failed', true);
        [$noTick] = $this->closed('Synthetic Assistant Three', 'passed', false);
        [$open] = $this->filedWithToken('Synthetic Assistant Four', true);

        // No period: nobody is kept.
        $this->selectBranch($this->hr);
        $this->get(route('hr.applications.index', ['tab' => 'pool']))->assertInertia(fn (Assert $p) => $p
            ->where('applications.pool.days', null)->where('applications.pool.members', []));

        // A first period waits the grace days.
        $this->setPoolDays(365, 0)->assertSessionHasNoErrors();
        $this->get(route('hr.applications.index', ['tab' => 'pool']))->assertInertia(fn (Assert $p) => $p
            ->where('applications.pool.days', null)->where('applications.pool.pending.days', 365));
        $this->setPoolDays(365, 1)->assertSessionHasErrors('pool_days');
        $this->setPoolDays(0, 1)->assertSessionHasErrors('pool_days');
        $this->travel(JobApplicationService::GRACE_DAYS + 1)->days();
        $this->selectBranch($this->hr);
        $this->get(route('hr.applications.index', ['tab' => 'pool']))->assertInertia(fn (Assert $p) => $p
            ->where('applications.pool.days', 365)->has('applications.pool.members', 2)
            ->where('applications.pool.members.0.id', $failed)->where('applications.pool.members.1.id', $passed)
            ->where('applications.pool.members.1.resultLabel', 'Passed'));
        $this->assertNotNull($this->row($passed)->closed_at);
        $this->assertNull($this->row($open)->closed_at);
        $this->assertFalse((bool) $this->row($noTick)->talent_pool_consent);
        $this->assertSame(1, DB::table('audit_logs')->where('event', 'hr.applications.pool_days_set')->count());
    }

    public function test_hr_invites_a_pool_candidate_to_an_open_position_of_the_same_type_once(): void
    {
        config(['hr-applications.email_enabled' => true]);
        [$source] = $this->closed('Synthetic Assistant', 'failed', true);
        $this->poolOn();
        $this->selectBranch($this->hr);
        $this->post(route('hr.applications.positions.store'), ['title' => 'Synthetic Assistant Night', 'branch_id' => 'any', 'position_type' => 'ca',
            'idempotency_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
        $this->post(route('hr.applications.positions.store'), ['title' => 'Synthetic Intern Place', 'branch_id' => 'any', 'position_type' => 'intern',
            'idempotency_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
        $night = (string) DB::table('hr_job_positions')->where('title', 'Synthetic Assistant Night')->value('public_id');
        $intern = (string) DB::table('hr_job_positions')->where('title', 'Synthetic Intern Place')->value('public_id');

        $this->get(route('hr.applications.index', ['tab' => 'pool']))->assertInertia(fn (Assert $p) => $p
            ->where('applications.pool.members.0.positions', fn ($positions) => collect($positions)->pluck('label')->contains('Synthetic Assistant Night')
                && ! collect($positions)->pluck('label')->contains('Synthetic Intern Place')));
        $this->invite($source, $intern)->assertSessionHasErrors('position');

        $this->invite($source, $night)->assertSessionHasNoErrors()->assertRedirect(route('hr.applications.index', ['tab' => 'pool']));
        $from = $this->row($source);
        $new = DB::table('hr_job_applications')->where('invited_from_application_id', $from->id)->sole();
        $this->assertSame(['phone_screening', true], [$new->status, (bool) $new->talent_pool_consent]);
        $this->assertSame(Crypt::decryptString($from->name_encrypted), Crypt::decryptString($new->name_encrypted));
        $this->assertNotSame($from->cv_path, $new->cv_path);
        $this->assertSame(Crypt::decryptString(Storage::disk(JobApplicationIntake::DISK)->get($from->cv_path)),
            Crypt::decryptString(Storage::disk(JobApplicationIntake::DISK)->get($new->cv_path)));
        $this->assertSame(['invited_to_position'], DB::table('hr_job_application_events')->where('application_id', $from->id)->where('action', 'like', 'invited%')->pluck('action')->all());
        $this->assertSame(['invited_from_pool'], DB::table('hr_job_application_events')->where('application_id', $new->id)->pluck('action')->all());
        // The new link is emailed once, and the candidate's own page shows the invitation without it.
        Mail::assertSent(HiringMessageMail::class, fn (HiringMessageMail $m) => str_contains($m->bodyText, '/careers/status#'));
        $this->assertSame('pool_invited', DB::table('hr_job_application_messages')->where('application_id', $new->id)->value('template'));

        $this->invite($source, $night)->assertSessionHasErrors('position'); // once per position
        $this->assertSame(1, DB::table('hr_job_applications')->where('invited_from_application_id', $from->id)->count());
        // After leaving the pool, no more invitations.
        [, $token] = [$source, $this->tokens[$source]];
        $this->leave($token)->assertSessionHasNoErrors();
        $this->selectBranch($this->hr);
        $this->post(route('hr.applications.positions.store'), ['title' => 'Synthetic Assistant Weekend', 'branch_id' => 'any', 'position_type' => 'ca',
            'idempotency_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
        $weekend = (string) DB::table('hr_job_positions')->where('title', 'Synthetic Assistant Weekend')->value('public_id');
        $this->invite($source, $weekend)->assertSessionHasErrors('application');
    }

    public function test_the_purge_keeps_a_pool_candidate_until_the_pool_period_ends(): void
    {
        [$pool] = $this->closed('Synthetic Assistant', 'passed', true);
        [$plain] = $this->closed('Synthetic Assistant Two', 'passed', false);
        $this->selectBranch($this->hr);
        $this->post(route('hr.applications.account-request', $plain), ['expected_lock_version' => $this->row($plain)->lock_version,
            'idempotency_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
        $this->post(route('hr.applications.retention'), ['retention_days' => 30, 'expected_version' => 1, 'idempotency_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
        $this->setPoolDays(365, 0)->assertSessionHasNoErrors();
        $this->travel(40)->days();

        app(JobApplicationService::class)->purgeExpired();
        $this->assertNotNull($this->row($plain)->purged_at);
        $this->assertNull($this->row($pool)->purged_at);
        $request = DB::table('hr_staff_account_requests')->sole();
        $this->assertSame([null, null], [$request->name_encrypted, $request->email_encrypted]);
        $this->assertNotNull($request->purged_at);

        // Once the candidate leaves the pool, the usual retention applies at the next run.
        $this->leave($this->tokens[$pool])->assertSessionHasNoErrors();
        app(JobApplicationService::class)->purgeExpired();
        $this->assertNotNull($this->row($pool)->purged_at);
    }

    public function test_hr_asks_for_a_staff_account_and_only_whoever_may_create_staff_marks_it_done(): void
    {
        [$passed] = $this->closed('Synthetic Assistant', 'passed', false);
        [$failed] = $this->closed('Synthetic Assistant Two', 'failed', false);
        $before = [DB::table('users')->count(), DB::table('model_has_roles')->count(), DB::table('staff_branch_assignments')->count()];

        $this->requestAccount($failed)->assertSessionHasErrors('application');
        $this->requestAccount($passed)->assertSessionHasNoErrors();
        $this->requestAccount($passed)->assertSessionHasErrors('application'); // one open request
        $request = DB::table('hr_staff_account_requests')->sole();
        $this->assertSame(['open', 'Synthetic Assistant'], [$request->status, $request->position_title]);

        // The HR Manager may not create staff accounts, so sees no list and cannot mark it done.
        $this->selectBranch($this->hr);
        $this->get(route('staff.index'))->assertInertia(fn (Assert $p) => $p->where('accountRequests', null));
        $this->post(route('staff.account-requests.done', $request->public_id), ['expected_lock_version' => 1, 'idempotency_key' => (string) Str::uuid()])->assertForbidden();

        // The Director passes the staff-creation check: sees name, email, position and branch, and marks it done.
        $director = $this->actor('director');
        $this->selectBranch($director);
        $this->get(route('staff.index'))->assertInertia(fn (Assert $p) => $p->has('accountRequests', 1)
            ->where('accountRequests.0.name', 'Synthetic Applicant')->where('accountRequests.0.email', 'applicant@example.test')
            ->where('accountRequests.0.position', 'Synthetic Assistant')->where('accountRequests.0.branch', 'Any branch'));
        $this->post(route('staff.account-requests.done', $request->public_id), ['expected_lock_version' => 1, 'idempotency_key' => (string) Str::uuid()])
            ->assertRedirect(route('staff.index'))->assertSessionHasNoErrors();
        $this->assertSame('done', DB::table('hr_staff_account_requests')->value('status'));
        $this->get(route('staff.index'))->assertInertia(fn (Assert $p) => $p->where('accountRequests', []));

        // HR sees it in the drawer; nothing about accounts, roles or branch assignments changed.
        $this->selectBranch($this->hr);
        $this->get(route('hr.applications.index', ['status' => 'passed']))->assertInertia(fn (Assert $p) => $p
            ->where('applications.applications.rows.0.accountRequest.status', 'done'));
        $this->assertSame($before[0] + 1, DB::table('users')->count()); // only the Director made for this test
        $this->assertSame($before[1] + 1, DB::table('model_has_roles')->count());
        $this->assertSame([$before[2] + 1], [DB::table('staff_branch_assignments')->count()]);

        // PostgreSQL: closed once; keeps what was asked; only for a passed candidate.
        $this->assertDatabaseRefuses(fn () => DB::table('hr_staff_account_requests')->update(['status' => 'open', 'closed_at' => null, 'closed_by_user_id' => null]));
        $this->assertDatabaseRefuses(fn () => DB::table('hr_staff_account_requests')->update(['position_title' => 'Changed']));
        $this->assertDatabaseRefuses(fn () => DB::table('hr_staff_account_requests')->delete());
        $failedRow = $this->row($failed);
        $this->assertDatabaseRefuses(fn () => DB::table('hr_staff_account_requests')->insert(['public_id' => (string) Str::uuid(), 'organisation_id' => $failedRow->organisation_id,
            'application_id' => $failedRow->id, 'name_encrypted' => 'x', 'email_encrypted' => 'x', 'position_title' => 'Synthetic', 'status' => 'open',
            'requested_by_user_id' => $this->hr->id, 'requested_at' => now(), 'lock_version' => 1, 'created_at' => now(), 'updated_at' => now()]));
    }

    public function test_hr_can_cancel_an_open_request_and_ask_again(): void
    {
        [$passed] = $this->closed('Synthetic Assistant', 'passed', false);
        $this->requestAccount($passed)->assertSessionHasNoErrors();
        $request = DB::table('hr_staff_account_requests')->sole();
        $this->selectBranch($this->hr);
        $this->post(route('hr.applications.account-request.cancel', $request->public_id), ['expected_lock_version' => 1, 'idempotency_key' => (string) Str::uuid()])
            ->assertSessionHasNoErrors();
        $this->post(route('hr.applications.account-request.cancel', $request->public_id), ['expected_lock_version' => 2, 'idempotency_key' => (string) Str::uuid()])
            ->assertSessionHasErrors('request');
        $this->requestAccount($passed)->assertSessionHasNoErrors();
        $this->assertSame(['cancelled', 'open'], DB::table('hr_staff_account_requests')->orderBy('id')->pluck('status')->all());
    }

    /** @var array<string, string> each application's private link token, by public id */
    private array $tokens = [];

    /**
     * An application through phone screening, a confirmed interview and its result.
     *
     * @return array{0:string, 1:string}
     */
    private function closed(string $title, string $result, bool $pool): array
    {
        [$id, $token] = $this->scheduled($title, $pool);
        $this->tokens[$id] = $token;
        $slots = $this->starts($id);
        $this->pick($token, array_slice($slots, 0, 3))->assertSessionHasNoErrors();
        $this->confirm($id, $slots[0])->assertSessionHasNoErrors();
        $this->travelTo(CarbonImmutable::parse($slots[0])->addHours(2));
        $this->selectBranch($this->hr);
        $this->post(route('hr.applications.move', $id), ['to' => $result, 'expected_lock_version' => $this->row($id)->lock_version,
            'idempotency_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
        // Back to the start day, so the next candidate's slots are the same ones.
        $this->travelTo(CarbonImmutable::parse('2026-11-10 10:00', $this->branch->timezone)->addMinutes(count($this->tokens) * 10));

        return [$id, $token];
    }

    private function poolOn(): void
    {
        $this->setPoolDays(365, 0)->assertSessionHasNoErrors();
        // A first period waits the grace days.
        $this->travel(JobApplicationService::GRACE_DAYS + 1)->days();
    }

    private function setPoolDays(int $days, int $version): TestResponse
    {
        $this->selectBranch($this->hr);

        return $this->post(route('hr.applications.pool-days'), ['pool_days' => $days, 'expected_version' => $version, 'idempotency_key' => (string) Str::uuid()]);
    }

    private function invite(string $id, string $position): TestResponse
    {
        $this->selectBranch($this->hr);

        return $this->post(route('hr.applications.invite', $id), ['position' => $position, 'expected_lock_version' => $this->row($id)->lock_version,
            'idempotency_key' => (string) Str::uuid()]);
    }

    private function requestAccount(string $id): TestResponse
    {
        $this->selectBranch($this->hr);

        return $this->post(route('hr.applications.account-request', $id), ['expected_lock_version' => $this->row($id)->lock_version,
            'idempotency_key' => (string) Str::uuid()]);
    }

    private function leave(string $token): TestResponse
    {
        auth()->logout();

        return $this->withCookie('kpone_career_status', $token)->post(route('hr-applications.status.leave-pool'));
    }

    private function statusPage(string $token): TestResponse
    {
        auth()->logout();

        return $this->withCookie('kpone_career_status', $token)->get(route('hr-applications.status'));
    }

    private function starts(string $id): array
    {
        return collect(app(InterviewSlots::class)->available($this->row($id)))->flatMap(fn (array $d): array => array_column($d['slots'], 'start'))->values()->all();
    }

    private function row(string $id): \stdClass
    {
        return DB::table('hr_job_applications')->where('public_id', $id)->sole();
    }

    /** @return array{0:string, 1:string} an application at Scheduling and its private link token */
    private function scheduled(string $title, bool $pool = false): array
    {
        [$id, $token] = $this->filedWithToken($title, $pool);
        $this->selectBranch($this->hr);
        $this->post(route('hr.applications.move', $id), ['to' => 'phone_screening', 'expected_lock_version' => $this->row($id)->lock_version,
            'idempotency_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
        $this->post(route('hr.applications.screen', $id), ['outcome' => 'pass', 'rating' => 4, 'expected_lock_version' => $this->row($id)->lock_version,
            'idempotency_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
        $this->assertSame('scheduling', $this->row($id)->status);
        auth()->logout();

        return [$id, $token];
    }

    /** @return array{0:string, 1:string} */
    private function filedWithToken(string $title, bool $pool = false, string $type = 'ca'): array
    {
        $this->selectBranch($this->hr);
        $this->post(route('hr.applications.positions.store'), ['title' => $title, 'branch_id' => 'any', 'position_type' => $type, 'idempotency_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
        if (! DB::table('hr_job_application_settings')->exists()) {
            $this->post(route('hr.applications.retention'), ['retention_days' => 3650, 'expected_version' => 0, 'idempotency_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
        }
        $position = (string) DB::table('hr_job_positions')->where('title', $title)->value('public_id');
        config(['hr-applications.enabled' => true]);
        auth()->logout();
        // Each send in its own minute, the form opened 30 seconds before (kept within the start day).
        $this->travel(1)->minutes();
        $formToken = JobApplicationIntake::issueToken();
        $this->travel(30)->seconds();
        $this->post(route('hr-applications.submit'), ['form_token' => $formToken, 'position' => $position, 'name' => 'Synthetic Applicant',
            'phone' => '012-345 6789', 'email' => 'applicant@example.test', 'privacy_accepted' => '1', 'website' => '', 'preferred_branch' => 'any',
            'start_date' => now()->addDays(14)->toDateString(), 'education' => 'degree', 'years_experience' => '2', 'languages' => ['malay', 'english'], ...($pool ? ['talent_pool' => '1'] : []),
            'cv' => UploadedFile::fake()->createWithContent('cv.pdf', self::PDF)])->assertRedirect(route('hr-applications.thanks'));
        $link = null;
        $this->get(route('hr-applications.thanks'))->assertInertia(function (Assert $page) use (&$link): void {
            $link = $page->toArray()['props']['statusLink'];
        });
        $token = Str::after((string) $link, '#');
        $this->assertTrue(CandidateLink::valid($token));

        return [(string) DB::table('hr_job_applications')->orderByDesc('id')->value('public_id'), $token];
    }

    /** @param list<string> $starts */
    private function pick(string $token, array $starts): TestResponse
    {
        auth()->logout();

        return $this->withCookie('kpone_career_status', $token)->post(route('hr-applications.status.picks'), ['starts' => $starts]);
    }

    private function confirm(string $id, string $start): TestResponse
    {
        $this->selectBranch($this->hr);

        return $this->post(route('hr.applications.interview.confirm', $id), ['start' => $start, 'expected_lock_version' => $this->row($id)->lock_version,
            'idempotency_key' => (string) Str::uuid()]);
    }

    private function cancel(string $id, ?string $reason): TestResponse
    {
        $this->selectBranch($this->hr);

        return $this->post(route('hr.applications.interview.cancel', $id), ['reason' => $reason, 'expected_lock_version' => $this->row($id)->lock_version,
            'idempotency_key' => (string) Str::uuid()]);
    }

    private function assertDatabaseRefuses(callable $write, string $code = 'P0001'): void
    {
        try {
            DB::transaction(fn () => $write());
            $this->fail('PostgreSQL accepted a write it must refuse.');
        } catch (QueryException $e) {
            $this->assertSame($code, $e->errorInfo[0] ?? null, $e->getMessage());
        }
    }
}
