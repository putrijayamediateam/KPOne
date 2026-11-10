<?php

namespace Tests\Feature\HR;

use App\Domain\HR\Applications\HiringTemplates;
use App\Domain\HR\Applications\Services\CandidateLink;
use App\Domain\HR\Applications\Services\HiringDeadlines;
use App\Domain\HR\Applications\Services\JobApplicationIntake;
use App\Domain\HR\Applications\Services\JobApplicationService;
use App\Mail\HiringMessageMail;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use InvalidArgumentException;
use Tests\Feature\Visit\VisitTestCase;

/**
 * HR-17b (HR-PLAN 19.2, 19.3, 19.5 and 19.9): the candidate's private link and status page, the fixed hiring messages
 * (shown in KPOne, emailed only when switched on), the daily auto-reject with its reminder to the hiring mailbox, and
 * "Needs you". Synthetic `.test` data only; nothing is really sent (Mail::fake).
 */
class HiringMessagesTest extends VisitTestCase
{
    private const PDF = "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\nSYNTHETIC-CV-CONTENT\n%%EOF\n";

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(JobApplicationIntake::DISK);
        Mail::fake();
        RateLimiter::clear('hr-apply-all');
        // A Tuesday.
        $this->travelTo(CarbonImmutable::parse('2026-11-10 10:00', $this->branch->timezone));
    }

    public function test_the_private_link_is_shown_once_kept_only_as_a_hash_and_opens_a_status_page_without_personal_values(): void
    {
        $hr = $this->actor('hr_manager');
        $position = $this->addPosition($hr, 'Synthetic Assistant');
        config(['hr-applications.enabled' => true]);
        auth()->logout();

        $this->apply($position)->assertRedirect(route('hr-applications.thanks'));
        $link = null;
        $this->get(route('hr-applications.thanks'))->assertInertia(function (Assert $page) use (&$link): void {
            $link = $page->toArray()['props']['statusLink'];
        });
        $this->assertIsString($link);
        $this->assertStringStartsWith(route('hr-applications.status').'#', $link);
        $token = Str::after($link, '#');
        $this->assertTrue(CandidateLink::valid($token));
        // Shown once: the next visit has no link, and the session never held the raw token.
        $this->get(route('hr-applications.thanks'))->assertInertia(fn (Assert $page) => $page->where('statusLink', null));

        $row = DB::table('hr_job_applications')->sole();
        $this->assertSame(CandidateLink::hash($token), $row->status_token_hash);
        $this->assertStringNotContainsString($token, json_encode(DB::table('hr_job_applications')->get(), JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString($token, json_encode(DB::table('audit_logs')->get(), JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString($token, json_encode(session()->all(), JSON_THROW_ON_ERROR));

        // Without the cookie the page shows nothing; a wrong token is refused and sets no cookie.
        $this->get(route('hr-applications.status'))->assertOk()->assertInertia(fn (Assert $page) => $page->component('Careers/Status')->where('application', null));
        $this->post(route('hr-applications.status.exchange'), ['token' => str_repeat('A', 43)])
            ->assertRedirect(route('hr-applications.status'))->assertSessionHasErrors('link')->assertCookieExpired('kpone_career_status');

        $response = $this->post(route('hr-applications.status.exchange'), ['token' => $token])->assertRedirect(route('hr-applications.status'))
            ->assertSessionHasNoErrors()->assertCookie('kpone_career_status');
        $cookie = collect($response->headers->getCookies())->first(fn ($c) => $c->getName() === 'kpone_career_status');
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertSame('/careers/status', $cookie->getPath());

        $page = $this->withCookie('kpone_career_status', $token)->get(route('hr-applications.status'))->assertOk();
        $page->assertInertia(fn (Assert $p) => $p->where('application.stage', 'new')->where('application.stageLabel', 'We have your application')
            ->where('application.position', 'Synthetic Assistant')->where('application.open', true)
            ->has('application.messages', 1)->where('application.messages.0.label', 'Application received'));
        $json = json_encode($page->viewData('page'), JSON_THROW_ON_ERROR);
        foreach (['Synthetic Applicant', 'applicant@example.test', '012-345', $token] as $private) {
            $this->assertStringNotContainsString($private, $json);
        }
        // The message shown again never carries the link.
        $this->assertStringContainsString('shown once, when you applied', $json);
    }

    public function test_messages_are_shown_in_kpone_while_email_is_off_and_emailed_once_when_it_is_on(): void
    {
        $hr = $this->actor('hr_manager');
        $position = $this->addPosition($hr, 'Synthetic Assistant');
        config(['hr-applications.enabled' => true]);
        auth()->logout();

        $this->apply($position)->assertRedirect(route('hr-applications.thanks'));
        $this->assertSame('off', DB::table('hr_job_application_messages')->sole()->status);
        Mail::assertNothingSent();

        config(['hr-applications.email_enabled' => true]);
        $this->apply($position, ['email' => 'second@example.test'])->assertRedirect(route('hr-applications.thanks'));
        $message = DB::table('hr_job_application_messages')->orderByDesc('id')->first();
        $this->assertSame(['received', 'candidate', 'new', 'sent'], [$message->template, $message->recipient, $message->stage, $message->status]);
        Mail::assertSent(HiringMessageMail::class, fn (HiringMessageMail $mail): bool => $mail->hasTo('second@example.test')
            && str_contains($mail->bodyText, route('hr-applications.status').'#') && str_contains($mail->bodyText, 'Synthetic Assistant'));
        Mail::assertSentCount(1);

        // HR's decisions tell the candidate, once per stage, and never carry the rating, the note or the reason.
        $id = (string) DB::table('hr_job_applications')->orderByDesc('id')->value('public_id');
        $this->move($hr, $id, 'phone_screening', 1)->assertSessionHasNoErrors();
        $this->screen($hr, $id, ['outcome' => 'reject', 'rating' => 2, 'reject_reason' => 'availability', 'note' => 'SYNTHETIC-NOTE'], 2)->assertSessionHasNoErrors();
        Mail::assertSentCount(2);
        Mail::assertSent(HiringMessageMail::class, fn (HiringMessageMail $mail): bool => str_contains($mail->bodyText, 'will not take your application forward')
            && ! str_contains($mail->bodyText, 'SYNTHETIC-NOTE') && ! str_contains($mail->bodyText, 'Availability'));
        $this->assertSame(['received', 'not_taken_forward'], DB::table('hr_job_application_messages')->where('application_id', DB::table('hr_job_applications')->where('public_id', $id)->value('id'))
            ->orderBy('id')->pluck('template')->all());

        // HR sees each message with its outcome in words.
        $this->get(route('hr.applications.index', ['status' => 'not_taken_forward']))->assertInertia(fn (Assert $page) => $page
            ->where('applications.applications.rows.0.messages.0.label', 'Not taken forward')
            ->where('applications.applications.rows.0.messages.0.statusLabel', 'Emailed')
            ->where('applications.applications.rows.0.messages.1.label', 'Application received'));

        // A message row keeps what it refers to, and a second one for the same stage is not written.
        $this->assertDatabaseRefuses(fn () => DB::table('hr_job_application_messages')->where('template', 'received')->orderBy('id')->limit(1)->update(['status' => 'sent']));
        $this->assertDatabaseRefuses(fn () => DB::table('hr_job_application_messages')->delete());
    }

    public function test_a_failed_email_is_recorded_and_the_hiring_change_stays(): void
    {
        $hr = $this->actor('hr_manager');
        $id = $this->filed($hr, 'Synthetic Assistant');
        config(['hr-applications.email_enabled' => true]);
        Mail::shouldReceive('to')->andThrow(new \RuntimeException('SMTP down'));

        $this->move($hr, $id, 'not_taken_forward', 1)->assertSessionHasNoErrors();
        $this->assertSame('not_taken_forward', DB::table('hr_job_applications')->where('public_id', $id)->value('status'));
        $failed = DB::table('hr_job_application_messages')->where('template', 'not_taken_forward')->sole();
        $this->assertSame(['failed', 'send_failed'], [$failed->status, $failed->error_code]);
    }

    public function test_the_candidate_withdraws_an_open_application_through_the_link(): void
    {
        $hr = $this->actor('hr_manager');
        $position = $this->addPosition($hr, 'Synthetic Assistant');
        config(['hr-applications.enabled' => true]);
        auth()->logout();
        $token = $this->applyAndGetToken($position);

        $this->post(route('hr-applications.status.withdraw'))->assertSessionHasErrors('link');
        $this->withCookie('kpone_career_status', $token)->post(route('hr-applications.status.withdraw'))
            ->assertRedirect(route('hr-applications.status'))->assertSessionHasNoErrors();
        $row = DB::table('hr_job_applications')->sole();
        $this->assertSame('withdrawn', $row->status);
        $this->assertNotNull($row->closed_at);
        $this->assertSame(['submitted', 'candidate_withdrew', 'status_changed'], DB::table('hr_job_application_events')->orderBy('id')->pluck('action')->all());
        $this->assertNull(DB::table('hr_job_application_events')->where('action', 'status_changed')->value('actor_user_id'));
        $this->assertSame(1, DB::table('audit_logs')->where('event', 'hr.applications.withdrawn_by_candidate')->count());
        $this->assertSame(['received', 'withdrawn'], DB::table('hr_job_application_messages')->orderBy('id')->pluck('template')->all());

        // A closed application cannot be withdrawn again.
        $this->withCookie('kpone_career_status', $token)->post(route('hr-applications.status.withdraw'))->assertSessionHasErrors('withdraw');
        $this->withCookie('kpone_career_status', $token)->get(route('hr-applications.status'))
            ->assertInertia(fn (Assert $p) => $p->where('application.stage', 'withdrawn')->where('application.open', false));
    }

    public function test_three_working_days_without_a_decision_send_one_reminder_then_auto_reject(): void
    {
        $hr = $this->actor('hr_manager');
        $this->workingDays($hr, [1, 2, 3, 4, 5]);
        // Thursday 12 Nov is a public holiday at every branch.
        foreach (DB::table('branches')->where('organisation_id', $hr->organisation_id)->pluck('id') as $branchId) {
            DB::table('hr_public_holidays')->insert(['organisation_id' => $hr->organisation_id, 'branch_id' => $branchId, 'holiday_date' => '2026-11-12',
                'name' => 'Synthetic holiday', 'created_by_user_id' => $hr->id, 'created_at' => now(), 'updated_at' => now()]);
        }
        config(['hr-applications.email_enabled' => true, 'hr-applications.hiring_mailbox' => 'hiring@example.test']);
        $id = $this->filed($hr, 'Synthetic Assistant'); // sent on Tuesday 10 Nov
        $deadlines = app(HiringDeadlines::class);
        $row = fn () => DB::table('hr_job_applications')->where('public_id', $id)->first();
        // Working days after Tuesday: Wed 11, (Thu 12 holiday), Fri 13, Mon 16 = reminder, Tue 17 = closes.
        $this->assertSame(['remind' => '2026-11-16', 'reject' => '2026-11-17'], $deadlines->deadlines($row()));

        $this->runAt('2026-11-13 09:00');
        $this->assertSame([0, 0], $this->lastRun);
        $this->runAt('2026-11-16 08:00');
        $this->assertSame([1, 0], $this->lastRun);
        $this->runAt('2026-11-16 15:00');
        $this->assertSame([0, 0], $this->lastRun); // once only
        $reminder = DB::table('hr_job_application_messages')->where('template', 'auto_reject_reminder')->sole();
        $this->assertSame(['mailbox', 'new', 'sent'], [$reminder->recipient, $reminder->stage, $reminder->status]);
        Mail::assertSent(HiringMessageMail::class, fn (HiringMessageMail $mail): bool => $mail->hasTo('hiring@example.test')
            && ! str_contains($mail->bodyText, 'Synthetic Applicant') && ! str_contains($mail->bodyText, 'applicant@example.test'));

        // "Needs you" lists it as closing, not as new.
        $this->selectBranch($hr);
        $this->get(route('hr.applications.index'))->assertInertia(fn (Assert $page) => $page
            ->where('applications.needsYou.closing.count', 1)->where('applications.needsYou.closing.rows.0.id', $id)->where('applications.needsYou.new.count', 0)
            ->where('applications.needsYou.noWorkingDays', false)->where('applications.applications.rows.0.autoRejectOn', 'Tue 17 Nov 2026')
            ->where('applications.applications.rows.0.autoRejectSoon', true));
        // "Open" shows just that application.
        $this->get(route('hr.applications.index', ['status' => 'new', 'focus' => $id]))->assertInertia(fn (Assert $page) => $page
            ->where('applications.applications.filters.focus', $id)->has('applications.applications.rows', 1)->where('applications.applications.rows.0.id', $id));

        // Nothing is sent at night: the closing waits for 08:00 branch time.
        $this->runAt('2026-11-17 00:20');
        $this->assertSame([0, 0], $this->lastRun);
        $this->runAt('2026-11-17 08:20');
        $this->assertSame([0, 1], $this->lastRun);
        $this->assertSame('auto_rejected', $row()->status);
        // The candidate's own list says "Not taken forward", never that nobody decided.
        $view = app(CandidateLink::class)->view($row());
        $this->assertSame('Not taken forward', $view['stageLabel']);
        $this->assertSame('Not taken forward', $view['messages'][0]['label']);
        $this->assertNotNull($row()->closed_at);
        $this->assertSame(1, DB::table('audit_logs')->where('event', 'hr.applications.auto_rejected')->count());
        Mail::assertSent(HiringMessageMail::class, fn (HiringMessageMail $mail): bool => $mail->hasTo('applicant@example.test') && str_contains($mail->subjectLine, 'Synthetic Assistant'));
        $this->runAt('2026-11-18 09:00');
        $this->assertSame([0, 0], $this->lastRun);
    }

    public function test_an_hr_decision_restarts_the_clock_and_a_branch_without_working_days_never_auto_rejects(): void
    {
        $hr = $this->actor('hr_manager');
        $id = $this->filed($hr, 'Synthetic Assistant');
        // No working days set yet: nothing ever closes, and "Needs you" says so.
        $this->runAt('2026-12-30 09:00');
        $this->assertSame([0, 0], $this->lastRun);
        $this->selectBranch($hr);
        $this->get(route('hr.applications.index'))->assertInertia(fn (Assert $page) => $page->where('applications.needsYou.noWorkingDays', true)
            ->where('applications.needsYou.new.count', 1)->where('applications.needsYou.new.rows.0.name', 'Synthetic Applicant'));

        $this->workingDays($hr, [1, 2, 3, 4, 5]);
        $this->travelTo(CarbonImmutable::parse('2026-12-31 10:00', $this->branch->timezone)); // Thursday
        $this->move($hr, $id, 'phone_screening', 1)->assertSessionHasNoErrors();
        // Counted again from the move: Fri 1, Mon 4, Tue 5 = reminder, Wed 6 = closes.
        $this->assertSame(['remind' => '2027-01-05', 'reject' => '2027-01-06'],
            app(HiringDeadlines::class)->deadlines(DB::table('hr_job_applications')->where('public_id', $id)->first()));
        $this->runAt('2027-01-05 07:00');
        $this->assertSame([0, 0], $this->lastRun); // before 08:00
        $this->runAt('2027-01-05 09:00');
        $this->assertSame([1, 0], $this->lastRun);
        $this->runAt('2027-01-06 09:00');
        $this->assertSame([0, 1], $this->lastRun);
        // No mailbox set: the reminder was shown in KPOne only.
        $this->assertSame('off', DB::table('hr_job_application_messages')->where('template', 'auto_reject_reminder')->value('status'));
    }

    public function test_a_missed_reminder_goes_first_and_the_closing_waits_a_working_day(): void
    {
        $hr = $this->actor('hr_manager');
        $this->workingDays($hr, [1, 2, 3, 4, 5]);
        $id = $this->filed($hr, 'Synthetic Assistant'); // Tuesday 10 Nov: reminder Fri 13, closing Mon 16
        // The scheduler was down until Wednesday 18 Nov: the reminder goes now, and nothing closes the same day.
        $this->runAt('2026-11-18 09:00');
        $this->assertSame([1, 0], $this->lastRun);
        $this->runAt('2026-11-18 16:00');
        $this->assertSame([0, 0], $this->lastRun);
        $this->assertSame('new', DB::table('hr_job_applications')->where('public_id', $id)->value('status'));
        $this->runAt('2026-11-19 09:00');
        $this->assertSame([0, 1], $this->lastRun);
    }

    public function test_the_purge_clears_the_link_and_postgresql_keeps_the_link_and_clock_honest(): void
    {
        $hr = $this->actor('hr_manager');
        $position = $this->addPosition($hr, 'Synthetic Assistant');
        config(['hr-applications.enabled' => true]);
        auth()->logout();
        $token = $this->applyAndGetToken($position);
        $id = (int) DB::table('hr_job_applications')->value('id');

        // The link is never swapped for another, and the stage clock moves only with the stage.
        $this->assertDatabaseRefuses(fn () => DB::table('hr_job_applications')->where('id', $id)->update(['status_token_hash' => str_repeat('a', 64)]));
        $this->assertDatabaseRefuses(fn () => DB::table('hr_job_applications')->where('id', $id)->update(['status_token_hash' => null]));
        $this->assertDatabaseRefuses(fn () => DB::table('hr_job_applications')->where('id', $id)->update(['stage_entered_at' => now()->subYear()]));
        $this->assertDatabaseRefuses(fn () => DB::table('hr_job_applications')->where('id', $id)->update(['status' => 'phone_screening', 'stage_entered_at' => now()->subDay()]));

        $this->actingAs($hr);
        $this->move($hr, (string) DB::table('hr_job_applications')->value('public_id'), 'not_taken_forward', 1)->assertSessionHasNoErrors();
        $this->travel(3651)->days();
        app(JobApplicationService::class)->purgeExpired();
        $this->assertNull(DB::table('hr_job_applications')->value('status_token_hash'));
        auth()->logout();
        $this->withCookie('kpone_career_status', $token)->get(route('hr-applications.status'))->assertInertia(fn (Assert $p) => $p->where('application', null));
        $this->post(route('hr-applications.status.exchange'), ['token' => $token])->assertSessionHasErrors('link');
    }

    public function test_the_status_pages_are_off_with_the_form_and_rate_limited(): void
    {
        $this->get(route('hr-applications.status'))->assertNotFound();
        $this->post(route('hr-applications.status.exchange'), ['token' => str_repeat('A', 43)])->assertNotFound();
        config(['hr-applications.enabled' => true]);
        $hr = $this->actor('hr_manager');
        $this->addPosition($hr, 'Synthetic Assistant');
        auth()->logout();
        // At most ten tries a minute from one address.
        $allowed = 0;
        for ($i = 0; $i < 12; $i++) {
            $status = $this->post(route('hr-applications.status.exchange'), ['token' => str_repeat('B', 43)])->status();
            if ($status === 429) {
                break;
            }
            $allowed++;
        }
        $this->assertLessThanOrEqual(10, $allowed);
        $this->assertLessThan(12, $i, 'The link exchange was never rate limited.');
    }

    public function test_the_templates_take_only_the_values_they_may_hold(): void
    {
        foreach (array_keys(HiringTemplates::TEMPLATES) as $template) {
            foreach (HiringTemplates::placeholders($template) as $key) {
                $this->assertArrayHasKey($key, HiringTemplates::PLACEHOLDERS, "{$template} uses {$key}");
            }
        }
        $this->expectException(InvalidArgumentException::class);
        HiringTemplates::render('auto_reject_reminder', ['position' => 'Synthetic Assistant', 'reference' => 'Synthetic Applicant', 'stage' => 'New']);
    }

    /** @var array{0:int, 1:int} */
    private array $lastRun = [0, 0];

    private function runAt(string $local): void
    {
        $this->travelTo(CarbonImmutable::parse($local, $this->branch->timezone));
        $this->lastRun = app(HiringDeadlines::class)->run();
    }

    /** @param list<int> $weekdays */
    private function workingDays(User $hr, array $weekdays): void
    {
        foreach (DB::table('branches')->where('organisation_id', $hr->organisation_id)->pluck('id') as $branchId) {
            DB::table('hr_branch_working_days')->updateOrInsert(['branch_id' => $branchId], ['organisation_id' => $hr->organisation_id,
                'weekdays' => json_encode($weekdays), 'updated_by_user_id' => $hr->id, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    private function applyAndGetToken(string $position): string
    {
        $this->apply($position)->assertRedirect(route('hr-applications.thanks'));
        $link = null;
        $this->get(route('hr-applications.thanks'))->assertInertia(function (Assert $page) use (&$link): void {
            $link = $page->toArray()['props']['statusLink'];
        });

        return Str::after((string) $link, '#');
    }

    private function addPosition(User $hr, string $title): string
    {
        $this->selectBranch($hr);
        $this->post(route('hr.applications.positions.store'), ['title' => $title, 'branch_id' => 'any', 'position_type' => 'ca', 'idempotency_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
        if (! DB::table('hr_job_application_settings')->exists()) {
            $this->post(route('hr.applications.retention'), ['retention_days' => 3650, 'expected_version' => 0, 'idempotency_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
        }

        return (string) DB::table('hr_job_positions')->where('title', $title)->value('public_id');
    }

    private function filed(User $hr, string $title): string
    {
        $position = $this->addPosition($hr, $title);
        config(['hr-applications.enabled' => true]);
        auth()->logout();
        $this->apply($position)->assertRedirect(route('hr-applications.thanks'));

        return (string) DB::table('hr_job_applications')->orderByDesc('id')->value('public_id');
    }

    private function move(User $hr, string $id, string $to, int $lock): TestResponse
    {
        $this->selectBranch($hr);

        return $this->post(route('hr.applications.move', $id), ['to' => $to, 'expected_lock_version' => $lock, 'idempotency_key' => (string) Str::uuid()]);
    }

    /** @param array<string, mixed> $input */
    private function screen(User $hr, string $id, array $input, int $lock): TestResponse
    {
        $this->selectBranch($hr);

        return $this->post(route('hr.applications.screen', $id), [...$input, 'expected_lock_version' => $lock, 'idempotency_key' => (string) Str::uuid()]);
    }

    /** @param array<string, mixed> $overrides */
    private function apply(string $position, array $overrides = []): TestResponse
    {
        // Each send starts a new minute; the form is opened 30 seconds before it is sent. Kept within the start day.
        $this->travel(1)->minutes();
        $token = JobApplicationIntake::issueToken();
        $this->travel(30)->seconds();

        return $this->post(route('hr-applications.submit'), [...['form_token' => $token, 'position' => $position, 'name' => 'Synthetic Applicant',
            'phone' => '012-345 6789', 'email' => 'applicant@example.test', 'privacy_accepted' => '1', 'website' => '', 'preferred_branch' => 'any',
            'start_date' => now()->addDays(14)->toDateString(), 'education' => 'degree', 'years_experience' => '2', 'languages' => ['malay', 'english'],
            'cv' => UploadedFile::fake()->createWithContent('cv.pdf', self::PDF)], ...$overrides]);
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
