<?php

namespace Tests\Feature\HR;

use App\Domain\HR\Applications\Services\JobApplicationIntake;
use App\Domain\HR\Applications\Services\JobApplicationService;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Visit\VisitTestCase;

/**
 * HR-17a hiring stages (HR-PLAN 20; owner, 2026-11-10, relayed by B.E.R.N.I.S.S): position types and their questions,
 * the talent-pool consent, the phone-screening call and its checklist, and the new stages. Synthetic `.test` data only.
 */
class HiringStagesTest extends VisitTestCase
{
    private const PDF = "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\nSYNTHETIC-CV-CONTENT\n%%EOF\n";

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(JobApplicationIntake::DISK);
        RateLimiter::clear('hr-apply-all');
        $this->travelTo(CarbonImmutable::parse('2026-11-10 10:00', $this->branch->timezone));
    }

    public function test_each_position_type_asks_its_own_questions_and_nothing_about_pay_or_identity(): void
    {
        $hr = $this->actor('hr_manager');
        $doctor = $this->addPosition($hr, 'Synthetic Resident Doctor', 'resident_doctor');
        $intern = $this->addPosition($hr, 'Synthetic Intern', 'intern');
        $locum = $this->addPosition($hr, 'Synthetic Locum', 'locum');
        config(['hr-applications.enabled' => true]);
        auth()->logout();

        $this->get(route('hr-applications.show'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('positions.0.type', 'intern')->where('positions.1.type', 'locum')->where('positions.2.type', 'resident_doctor')
            ->has('branches', 3)->where('privacyNoticeVersion', 'draft-hr17-applications-v1'));

        // Each type's own questions are required; a CA's are not asked of them.
        $this->apply($doctor, [])->assertSessionHasErrors(['mmc_number', 'apc_year', 'apc_expiry'])->assertSessionDoesntHaveErrors(['institution', 'availability']);
        $this->apply($intern, [])->assertSessionHasErrors(['institution', 'programme', 'intern_from'])->assertSessionDoesntHaveErrors(['mmc_number']);
        $this->apply($locum, [])->assertSessionHasErrors(['availability'])->assertSessionDoesntHaveErrors(['mmc_number', 'institution']);
        $this->apply($doctor, ['mmc_number' => 'MMC-12', 'apc_year' => '2020', 'apc_expiry' => '2030-13-01', 'start_date' => '2025-01-01',
            'languages' => ['klingon'], 'preferred_branch' => 'NOWHERE'])
            ->assertSessionHasErrors(['mmc_number', 'apc_year', 'apc_expiry', 'start_date', 'languages', 'preferred_branch']);
        $this->assertSame(0, DB::table('hr_job_applications')->count());

        $this->apply($doctor, ['mmc_number' => '54321', 'apc_year' => '2026', 'apc_expiry' => '2026-12-31', 'preferred_branch' => 'CHERAS',
            'talent_pool_consent' => '1', 'salary' => '9000', 'ic_number' => '900101-14-5678'])->assertRedirect(route('hr-applications.thanks'));
        $row = DB::table('hr_job_applications')->sole();
        // No talent pool yet (HR-17e): a consent sent anyway is not recorded.
        $this->assertFalse((bool) $row->talent_pool_consent);
        $this->assertStringNotContainsString('54321', (string) $row->details_encrypted);
        $details = json_decode(Crypt::decryptString((string) $row->details_encrypted), true);
        $this->assertSame(['type', 'preferred_branch', 'start_date', 'education', 'years_experience', 'languages', 'mmc_number', 'apc_year', 'apc_expiry'], array_keys($details));
        $this->assertSame('54321', $details['mmc_number']);
        // Anything the form does not ask is dropped, never stored.
        $this->assertStringNotContainsString('9000', json_encode($details, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('900101', json_encode($details, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('54321', json_encode(DB::table('audit_logs')->get(), JSON_THROW_ON_ERROR));

        // HR sees the answers as labelled lines.
        $this->selectBranch($hr);
        $this->get(route('hr.applications.index'))->assertInertia(fn (Assert $page) => $page
            ->where('applications.applications.rows.0.type', 'resident_doctor')
            ->where('applications.applications.rows.0.talentPoolConsent', false)
            ->where('applications.applications.rows.0.details.0', ['label' => 'Preferred branch', 'value' => 'Cheras'])
            ->where('applications.applications.rows.0.details.5', ['label' => 'MMC number (as stated)', 'value' => '54321'])
            ->where('applications.applications.rows.0.screening', null));
    }

    public function test_a_position_changed_to_another_type_after_the_form_opened_is_refused(): void
    {
        $hr = $this->actor('hr_manager');
        $position = $this->addPosition($hr, 'Synthetic Assistant', 'ca');
        config(['hr-applications.enabled' => true]);
        $token = JobApplicationIntake::issueToken();
        // HR changes the type between the candidate opening the form and sending it, after validation picked the CA questions.
        DB::table('hr_job_positions')->where('public_id', $position)->update(['position_type' => 'resident_doctor']);
        $this->travel(30)->seconds();
        auth()->logout();
        $this->apply($position, ['form_token' => $token])->assertSessionHasErrors('mmc_number');
        $this->assertSame(0, DB::table('hr_job_applications')->count());
    }

    public function test_the_phone_screening_call_records_the_checklist_rating_and_outcome_once(): void
    {
        $hr = $this->actor('hr_manager');
        $id = $this->filed($hr, 'Synthetic Assistant');
        $this->selectBranch($hr);

        // KPOne ships no checklist; HR enters 1 to 15 short questions, each a version.
        $this->get(route('hr.applications.index', ['tab' => 'settings']))->assertInertia(fn (Assert $page) => $page->where('applications.checklist', ['version' => 0, 'items' => []]));
        $this->checklist([], 0)->assertSessionHasErrors('items');
        $this->checklist(array_fill(0, 16, 'Synthetic question'), 0)->assertSessionHasErrors('items');
        $this->checklist(['Clear speech', 'clear speech'], 0)->assertSessionHasErrors('items');
        $this->checklist(['Speaks clearly and politely', 'Can work weekends'], 0)->assertSessionHasNoErrors();
        $this->checklist(['Speaks clearly and politely', 'Can work weekends'], 0)->assertSessionHasErrors('items'); // stale
        $this->checklist(['Speaks clearly and politely', 'Can work weekends'], 1)->assertSessionHasErrors('items'); // nothing changed

        // Only at phone screening.
        $this->screen($id, ['outcome' => 'pass', 'rating' => 4], 1)->assertSessionHasErrors('outcome');
        $this->move($hr, $id, 'phone_screening', 1)->assertSessionHasNoErrors();
        $this->screen($id, ['outcome' => 'maybe', 'rating' => 6, 'ticked' => ['x'], 'note' => str_repeat('n', 501)], 2)
            ->assertSessionHasErrors(['outcome', 'rating', 'ticked', 'note']);
        $this->screen($id, ['outcome' => 'pass', 'rating' => 4, 'ticked' => [5]], 2)->assertSessionHasErrors('ticked');
        $this->screen($id, ['outcome' => 'pass', 'rating' => 4, 'ticked' => [1, 0, 1], 'note' => 'Synthetic: friendly on the phone'], 2)->assertSessionHasNoErrors();

        $application = DB::table('hr_job_applications')->where('public_id', $id)->sole();
        $this->assertSame('scheduling', $application->status);
        $this->assertNull($application->closed_at);
        $screening = DB::table('hr_job_screenings')->sole();
        $this->assertSame([[0, 1], 4, 'pass', 1], [json_decode((string) $screening->ticked, true), (int) $screening->rating, $screening->outcome, (int) $screening->checklist_version]);
        $this->assertStringNotContainsString('friendly', (string) $screening->note_encrypted);
        $this->assertStringNotContainsString('friendly', json_encode(DB::table('audit_logs')->get(), JSON_THROW_ON_ERROR));

        $this->get(route('hr.applications.index'))->assertInertia(fn (Assert $page) => $page
            ->where('applications.applications.rows.0.status', 'scheduling')
            ->where('applications.applications.rows.0.screening.rating', 4)
            ->where('applications.applications.rows.0.screening.note', 'Synthetic: friendly on the phone')
            ->where('applications.applications.rows.0.screening.items', [['text' => 'Speaks clearly and politely', 'ticked' => true], ['text' => 'Can work weekends', 'ticked' => true]]));

        // A reject closes the application as Not taken forward.
        $other = $this->filed($hr, 'Synthetic Second Role');
        $this->selectBranch($hr);
        $this->move($hr, $other, 'phone_screening', 1)->assertSessionHasNoErrors();
        $this->screen($other, ['outcome' => 'reject', 'rating' => 2], 2)->assertSessionHasNoErrors();
        $row = DB::table('hr_job_applications')->where('public_id', $other)->sole();
        $this->assertSame('not_taken_forward', $row->status);
        $this->assertNotNull($row->closed_at);

        // PostgreSQL: one screening per application, and it keeps what was recorded.
        $this->assertDatabaseRefuses(fn () => DB::table('hr_job_screenings')->insert(['organisation_id' => $hr->organisation_id, 'application_id' => $application->id,
            'ticked' => '[]', 'rating' => 3, 'outcome' => 'pass', 'actor_user_id' => $hr->id, 'created_at' => now()->utc()]), '23505');
        $this->assertDatabaseRefuses(fn () => DB::table('hr_job_screenings')->where('id', $screening->id)->update(['rating' => 5]));
        $this->assertDatabaseRefuses(fn () => DB::table('hr_job_screenings')->where('id', $screening->id)->delete());
        // The note is cleared only by the purge, and the audit holds no rating (Suresh, HR-17a).
        $this->assertDatabaseRefuses(fn () => DB::table('hr_job_screenings')->where('id', $screening->id)->update(['note_encrypted' => null]));
        $this->assertArrayNotHasKey('rating', (array) json_decode((string) DB::table('audit_logs')->where('event', 'hr.applications.screened')->value('metadata'), true));
        $this->assertDatabaseRefuses(fn () => DB::table('hr_job_screening_checklists')->update(['items' => '["x"]']));
    }

    public function test_postgresql_holds_the_new_stages_and_older_steps_finish_their_way(): void
    {
        $hr = $this->actor('hr_manager');
        $id = $this->filed($hr, 'Synthetic Assistant');
        $row = DB::table('hr_job_applications')->where('public_id', $id)->sole();
        $set = fn (string $status, ?bool $closed = null) => DB::table('hr_job_applications')->where('id', $row->id)
            ->update(['status' => $status, 'closed_at' => $closed ? now()->utc() : null]);

        // No skipping, no Shortlisted or Offered for a new application, and scheduling needs a passed screening.
        $this->assertDatabaseRefuses(fn () => $set('interview'));
        $this->assertDatabaseRefuses(fn () => $set('shortlisted'));
        $set('phone_screening');
        $this->assertDatabaseRefuses(fn () => $set('scheduling'));
        $set('auto_rejected', true);
        $this->assertDatabaseRefuses(fn () => $set('phone_screening'));

        // An HR-13 application at Shortlisted can still go to Interview, and then to Passed or Failed; Offered only to Withdrawn.
        $old = $this->filed($hr, 'Synthetic Older Role');
        $oldRow = DB::table('hr_job_applications')->where('public_id', $old)->sole();
        DB::statement('ALTER TABLE hr_job_applications DISABLE TRIGGER hr_job_applications_guard');
        DB::table('hr_job_applications')->where('id', $oldRow->id)->update(['status' => 'shortlisted']);
        DB::statement('ALTER TABLE hr_job_applications ENABLE TRIGGER hr_job_applications_guard');
        $this->selectBranch($hr);
        $this->get(route('hr.applications.index'))->assertInertia(fn (Assert $page) => $page
            ->where('applications.applications.rows.0.next', [['value' => 'interview', 'label' => 'Interview'], ['value' => 'not_taken_forward', 'label' => 'Not taken forward'],
                ['value' => 'withdrawn', 'label' => 'Withdrawn']]));
        $this->move($hr, $old, 'interview', (int) $oldRow->lock_version)->assertSessionHasNoErrors();
        $this->move($hr, $old, 'offered', (int) $oldRow->lock_version + 1)->assertSessionHasErrors('to');
        $this->move($hr, $old, 'failed', (int) $oldRow->lock_version + 1)->assertSessionHasNoErrors();
    }

    public function test_the_purge_also_clears_the_answers_and_the_call_note(): void
    {
        $hr = $this->actor('hr_manager');
        $id = $this->filed($hr, 'Synthetic Assistant');
        $this->selectBranch($hr);
        $this->move($hr, $id, 'phone_screening', 1)->assertSessionHasNoErrors();
        $this->screen($id, ['outcome' => 'reject', 'rating' => 1, 'note' => 'Synthetic note'], 2)->assertSessionHasNoErrors();
        // Retention of 30 days, after the 7-day wait.
        $version = (int) DB::table('hr_job_application_settings')->max('version');
        $this->post(route('hr.applications.retention'), ['retention_days' => 30, 'expected_version' => $version, 'idempotency_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
        $this->travel(40)->days();

        $this->assertSame(1, app(JobApplicationService::class)->purgeExpired());
        $row = DB::table('hr_job_applications')->where('public_id', $id)->sole();
        $this->assertNull($row->details_encrypted);
        $this->assertNull(DB::table('hr_job_screenings')->value('note_encrypted'));
        $this->assertSame(1, (int) DB::table('hr_job_screenings')->value('rating')); // the rating and outcome stay
    }

    private function addPosition(User $hr, string $title, string $type = 'ca'): string
    {
        $this->selectBranch($hr);
        $this->post(route('hr.applications.positions.store'), ['title' => $title, 'branch_id' => 'any', 'position_type' => $type, 'idempotency_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
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
        $this->apply($position, [])->assertRedirect(route('hr-applications.thanks'));

        return (string) DB::table('hr_job_applications')->orderByDesc('id')->value('public_id');
    }

    private function move(User $hr, string $id, string $to, int $lock): TestResponse
    {
        $this->actingAs($hr);

        return $this->post(route('hr.applications.move', $id), ['to' => $to, 'expected_lock_version' => $lock, 'idempotency_key' => (string) Str::uuid()]);
    }

    /** @param array<string, mixed> $input */
    private function screen(string $id, array $input, int $lock): TestResponse
    {
        return $this->post(route('hr.applications.screen', $id), [...$input, 'expected_lock_version' => $lock, 'idempotency_key' => (string) Str::uuid()]);
    }

    /** @param list<string> $items */
    private function checklist(array $items, int $version): TestResponse
    {
        return $this->post(route('hr.applications.checklist'), ['items' => $items, 'expected_version' => $version, 'idempotency_key' => (string) Str::uuid()]);
    }

    /** @param array<string, mixed> $overrides */
    private function apply(string $position, array $overrides): TestResponse
    {
        // The form allows three sends a minute from one address; each call here starts a new minute.
        $this->travel(61)->seconds();
        $token = $overrides['form_token'] ?? JobApplicationIntake::issueToken();
        if (! isset($overrides['form_token'])) {
            $this->travel(30)->seconds();
        }

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
