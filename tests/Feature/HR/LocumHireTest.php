<?php

namespace Tests\Feature\HR;

use App\Domain\HR\Applications\Services\CandidateLink;
use App\Domain\HR\Applications\Services\InterviewSlots;
use App\Domain\HR\Applications\Services\JobApplicationIntake;
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
 * HR-15d (owner, via B.E.R.N.I.S.S, 18 Nov 2026): a passed candidate for a Locum position becomes a locum file in one
 * step, with a sign-in request using the email they applied with. Nothing creates an account, a role or a branch
 * assignment; the staff admin still creates the account from the Staff page. Synthetic `.test` data only.
 */
class LocumHireTest extends VisitTestCase
{
    private const PDF = "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\nSYNTHETIC-CV-CONTENT\n%%EOF\n";

    private User $hr;

    /** @var array<string, string> */
    private array $tokens = [];

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

    public function test_a_passed_locum_candidate_becomes_a_locum_file_with_a_sign_in_request_in_one_step(): void
    {
        [$passed] = $this->closed('Synthetic Locum', 'passed', false);
        $before = [DB::table('users')->count(), DB::table('model_has_roles')->count(), DB::table('staff_branch_assignments')->count()];
        $key = (string) Str::uuid();

        $this->makeFile($passed, $key)->assertSessionHasNoErrors();
        // The same key again changes nothing; a new key is refused (one file per candidate).
        $this->makeFile($passed, $key)->assertSessionHasNoErrors();
        $this->makeFile($passed)->assertSessionHasErrors(['application' => 'A locum file was already made for this candidate.']);

        $worker = DB::table('hr_workers')->where('kind', 'locum')->sole();
        $this->assertSame(['Synthetic Applicant', null], [$worker->display_name, $worker->user_id]);
        $this->assertSame('0123456789', Crypt::decryptString((string) $worker->locum_phone_encrypted));
        $signIn = DB::table('hr_locum_sign_ins')->sole();
        $this->assertSame(['open', $worker->id, 'applicant@example.test'], [$signIn->status, $signIn->worker_id, Crypt::decryptString((string) $signIn->email_encrypted)]);
        $hire = DB::table('hr_locum_hires')->sole();
        $this->assertSame([(int) $this->row($passed)->id, $worker->id, $signIn->id, $this->hr->id],
            [(int) $hire->application_id, (int) $hire->worker_id, (int) $hire->sign_in_request_id, (int) $hire->made_by_user_id]);
        $this->assertSame(1, DB::table('hr_job_application_events')->where('action', 'locum_file_made')->count());
        $this->assertSame(1, DB::table('audit_logs')->where('event', 'hr.applications.locum_file_made')->count());
        $this->assertSame(1, DB::table('audit_logs')->where('event', 'hr.records.locum_added')->count());
        $this->assertSame(1, DB::table('audit_logs')->where('event', 'hr.records.locum_sign_in_requested')->count());
        // No account, role or branch assignment is created; the staff admin does that from the Staff page.
        $this->assertSame($before, [DB::table('users')->count(), DB::table('model_has_roles')->count(), DB::table('staff_branch_assignments')->count()]);

        // HR sees the file and its sign-in state on the application.
        $this->selectBranch($this->hr);
        $this->get(route('hr.applications.index', ['status' => 'passed']))->assertInertia(fn (Assert $p) => $p
            ->where('applications.applications.rows.0.locumHire.workerId', $worker->public_id)
            ->where('applications.applications.rows.0.locumHire.name', 'Synthetic Applicant')
            ->where('applications.applications.rows.0.locumHire.signIn', 'Sign-in asked for, waiting on the Staff page'));

        // The link is append-only.
        $this->assertDatabaseRefuses(fn () => DB::table('hr_locum_hires')->update(['made_by_user_id' => $this->hr->id]));
        $this->assertDatabaseRefuses(fn () => DB::table('hr_locum_hires')->delete());
    }

    public function test_only_a_passed_candidate_for_a_locum_position_and_only_hiring_managers(): void
    {
        [$failed] = $this->closed('Synthetic Locum', 'failed', false);
        [$assistant] = $this->closed('Synthetic Assistant', 'passed', false, 'ca');
        [$passed] = $this->closed('Synthetic Locum Two', 'passed', false);

        $this->makeFile($failed)->assertSessionHasErrors(['application' => 'A locum file can be made only for a candidate who passed.']);
        $this->makeFile($assistant)->assertSessionHasErrors(['application' => 'Only a candidate for a Locum position becomes a locum file.']);
        $this->assertSame(0, DB::table('hr_workers')->where('kind', 'locum')->count());

        foreach (['ca', 'finance_officer', 'technical_admin'] as $role) {
            $other = $this->actor($role);
            $this->selectBranch($other);
            $this->post(route('hr.applications.locum-file', $passed), ['expected_lock_version' => $this->row($passed)->lock_version,
                'idempotency_key' => (string) Str::uuid()])->assertForbidden();
        }

        // An open staff-account request must be cancelled first, so one candidate never gets both.
        $this->selectBranch($this->hr);
        $this->post(route('hr.applications.account-request', $passed), ['expected_lock_version' => $this->row($passed)->lock_version,
            'idempotency_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
        $this->makeFile($passed)->assertSessionHasErrors(['application' => 'A staff account was already asked for. Cancel it first to make a locum file instead.']);
        $this->assertSame(0, DB::table('hr_locum_hires')->count());

        // PostgreSQL also refuses a hire row for a candidate who did not pass.
        $worker = DB::table('hr_workers')->insertGetId(['public_id' => (string) Str::uuid(), 'organisation_id' => $this->hr->organisation_id, 'kind' => 'locum',
            'display_name' => 'Synthetic', 'created_by_user_id' => $this->hr->id, 'lock_version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $this->assertDatabaseRefuses(fn () => DB::table('hr_locum_hires')->insert(['organisation_id' => $this->hr->organisation_id, 'application_id' => $this->row($failed)->id,
            'worker_id' => $worker, 'sign_in_request_id' => 1, 'made_by_user_id' => $this->hr->id, 'created_at' => now()]), '23514');
    }

    private function makeFile(string $id, ?string $key = null): TestResponse
    {
        $this->selectBranch($this->hr);

        return $this->post(route('hr.applications.locum-file', $id), ['expected_lock_version' => $this->row($id)->lock_version,
            'idempotency_key' => $key ?? (string) Str::uuid()]);
    }

    /**
     * An application through phone screening, a confirmed interview and its result.
     *
     * @return array{0:string, 1:string}
     */
    private function closed(string $title, string $result, bool $pool, string $type = 'locum'): array
    {
        [$id, $token] = $this->scheduled($title, $pool, $type);
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

    private function starts(string $id): array
    {
        return collect(app(InterviewSlots::class)->available($this->row($id)))->flatMap(fn (array $d): array => array_column($d['slots'], 'start'))->values()->all();
    }

    private function row(string $id): \stdClass
    {
        return DB::table('hr_job_applications')->where('public_id', $id)->sole();
    }

    /** @return array{0:string, 1:string} an application at Scheduling and its private link token */
    private function scheduled(string $title, bool $pool = false, string $type = 'locum'): array
    {
        [$id, $token] = $this->filedWithToken($title, $pool, $type);
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
            'start_date' => now()->addDays(14)->toDateString(), 'education' => 'degree', 'years_experience' => '2', 'languages' => ['malay', 'english'], ...($pool ? ['talent_pool' => '1'] : []), ...($type === 'locum' ? ['availability' => 'Weekday evenings and Saturday mornings'] : []),
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
