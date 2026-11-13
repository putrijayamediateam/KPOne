<?php

namespace Tests\Feature\HR;

use App\Domain\HR\Applications\Services\CandidateLink;
use App\Domain\HR\Applications\Services\HiringMessenger;
use App\Domain\HR\Applications\Services\InterviewDocuments;
use App\Domain\HR\Applications\Services\InterviewSlots;
use App\Domain\HR\Applications\Services\JobApplicationIntake;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
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
 * HR-17d interview documents (HR-PLAN 19.7): PDFs per position type that a candidate downloads only while their
 * interview is confirmed, each download recorded first. Synthetic `.test` data and dummy PDFs only.
 */
class InterviewDocumentsTest extends VisitTestCase
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

    public function test_hr_adds_checked_pdfs_per_position_type_encrypted_with_no_original_name(): void
    {
        $this->selectBranch($this->hr);
        $this->upload('ca', 'Clinic induction booklet', $this->pdf('induction'), 'my-private-name.pdf')->assertSessionHasNoErrors();
        $doc = DB::table('hr_interview_documents')->sole();
        $this->assertSame(['ca', 'Clinic induction booklet', 'active', 1], [$doc->position_type, $doc->title, $doc->status, (int) $doc->lock_version]);
        $stored = Storage::disk(JobApplicationIntake::DISK)->get((string) $doc->stored_path);
        $this->assertStringNotContainsString('SYNTHETIC', (string) $stored);
        $this->assertStringNotContainsString('my-private-name', json_encode(DB::table('hr_interview_documents')->get()).json_encode(DB::table('audit_logs')->get()));
        $this->assertSame(['uploaded'], DB::table('hr_interview_document_events')->pluck('action')->all());
        $audit = DB::table('audit_logs')->where('event', 'hr.applications.interview_document_uploaded')->sole();
        $this->assertSame(['bytes', 'document_id', 'position_type'], collect(json_decode((string) $audit->metadata, true))->keys()->sort()->values()->all());

        // Not a PDF, an unreadable title, the same file twice for one type: refused, nothing written.
        // A text file named .pdf is refused by its content (no GD needed).
        $this->upload('ca', 'Not really a PDF', UploadedFile::fake()->createWithContent('notes.pdf', 'Synthetic plain text, not a PDF.'))->assertSessionHasErrors('file');
        $this->upload('ca', 'No', $this->pdf('short-title'))->assertSessionHasErrors('title');
        $this->upload('ca', 'Has <markup>', $this->pdf('markup'))->assertSessionHasErrors('title');
        $this->upload('ca', 'Same file again', $this->pdf('induction'))->assertSessionHasErrors('file');
        $this->upload('nurse', 'Unknown type', $this->pdf('nurse'))->assertSessionHasErrors('position_type');
        $this->assertSame(1, DB::table('hr_interview_documents')->count());
        // The same file is allowed for another type.
        $this->upload('intern', 'Clinic induction booklet', $this->pdf('induction'))->assertSessionHasNoErrors();

        // Over 10 MB is refused.
        $big = UploadedFile::fake()->createWithContent('big.pdf', "%PDF-1.4\n".str_repeat('0', 10 * 1024 * 1024 + 10)."\n%%EOF\n");
        $this->upload('ca', 'A big file', $big)->assertSessionHasErrors('file');

        // The settings list shows title, size and who added it.
        $this->get(route('hr.applications.index', ['tab' => 'settings']))->assertInertia(fn (Assert $p) => $p
            ->where('applications.interviewDocuments.0.type', 'ca')
            ->where('applications.interviewDocuments.0.documents.0.title', 'Clinic induction booklet')
            ->where('applications.interviewDocuments.0.documents.0.candidates', 0)
            ->has('applications.interviewDocuments.1.documents', 1));
    }

    public function test_ten_documents_a_type_and_a_retry_adds_nothing(): void
    {
        $this->selectBranch($this->hr);
        foreach (range(1, 10) as $n) {
            $this->upload('ca', "Synthetic document {$n}", $this->pdf("doc-{$n}"))->assertSessionHasNoErrors();
        }
        $this->upload('ca', 'Synthetic document 11', $this->pdf('doc-11'))->assertSessionHasErrors('file');
        $this->assertSame(10, DB::table('hr_interview_documents')->where('position_type', 'ca')->count());
        // PostgreSQL holds the ten too.
        $first = DB::table('hr_interview_documents')->first();
        $this->assertDatabaseRefuses(fn () => DB::table('hr_interview_documents')->insert([...(array) $first, 'id' => 999, 'public_id' => (string) Str::uuid(),
            'sha256' => str_repeat('a', 64)]));

        // The same key sends once.
        $key = (string) Str::uuid();
        $this->upload('intern', 'Retry once', $this->pdf('retry'), 'x.pdf', $key)->assertSessionHasNoErrors();
        $this->upload('intern', 'Retry once', $this->pdf('retry'), 'x.pdf', $key)->assertSessionHasNoErrors();
        $this->assertSame(1, DB::table('hr_interview_documents')->where('position_type', 'intern')->count());
    }

    public function test_the_candidate_downloads_only_while_the_interview_is_confirmed_and_each_download_is_recorded_first(): void
    {
        $this->selectBranch($this->hr);
        $this->upload('ca', 'Clinic induction booklet', $this->pdf('induction'))->assertSessionHasNoErrors();
        $this->upload('intern', 'Intern programme', $this->pdf('intern'))->assertSessionHasNoErrors();
        $ca = (string) DB::table('hr_interview_documents')->where('position_type', 'ca')->value('public_id');
        $intern = (string) DB::table('hr_interview_documents')->where('position_type', 'intern')->value('public_id');
        [$id, $token] = $this->scheduled('Synthetic Assistant');

        // At Scheduling: nothing listed, nothing downloads, nothing recorded.
        $this->statusPage($token)->assertInertia(fn (Assert $p) => $p->where('application.documents', []));
        $this->download($token, $ca)->assertNotFound();
        $slots = $this->starts($id);
        $this->pick($token, array_slice($slots, 0, 3))->assertSessionHasNoErrors();
        $this->confirm($id, $slots[0])->assertSessionHasNoErrors();
        auth()->logout();

        // Confirmed: their position type only, title and size.
        $this->statusPage($token)->assertInertia(fn (Assert $p) => $p->has('application.documents', 1)
            ->where('application.documents.0.id', $ca)->where('application.documents.0.title', 'Clinic induction booklet'));
        $this->download($token, $intern)->assertNotFound();
        $this->download('not-a-token', $ca)->assertNotFound();
        $response = $this->download($token, $ca)->assertOk()->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('X-Content-Type-Options', 'nosniff')->assertHeader('Content-Disposition', 'attachment; filename="clinic-induction-booklet.pdf"');
        $this->assertStringContainsString('SYNTHETIC-induction', (string) $response->getContent());
        $app = $this->row($id);
        $this->assertSame([['downloaded', (int) $app->id, null]], DB::table('hr_interview_document_events')->where('action', 'downloaded')->get()
            ->map(fn ($e) => [$e->action, (int) $e->application_id, $e->actor_user_id])->all());

        // HR sees which documents the candidate downloaded, and the count in Settings.
        $this->selectBranch($this->hr);
        $this->get(route('hr.applications.index', ['status' => 'interview']))->assertInertia(fn (Assert $p) => $p
            ->where('applications.applications.rows.0.interview.documents.0.title', 'Clinic induction booklet')
            ->where('applications.applications.rows.0.interview.documents.0.downloadedAt', fn ($v) => is_string($v) && str_starts_with($v, '10 Nov 2026, ')));
        $this->get(route('hr.applications.index', ['tab' => 'settings']))->assertInertia(fn (Assert $p) => $p
            ->where('applications.interviewDocuments.0.documents.0.candidates', 1));

        // Once the interview is cancelled, nothing downloads; PostgreSQL refuses the record too.
        $this->cancel($id, 'clinic_cannot')->assertSessionHasNoErrors();
        auth()->logout();
        $this->download($token, $ca)->assertNotFound();
        $this->statusPage($token)->assertInertia(fn (Assert $p) => $p->where('application.documents', []));
        $docId = (int) DB::table('hr_interview_documents')->where('public_id', $ca)->value('id');
        $this->assertDatabaseRefuses(fn () => DB::table('hr_interview_document_events')->insert(['organisation_id' => $app->organisation_id,
            'document_id' => $docId, 'application_id' => $app->id, 'action' => 'downloaded', 'actor_user_id' => null, 'created_at' => now()]));
        $this->assertSame(1, DB::table('hr_interview_document_events')->where('action', 'downloaded')->count());
    }

    public function test_hr_opens_and_removes_a_document_and_the_row_and_events_are_kept(): void
    {
        $this->selectBranch($this->hr);
        $this->upload('ca', 'Clinic induction booklet', $this->pdf('induction'))->assertSessionHasNoErrors();
        $doc = DB::table('hr_interview_documents')->sole();
        $opened = $this->get(route('hr.applications.documents.show', $doc->public_id))->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString('no-store', (string) $opened->headers->get('Cache-Control'));
        $this->assertSame(['uploaded', 'opened'], DB::table('hr_interview_document_events')->orderBy('id')->pluck('action')->all());

        $this->remove($doc->public_id, 99)->assertSessionHasErrors();
        $this->remove($doc->public_id, (int) $doc->lock_version)->assertSessionHasNoErrors();
        $after = DB::table('hr_interview_documents')->sole();
        $this->assertSame(['removed', null, 2], [$after->status, $after->stored_path, (int) $after->lock_version]);
        $this->assertFalse(Storage::disk(JobApplicationIntake::DISK)->exists((string) $doc->stored_path));
        $this->remove($doc->public_id, 2)->assertSessionHasErrors('document');
        $this->get(route('hr.applications.documents.show', $doc->public_id))->assertRedirect();
        $this->assertSame(['uploaded', 'opened', 'removed'], DB::table('hr_interview_document_events')->orderBy('id')->pluck('action')->all());
        // The same file can be added again after a removal.
        $this->upload('ca', 'Clinic induction booklet', $this->pdf('induction'))->assertSessionHasNoErrors();

        // Kept as uploaded: no edit, no delete, no truncate; events are append-only.
        $this->assertDatabaseRefuses(fn () => DB::table('hr_interview_documents')->where('id', $doc->id)->update(['title' => 'Changed']));
        $this->assertDatabaseRefuses(fn () => DB::table('hr_interview_documents')->where('id', $doc->id)->delete());
        $this->assertDatabaseRefuses(fn () => DB::statement('TRUNCATE hr_interview_document_events CASCADE'));
        $this->assertDatabaseRefuses(fn () => DB::table('hr_interview_document_events')->update(['action' => 'opened']));
    }

    public function test_only_people_who_manage_applications_reach_the_documents(): void
    {
        $this->selectBranch($this->hr);
        $this->upload('ca', 'Clinic induction booklet', $this->pdf('induction'))->assertSessionHasNoErrors();
        $doc = DB::table('hr_interview_documents')->sole();
        $ca = $this->actor('ca');
        $this->selectBranch($ca);
        $this->post(route('hr.applications.documents.store'), ['position_type' => 'ca', 'title' => 'Not allowed', 'file' => $this->pdf('nope'),
            'idempotency_key' => (string) Str::uuid()])->assertForbidden();
        $this->get(route('hr.applications.documents.show', $doc->public_id))->assertForbidden();
        $this->post(route('hr.applications.documents.remove', $doc->public_id), ['expected_lock_version' => 1, 'idempotency_key' => (string) Str::uuid()])->assertForbidden();
        $this->assertSame(['uploaded'], DB::table('hr_interview_document_events')->pluck('action')->all());
    }

    public function test_an_upload_that_fails_after_the_file_is_written_keeps_neither_row_nor_bytes(): void
    {
        // The event insert fails after the bytes are on disk (Suresh, HR-17d m1): the row rolls back and the file goes.
        DB::unprepared(<<<'SQL'
CREATE FUNCTION test_fail_interview_doc_event() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RAISE EXCEPTION 'synthetic failure' USING ERRCODE = 'P0001'; END $$;
CREATE TRIGGER test_fail_interview_doc_event BEFORE INSERT ON hr_interview_document_events FOR EACH ROW EXECUTE FUNCTION test_fail_interview_doc_event();
SQL);
        try {
            app(InterviewDocuments::class)->upload($this->hr, $this->pdf('fails'), ['position_type' => 'ca', 'title' => 'Fails after the write',
                'idempotency_key' => (string) Str::uuid()]);
            $this->fail('The upload should have failed.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('synthetic failure', $e->getMessage());
        }
        $this->assertSame(0, DB::table('hr_interview_documents')->count());
        $this->assertSame([], Storage::disk(JobApplicationIntake::DISK)->allFiles('hr-interview-documents'));
    }

    public function test_the_download_filename_is_a_trimmed_slug_of_the_title(): void
    {
        $this->selectBranch($this->hr);
        $this->upload('ca', str_repeat('a', 59).' booklet', $this->pdf('long'))->assertSessionHasNoErrors();
        $doc = DB::table('hr_interview_documents')->sole();
        $this->get(route('hr.applications.documents.show', $doc->public_id))->assertOk()
            ->assertHeader('Content-Disposition', 'attachment; filename="'.str_repeat('a', 59).'.pdf"');
    }

    public function test_an_unsuccessful_candidates_interview_records_go_six_months_after_closing_and_the_clinic_files_stay(): void
    {
        // PDPA 15 (owner, 2026-11-11). PostgreSQL checks the 6 months against its own clock, so this test works in real time.
        $real = CarbonImmutable::createFromTimestamp(time(), 'UTC');
        $this->travelTo($real->subMonths(7));
        $this->selectBranch($this->hr);
        $this->upload('ca', 'Clinic induction booklet', $this->pdf('induction'))->assertSessionHasNoErrors();
        $doc = (string) DB::table('hr_interview_documents')->value('public_id');
        [$gone, $goneToken] = $this->scheduled('Synthetic Withdrew');
        [$open, $openToken] = $this->scheduled('Synthetic Still Choosing');
        $slots = $this->starts($gone);
        $this->pick($goneToken, array_slice($slots, 0, 3))->assertSessionHasNoErrors();
        $this->confirm($gone, $slots[0])->assertSessionHasNoErrors();
        auth()->logout();
        $this->download($goneToken, $doc)->assertOk();
        $this->withCookie('kpone_career_status', $goneToken)->post(route('hr-applications.status.withdraw'))->assertSessionHasNoErrors();
        $this->pick($openToken, array_slice($this->starts($open), 3, 3))->assertSessionHasNoErrors();
        $this->travelBack();
        $goneId = (int) $this->row($gone)->id;
        $this->assertSame('withdrawn', $this->row($gone)->status);

        $this->artisan('hr-interview-records:purge')->expectsOutputToContain('Cleared the interview records of 1 applications (1 time choices, 1 bookings, 1 downloads).')
            ->assertSuccessful();
        foreach (['hr_job_interview_picks', 'hr_job_interviews', 'hr_interview_document_events'] as $table) {
            $this->assertSame(0, DB::table($table)->where('application_id', $goneId)->count(), $table);
        }
        $this->assertSame(1, DB::table('hr_job_interview_picks')->where('application_id', $this->row($open)->id)->count()); // still open
        $this->assertSame(1, DB::table('hr_interview_documents')->where('status', 'active')->count()); // the clinic's file stays
        $this->assertSame(1, DB::table('hr_interview_document_events')->where('action', 'uploaded')->count()); // and its history
        $this->assertSame('withdrawn', $this->row($gone)->status); // the application row and its history stay
        // The confirmation email stays in the history, saying why its details are gone (Suresh).
        $sent = DB::table('hr_job_application_messages')->where('application_id', $goneId)->where('template', 'interview_confirmed')->first();
        $this->assertNotNull($sent);
        $shown = HiringMessenger::shown($sent, (string) $this->row($gone)->public_id, 'Synthetic role');
        $this->assertSame(HiringMessenger::INTERVIEW_PURGED, $shown['body']);
        $this->assertSame('Interview time confirmed', $shown['subject']);
        $this->assertSame([['applications' => 1, 'picks' => 1, 'interviews' => 1, 'downloads' => 1, 'retention_months' => 6]], DB::table('audit_logs')
            ->where('event', 'hr.applications.interview_records_purged')->pluck('metadata')->map(fn ($m): mixed => json_decode((string) $m, true))->all());
        $this->artisan('hr-interview-records:purge')->expectsOutputToContain('Cleared the interview records of 0 applications')->assertSuccessful();
        $this->assertSame(['0 0 * * *'], collect(app(Schedule::class)->events())
            ->filter(fn ($e): bool => str_contains((string) $e->command, 'hr-interview-records:purge'))->pluck('expression')->values()->all());

        // Only the purge deletes, and only for an application closed unsuccessfully over 6 months ago; TRUNCATE stays refused.
        $this->assertDatabaseRefuses(fn () => DB::table('hr_job_interview_picks')->where('application_id', $this->row($open)->id)->delete());
        $this->assertDatabaseRefuses(function () use ($open): void {
            DB::statement("SELECT set_config('kpone.hr_interview_purge', 'on', true)");
            DB::table('hr_job_interview_picks')->where('application_id', $this->row($open)->id)->delete();
        });
        $this->assertDatabaseRefuses(function (): void {
            DB::statement("SELECT set_config('kpone.hr_interview_purge', 'on', true)");
            DB::table('hr_interview_document_events')->where('action', 'uploaded')->delete();
        });
        $this->assertDatabaseRefuses(fn () => DB::statement('TRUNCATE hr_job_interviews CASCADE'));
    }

    private function pdf(string $marker): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('doc.pdf', "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\nSYNTHETIC-{$marker}\n%%EOF\n");
    }

    private function upload(string $type, string $title, UploadedFile $file, string $name = 'doc.pdf', ?string $key = null): TestResponse
    {
        $this->selectBranch($this->hr);

        return $this->post(route('hr.applications.documents.store'), ['position_type' => $type, 'title' => $title, 'file' => $file,
            'idempotency_key' => $key ?? (string) Str::uuid()]);
    }

    private function remove(string $id, int $lock): TestResponse
    {
        $this->selectBranch($this->hr);

        return $this->post(route('hr.applications.documents.remove', $id), ['expected_lock_version' => $lock, 'idempotency_key' => (string) Str::uuid()]);
    }

    private function statusPage(string $token): TestResponse
    {
        auth()->logout();

        return $this->withCookie('kpone_career_status', $token)->get(route('hr-applications.status'));
    }

    private function download(string $token, string $document): TestResponse
    {
        auth()->logout();

        return $this->withCookie('kpone_career_status', $token)->get(route('hr-applications.status.document', $document));
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
