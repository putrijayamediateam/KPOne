<?php

namespace Tests\Feature\HR;

use App\Domain\Access\PermissionCatalogue;
use App\Domain\HR\Applications\ApplicationPermissions;
use App\Domain\HR\Applications\Services\JobApplicationIntake;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Visit\VisitTestCase;

/**
 * HR-13 job applications (owner, 2026-11-07: "start the HR-13 build"; positions kept in KPOne, retention entered by HR
 * with no number shipped; HR-PLAN 15.2). Synthetic `.test` data only.
 */
class JobApplicationsTest extends VisitTestCase
{
    private const PDF = "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\nSYNTHETIC-CV-CONTENT\n%%EOF\n";

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(JobApplicationIntake::DISK);
        RateLimiter::clear('hr-apply-all');
        $this->travelTo(CarbonImmutable::parse('2026-11-07 10:00', $this->branch->timezone));
    }

    public function test_grants_are_exact_and_the_public_form_is_off_by_default(): void
    {
        $holders = collect(PermissionCatalogue::roles())->filter(fn (array $p) => in_array(ApplicationPermissions::MANAGE, $p, true))->keys()->sort()->values()->all();
        $this->assertSame(['director', 'hr_manager'], $holders);
        $this->assertFalse(PermissionCatalogue::isAdministrativeAuthority(ApplicationPermissions::MANAGE));

        // Off unless HR_APPLICATIONS_ENABLED is true: every public address is 404 and nothing is stored.
        $this->assertFalse((bool) config('hr-applications.enabled'));
        $this->get(route('hr-applications.show'))->assertNotFound();
        $this->get(route('hr-applications.thanks'))->assertNotFound();
        $this->post(route('hr-applications.submit'), [])->assertNotFound();

        foreach (['ca', 'queue_display', 'finance_officer', 'technical_admin'] as $role) {
            $user = $this->actor($role);
            $this->selectBranch($user);
            $this->get(route('hr.applications.index'))->assertForbidden();
        }
        $hr = $this->actor('hr_manager');
        $this->selectBranch($hr);
        $this->get(route('hr.applications.index'))->assertOk()->assertInertia(fn (Assert $page) => $page->component('HR/Applications')
            ->where('applications.form.enabled', false)->where('applications.settings.retentionDays', null)->where('applications.positions', []));
    }

    public function test_the_form_stays_closed_until_hr_saves_how_long_applications_are_kept(): void
    {
        $hr = $this->actor('hr_manager');
        $this->selectBranch($hr);
        $this->post(route('hr.applications.positions.store'), ['title' => 'Synthetic Cleaner', 'branch_id' => 'any', 'idempotency_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
        config(['hr-applications.enabled' => true]);

        // The switch is on, but the privacy notice promises deletion after a period nobody has set (Daniel, HR-13).
        $this->get(route('hr-applications.show'))->assertNotFound();
        $this->post(route('hr-applications.submit'), [])->assertNotFound();
        $this->get(route('hr.applications.index'))->assertInertia(fn (Assert $page) => $page->where('applications.form.state', 'needs_retention'));

        $this->post(route('hr.applications.retention'), ['retention_days' => 365, 'expected_version' => 0, 'idempotency_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
        $this->get(route('hr-applications.show'))->assertOk();
        $this->get(route('hr.applications.index'))->assertInertia(fn (Assert $page) => $page->where('applications.form.state', 'on'));

        // Clearing the period closes the form again.
        $this->post(route('hr.applications.retention'), ['retention_days' => '', 'expected_version' => 1, 'idempotency_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
        $this->get(route('hr-applications.show'))->assertNotFound();
    }

    public function test_an_applicant_sends_one_application_that_is_encrypted_creates_no_account_and_audits_no_values(): void
    {
        $hr = $this->actor('hr_manager');
        $position = $this->addPosition($hr, 'Synthetic Clinic Assistant');
        $closed = $this->addPosition($hr, 'Synthetic Closed Role');
        $this->closePosition($hr, $closed);
        config(['hr-applications.enabled' => true]);
        $users = User::query()->count();

        $this->get(route('hr-applications.show'))->assertOk()->assertInertia(fn (Assert $page) => $page->component('Careers/Apply')
            ->where('positions', [['id' => $position, 'title' => 'Synthetic Clinic Assistant', 'branch' => null, 'type' => 'ca']])
            ->where('privacyNoticeVersion', 'draft-hr17-applications-v1')->has('formToken'));

        $token = JobApplicationIntake::issueToken();
        $this->travel(30)->seconds();
        $this->apply(['form_token' => $token, 'position' => $position])->assertRedirect(route('hr-applications.thanks'));
        $this->get(route('hr-applications.thanks'))->assertOk()->assertInertia(fn (Assert $page) => $page->component('Careers/Thanks'))
            ->assertDontSee('Synthetic Applicant')->assertDontSee('applicant@example.test');

        $row = DB::table('hr_job_applications')->sole();
        $this->assertSame('new', $row->status);
        foreach (['name_encrypted', 'phone_encrypted', 'email_encrypted', 'message_encrypted'] as $column) {
            $this->assertStringNotContainsString('Synthetic', (string) $row->{$column});
        }
        $stored = Storage::disk(JobApplicationIntake::DISK)->get((string) $row->cv_path);
        $this->assertIsString($stored);
        $this->assertStringNotContainsString('SYNTHETIC-CV-CONTENT', $stored);
        $this->assertStringNotContainsString('cv.pdf', (string) $row->cv_path);
        $this->assertSame('draft-hr17-applications-v1', $row->privacy_notice_version);
        $this->assertSame(['submitted'], DB::table('hr_job_application_events')->pluck('action')->all());
        $this->assertSame($users, User::query()->count());

        // The same form cannot file twice.
        $this->apply(['form_token' => $token, 'position' => $position])->assertSessionHasErrors('form');
        // A closed position, an expired form and a stale token are refused.
        $fresh = JobApplicationIntake::issueToken();
        $this->travel(10)->seconds();
        $this->apply(['form_token' => $fresh, 'position' => $closed])->assertSessionHasErrors('position');
        $this->travel(61)->minutes();
        $this->apply(['form_token' => $fresh, 'position' => $position])->assertSessionHasErrors('form');
        $this->apply(['form_token' => 'not-a-token', 'position' => $position])->assertSessionHasErrors('form');
        $this->assertSame(1, DB::table('hr_job_applications')->count());

        $audit = DB::table('audit_logs')->where('event', 'like', 'hr.applications.%')->get();
        $this->assertContains('hr.applications.received', $audit->pluck('event')->all());
        foreach ($audit as $log) {
            $json = json_encode($log, JSON_THROW_ON_ERROR);
            foreach (['Synthetic Applicant', 'applicant@example.test', '012-345 6789', 'Synthetic note'] as $value) {
                $this->assertStringNotContainsString($value, $json);
            }
        }
    }

    public function test_robots_get_the_thank_you_page_and_nothing_is_stored(): void
    {
        $position = $this->addPosition($this->actor('hr_manager'), 'Synthetic Doctor');
        config(['hr-applications.enabled' => true]);

        // Too fast: under five seconds after the form was opened.
        $this->apply(['form_token' => JobApplicationIntake::issueToken(), 'position' => $position], null, false)->assertRedirect(route('hr-applications.thanks'));
        // The hidden field filled in.
        $token = JobApplicationIntake::issueToken();
        $this->travel(30)->seconds();
        $this->apply(['form_token' => $token, 'position' => $position, 'website' => 'http://spam.test'])->assertRedirect(route('hr-applications.thanks'));

        // A person who presses Send too soon on an empty form sees what to fill in, not the thank-you page.
        $this->apply(['form_token' => JobApplicationIntake::issueToken(), 'position' => $position, 'name' => ''], false, false)
            ->assertSessionHasErrors(['name', 'cv']);
        // Nothing typed goes into the session (Suresh, HR-13 M3).
        $this->assertNull(session()->getOldInput('message'));
        $this->assertSame(0, DB::table('hr_job_applications')->count());
        $this->assertSame([], Storage::disk(JobApplicationIntake::DISK)->allFiles());
    }

    public function test_the_server_checks_every_field_and_the_cv_by_its_content(): void
    {
        $position = $this->addPosition($this->actor('hr_manager'), 'Synthetic Nurse');
        config(['hr-applications.enabled' => true]);
        $token = JobApplicationIntake::issueToken();
        $this->travel(30)->seconds();

        $this->apply(['form_token' => $token, 'position' => $position], UploadedFile::fake()->createWithContent('cv.pdf', "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00"))
            ->assertSessionHasErrors(['cv' => JobApplicationIntake::NOT_PDF]);
        $this->apply(['form_token' => $token, 'position' => $position], UploadedFile::fake()->createWithContent('cv.pdf', "%PDF-1.4\nno end marker"))
            ->assertSessionHasErrors('cv');
        $this->apply(['form_token' => $token, 'position' => $position], UploadedFile::fake()->createWithContent('cv.pdf', "%PDF-1.4\n".str_repeat('a', 5 * 1024 * 1024)."\n%%EOF\n"))
            ->assertSessionHasErrors('cv');
        $this->apply(['form_token' => $token, 'position' => $position], false)->assertSessionHasErrors(['cv' => 'Attach your CV as a PDF. / Lampirkan CV anda dalam bentuk PDF.']);
        $this->apply(['form_token' => $token, 'position' => $position, 'privacy_accepted' => '0'])->assertSessionHasErrors('privacy_accepted');
        $this->apply(['form_token' => $token, 'position' => $position, 'name' => 'A', 'phone' => 'call me', 'email' => 'nope', 'message' => str_repeat('x', 1001)])
            ->assertSessionHasErrors(['name', 'phone', 'email', 'message']);
        $this->apply(['form_token' => $token, 'position' => 'not-a-uuid'])->assertSessionHasErrors('position');

        $this->assertSame(0, DB::table('hr_job_applications')->count());
        $this->assertSame([], Storage::disk(JobApplicationIntake::DISK)->allFiles());
    }

    public function test_hr_moves_an_application_through_its_steps_and_a_closed_one_stays_closed(): void
    {
        $hr = $this->actor('hr_manager');
        $id = $this->filedApplication($hr);
        $this->selectBranch($hr);

        $this->move($hr, $id, 'offered', 1)->assertSessionHasErrors('to');
        // HR-17a stages (owner, 2026-11-10): nothing new moves to HR-13's Shortlisted.
        $this->move($hr, $id, 'shortlisted', 1)->assertSessionHasErrors('to');
        $this->move($hr, $id, 'phone_screening', 1)->assertSessionHasNoErrors();
        // A stale page is refused.
        $this->move($hr, $id, 'interview', 1)->assertSessionHasErrors('records');
        // Scheduling needs a recorded phone screening.
        $this->move($hr, $id, 'scheduling', 2)->assertSessionHasErrors('to');
        $this->move($hr, $id, 'not_taken_forward', 2)->assertSessionHasNoErrors();
        $row = DB::table('hr_job_applications')->sole();
        $this->assertSame('not_taken_forward', $row->status);
        $this->assertNotNull($row->closed_at);
        $this->move($hr, $id, 'withdrawn', 3)->assertSessionHasErrors('to');

        // A candidate who passes the call goes on to the interview and its result; the result is final.
        $passed = $this->filedApplication($hr, 'Synthetic Passed Role');
        $this->selectBranch($hr);
        $this->move($hr, $passed, 'phone_screening', 1)->assertSessionHasNoErrors();
        $this->post(route('hr.applications.screen', $passed), ['outcome' => 'pass', 'rating' => 4, 'ticked' => [], 'expected_lock_version' => 2,
            'idempotency_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
        $this->move($hr, $passed, 'interview', 3)->assertSessionHasNoErrors();
        $this->move($hr, $passed, 'passed', 4)->assertSessionHasNoErrors();
        $this->move($hr, $passed, 'withdrawn', 5)->assertSessionHasErrors('to');
        $this->assertSame('passed', DB::table('hr_job_applications')->where('public_id', $passed)->value('status'));
        $row = DB::table('hr_job_applications')->where('public_id', $id)->sole();
        $this->assertSame(['submitted', 'status_changed', 'status_changed'], DB::table('hr_job_application_events')->where('application_id', $row->id)->orderBy('id')->pluck('action')->all());

        // PostgreSQL holds the same lines.
        foreach ([
            fn () => DB::table('hr_job_applications')->where('id', $row->id)->update(['status' => 'new', 'closed_at' => null]),
            fn () => DB::table('hr_job_applications')->where('id', $row->id)->update(['name_encrypted' => 'changed']),
            fn () => DB::table('hr_job_applications')->where('id', $row->id)->delete(),
            fn () => DB::table('hr_job_application_events')->where('application_id', $row->id)->update(['action' => 'purged']),
        ] as $write) {
            try {
                DB::transaction($write);
                $this->fail('PostgreSQL accepted a write it must refuse.');
            } catch (QueryException $e) {
                $this->assertStringContainsString('P0001', $e->getMessage());
            }
        }
    }

    public function test_opening_a_cv_is_recorded_before_any_byte_is_sent(): void
    {
        $hr = $this->actor('hr_manager');
        $id = $this->filedApplication($hr);
        $this->selectBranch($hr);

        $response = $this->get(route('hr.applications.cv', $id))->assertOk();
        $this->assertSame(self::PDF, $response->getContent());
        $response->assertHeader('Content-Type', 'application/pdf')->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertSame(1, DB::table('hr_job_application_events')->where('action', 'cv_opened')->count());
        $this->assertSame(1, DB::table('audit_logs')->where('event', 'hr.applications.cv_opened')->count());

        $ca = $this->actor('ca');
        $this->selectBranch($ca);
        $this->get(route('hr.applications.cv', $id))->assertForbidden();
        $this->assertSame(1, DB::table('hr_job_application_events')->where('action', 'cv_opened')->count());
    }

    public function test_closed_applications_are_cleared_only_after_the_retention_hr_sets(): void
    {
        $hr = $this->actor('hr_manager');
        $closedId = $this->filedApplication($hr);
        $openId = $this->filedApplication($hr, 'Synthetic Second Role');
        $this->selectBranch($hr);
        $this->move($hr, $closedId, 'withdrawn', 1)->assertSessionHasNoErrors();

        // Ten years in force: nothing is cleared after 400 days.
        $this->travel(400)->days();
        Artisan::call('hr-applications:purge');
        $this->assertSame(0, DB::table('hr_job_applications')->whereNotNull('purged_at')->count());

        // A shorter retention waits the grace days.
        $this->post(route('hr.applications.retention'), ['retention_days' => 'abc', 'expected_version' => 1, 'idempotency_key' => (string) Str::uuid()])->assertSessionHasErrors('retention_days');
        $this->post(route('hr.applications.retention'), ['retention_days' => 30, 'expected_version' => 0, 'idempotency_key' => (string) Str::uuid()])->assertSessionHasErrors('retention_days');
        $this->post(route('hr.applications.retention'), ['retention_days' => 30, 'expected_version' => 1, 'idempotency_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
        Artisan::call('hr-applications:purge');
        $this->assertSame(0, DB::table('hr_job_applications')->whereNotNull('purged_at')->count());
        $this->travel(8)->days();
        Artisan::call('hr-applications:purge');

        $closed = DB::table('hr_job_applications')->where('public_id', $closedId)->sole();
        $this->assertNotNull($closed->purged_at);
        $this->assertNull($closed->name_encrypted);
        $this->assertNull($closed->cv_path);
        $this->assertSame('withdrawn', $closed->status);
        // An application nobody closed counts from when it was sent, so it is cleared too (Suresh, HR-13 M1).
        $open = DB::table('hr_job_applications')->where('public_id', $openId)->sole();
        $this->assertNotNull($open->purged_at);
        $this->assertSame('new', $open->status);
        $this->assertSame([], Storage::disk(JobApplicationIntake::DISK)->allFiles());
        // A new application within the retention is kept.
        $recent = $this->filedApplication($hr, 'Synthetic Third Role');
        Artisan::call('hr-applications:purge');
        $this->assertNull(DB::table('hr_job_applications')->where('public_id', $recent)->value('purged_at'));
        $this->get(route('hr.applications.cv', $closedId))->assertRedirect();
        // A purged application stays purged.
        $this->expectException(QueryException::class);
        DB::transaction(fn () => DB::table('hr_job_applications')->where('id', $closed->id)->update(['purged_at' => null]));
    }

    public function test_the_public_form_is_rate_limited(): void
    {
        $position = $this->addPosition($this->actor('hr_manager'), 'Synthetic Receptionist');
        config(['hr-applications.enabled' => true]);
        foreach (range(1, 3) as $ignored) {
            $this->apply(['form_token' => 'stale', 'position' => $position], null, false)->assertSessionHasErrors('form');
        }
        $this->apply(['form_token' => 'stale', 'position' => $position], null, false)->assertStatus(429);
    }

    private function addPosition(User $hr, string $title): string
    {
        $this->selectBranch($hr);
        $this->post(route('hr.applications.positions.store'), ['title' => $title, 'branch_id' => 'any', 'idempotency_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
        // The form opens only once a retention period is saved (Daniel, HR-13); ten years here, so nothing is cleared.
        if (! DB::table('hr_job_application_settings')->exists()) {
            $this->post(route('hr.applications.retention'), ['retention_days' => 3650, 'expected_version' => 0, 'idempotency_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
        }

        return (string) DB::table('hr_job_positions')->where('title', $title)->value('public_id');
    }

    private function closePosition(User $hr, string $position): void
    {
        $this->selectBranch($hr);
        $row = DB::table('hr_job_positions')->where('public_id', $position)->sole();
        $this->post(route('hr.applications.positions.update', $position), ['title' => $row->title, 'branch_id' => 'any', 'is_open' => false,
            'expected_lock_version' => $row->lock_version, 'idempotency_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
    }

    private function filedApplication(User $hr, string $title = 'Synthetic Pharmacist'): string
    {
        $position = $this->addPosition($hr, $title);
        config(['hr-applications.enabled' => true]);
        $token = JobApplicationIntake::issueToken();
        $this->travel(30)->seconds();
        $this->apply(['form_token' => $token, 'position' => $position])->assertRedirect(route('hr-applications.thanks'));
        auth()->logout();

        return (string) DB::table('hr_job_applications')->orderByDesc('id')->value('public_id');
    }

    private function move(User $hr, string $id, string $to, int $lock): TestResponse
    {
        $this->actingAs($hr);

        return $this->post(route('hr.applications.move', $id), ['to' => $to, 'expected_lock_version' => $lock, 'idempotency_key' => (string) Str::uuid()]);
    }

    /** @param array<string, mixed> $overrides */
    private function apply(array $overrides, UploadedFile|false|null $cv = null, bool $wait = true): TestResponse
    {
        if ($wait) {
            // The form allows three sends a minute from one address; each call here starts a new minute.
            $this->travel(61)->seconds();
        }
        $cv ??= UploadedFile::fake()->createWithContent('cv.pdf', self::PDF);
        $data = [...['name' => 'Synthetic Applicant', 'phone' => '012-345 6789', 'email' => 'applicant@example.test', 'message' => 'Synthetic note',
            'privacy_accepted' => '1', 'website' => '',
            // HR-17a's questions every position asks (HR-PLAN 20.4).
            'preferred_branch' => 'any', 'start_date' => now()->addDays(14)->toDateString(), 'education' => 'degree', 'years_experience' => '2',
            'languages' => ['malay', 'english']], ...$overrides];
        if ($cv !== false) {
            $data['cv'] = $cv;
        }

        return $this->post(route('hr-applications.submit'), $data);
    }
}
