<?php

namespace Tests\Feature\HR;

use App\Domain\HR\Applications\Services\CandidateLink;
use App\Domain\HR\Applications\Services\HiringMessenger;
use App\Domain\HR\Applications\Services\InterviewSlots;
use App\Domain\HR\Applications\Services\JobApplicationIntake;
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
use Tests\Feature\Visit\VisitTestCase;

/**
 * HR-15e online interviews (owner, via B.E.R.N.I.S.S, 18 Nov 2026): HR picks In person or Online when confirming, and for
 * Online types a video link that shows on the candidate's private page and in the confirmation email. No video or
 * calendar integration. Synthetic `.test` data and example.test links only; nothing is really sent (Mail::fake).
 */
class OnlineInterviewTest extends VisitTestCase
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

    private const LINK = 'https://meet.example.test/abc-defg-hij';

    public function test_an_online_interview_gives_the_link_in_the_email_and_on_both_pages_and_never_in_the_audit(): void
    {
        config(['hr-applications.email_enabled' => true]);
        [$id, $token] = $this->scheduled('Synthetic Assistant');
        $slots = $this->starts($id);
        $this->pick($token, array_slice($slots, 0, 3))->assertSessionHasNoErrors();

        $this->confirm($id, $slots[1], ['mode' => 'online', 'video_link' => '  '.self::LINK.' '])->assertSessionHasNoErrors();
        $interview = DB::table('hr_job_interviews')->sole();
        $this->assertSame(['online', self::LINK], [$interview->mode, $interview->video_link]);
        $branch = (string) DB::table('branches')->where('id', $interview->branch_id)->value('name');
        Mail::assertSent(HiringMessageMail::class, fn (HiringMessageMail $m): bool => str_contains($m->bodyText, 'Wed 11 Nov 2026 at 10:00 AM. It is online.')
            && str_contains($m->bodyText, 'Join with this link: '.self::LINK) && ! str_contains($m->bodyText, $branch));

        $this->withCookie('kpone_career_status', $token)->get(route('hr-applications.status'))->assertInertia(fn (Assert $p) => $p
            ->where('application.interview.online', true)->where('application.interview.videoLink', self::LINK));
        $this->selectBranch($this->hr);
        $this->get(route('hr.applications.index', ['status' => 'interview']))->assertInertia(fn (Assert $p) => $p
            ->where('applications.applications.rows.0.interview.online', true)
            ->where('applications.applications.rows.0.interview.videoLink', self::LINK)
            ->where('applications.options.meetingModes.1', ['value' => 'online', 'label' => 'Online']));

        // The message list rebuilds the same text; the audit row says "online" but never holds the link.
        $this->assertStringContainsString(self::LINK, json_encode(HiringMessenger::listFor($this->row($id), true), JSON_UNESCAPED_SLASHES) ?: '');
        $audit = DB::table('audit_logs')->where('event', 'hr.applications.interview_confirmed')->sole();
        $this->assertStringContainsString('online', (string) $audit->metadata);
        $this->assertStringNotContainsString('meet.example.test', (string) $audit->metadata);
    }

    public function test_in_person_stays_the_default_and_names_the_branch(): void
    {
        config(['hr-applications.email_enabled' => true]);
        [$id, $token] = $this->scheduled('Synthetic Assistant');
        $slots = $this->starts($id);
        $this->pick($token, array_slice($slots, 0, 3))->assertSessionHasNoErrors();
        $this->confirm($id, $slots[0])->assertSessionHasNoErrors();
        $interview = DB::table('hr_job_interviews')->sole();
        $this->assertSame(['in_person', null], [$interview->mode, $interview->video_link]);
        Mail::assertSent(HiringMessageMail::class, fn (HiringMessageMail $m): bool => str_contains($m->bodyText, 'at Klinik Putrijaya')
            && ! str_contains($m->bodyText, 'online'));
        $this->withCookie('kpone_career_status', $token)->get(route('hr-applications.status'))->assertInertia(fn (Assert $p) => $p
            ->where('application.interview.online', false)->where('application.interview.videoLink', null));
    }

    public function test_a_bad_mode_or_link_is_refused_before_anything_is_written(): void
    {
        [$id, $token] = $this->scheduled('Synthetic Assistant');
        $slots = $this->starts($id);
        $this->pick($token, array_slice($slots, 0, 3))->assertSessionHasNoErrors();
        $this->confirm($id, $slots[0], ['mode' => 'phone'])->assertSessionHasErrors('mode');
        foreach (['', '   ', 'http://meet.example.test/x', 'meet.example.test/x', 'https://meet.example.test/a b', 'javascript:alert(1)',
            'https://meet.example.test/<x>', 'https://'.str_repeat('a', 500).'.test'] as $bad) {
            $this->confirm($id, $slots[0], ['mode' => 'online', 'video_link' => $bad])->assertSessionHasErrors('video_link');
        }
        $this->assertSame(0, DB::table('hr_job_interviews')->count());
        $this->assertSame('scheduling', $this->row($id)->status);
        // In person ignores any link sent with it.
        $this->confirm($id, $slots[0], ['mode' => 'in_person', 'video_link' => self::LINK])->assertSessionHasNoErrors();
        $this->assertNull(DB::table('hr_job_interviews')->value('video_link'));
    }

    public function test_postgresql_keeps_the_mode_and_link_as_confirmed(): void
    {
        [$id, $token] = $this->scheduled('Synthetic Assistant');
        $slots = $this->starts($id);
        $this->pick($token, array_slice($slots, 0, 3))->assertSessionHasNoErrors();
        $this->confirm($id, $slots[0], ['mode' => 'online', 'video_link' => self::LINK])->assertSessionHasNoErrors();
        $this->assertDatabaseRefuses(fn () => DB::table('hr_job_interviews')->update(['video_link' => 'https://meet.example.test/other']));
        $this->assertDatabaseRefuses(fn () => DB::table('hr_job_interviews')->update(['mode' => 'in_person', 'video_link' => null]));
        // The shape: online needs a link, in person has none, and the link is https with no spaces.
        $row = (array) DB::table('hr_job_interviews')->sole();
        foreach ([['mode' => 'online', 'video_link' => null], ['mode' => 'in_person', 'video_link' => self::LINK],
            ['mode' => 'online', 'video_link' => 'javascript:alert(1)'], ['mode' => 'online', 'video_link' => 'https://a b.test']] as $shape) {
            // The update guard is set aside inside the rolled-back transaction, so only the CHECK is tested.
            $this->assertDatabaseRefuses(function () use ($row, $shape): void {
                DB::statement('ALTER TABLE hr_job_interviews DISABLE TRIGGER hr_job_interviews_guard');
                DB::table('hr_job_interviews')->where('id', $row['id'])->update($shape);
            }, '23514');
        }
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
    private function scheduled(string $title): array
    {
        [$id, $token] = $this->filedWithToken($title);
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
    private function filedWithToken(string $title): array
    {
        $this->selectBranch($this->hr);
        $this->post(route('hr.applications.positions.store'), ['title' => $title, 'branch_id' => 'any', 'position_type' => 'ca', 'idempotency_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
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
            'start_date' => now()->addDays(14)->toDateString(), 'education' => 'degree', 'years_experience' => '2', 'languages' => ['malay', 'english'],
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

    /** @param array<string, string> $extra */
    private function confirm(string $id, string $start, array $extra = []): TestResponse
    {
        $this->selectBranch($this->hr);

        return $this->post(route('hr.applications.interview.confirm', $id), ['start' => $start, 'expected_lock_version' => $this->row($id)->lock_version,
            'idempotency_key' => (string) Str::uuid(), ...$extra]);
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
