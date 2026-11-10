<?php

namespace Tests\Feature\HR;

use App\Domain\Access\PermissionCatalogue;
use App\Domain\HR\Discipline\DisciplinePermissions;
use App\Domain\HR\Records\Models\Worker;
use App\Domain\HR\Records\Services\DocumentService;
use App\Domain\HR\Records\Services\StaffFileService;
use App\Domain\Organisation\BusinessDetails\BusinessDetailsService;
use App\Domain\Organisation\BusinessDetails\OrganisationBusinessProfile;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Visit\VisitTestCase;

/**
 * HR-10c discipline (HR-PLAN 20.5; owner, 2026-11-10). Synthetic data only.
 */
class DisciplineTest extends VisitTestCase
{
    private const ALLEGATION = 'SYNTHETIC-ALLEGATION: left the dispensary unattended for an hour on a clinic day.';

    private const REPLY = 'SYNTHETIC-REPLY: I was called to help at the counter.';

    private const PDF = "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\nSYNTHETIC-DISCIPLINE-FILE\n%%EOF\n";

    private User $hr;

    private User $director;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(DocumentService::DISK);
        $this->travelTo(CarbonImmutable::parse('2026-11-10 09:00', $this->branch->timezone));
        $this->hr = $this->actor('hr_manager');
        $this->director = $this->actor('director');
    }

    public function test_the_grant_is_exact_and_nobody_else_reaches_a_case(): void
    {
        $holders = collect(PermissionCatalogue::roles())->filter(fn (array $p) => in_array(DisciplinePermissions::MANAGE, $p, true))->keys()->sort()->values()->all();
        $this->assertSame(['director', 'hr_manager'], $holders);
        $this->assertFalse(PermissionCatalogue::isAdministrativeAuthority(DisciplinePermissions::MANAGE));
        $staff = $this->actor('ca');
        $case = $this->openCase($this->file($staff));

        foreach (['ca', 'ca_supervisor', 'finance_officer', 'technical_admin', 'queue_display'] as $role) {
            $this->selectBranch($this->actor($role));
            $this->get(route('hr.discipline.index'))->assertForbidden();
            $this->get(route('hr.discipline.show', $case))->assertForbidden();
        }
        // The subject cannot reach it, and it never shows on the staff file or in their own records.
        $this->selectBranch($staff);
        $this->get(route('hr.discipline.show', $case))->assertForbidden();
        $this->assertStringNotContainsString('SYNTHETIC-ALLEGATION', (string) $this->get(route('hr.records.index'))->getContent());
        $this->selectBranch($this->hr);
        $worker = Worker::query()->where('user_id', $staff->id)->sole();
        $page = (string) $this->get(route('hr.records.show', $worker->public_id))->getContent();
        $this->assertStringNotContainsString('SYNTHETIC-ALLEGATION', $page);
        $this->assertStringNotContainsString('\\/hr\\/discipline', $page); // no link to a case anywhere on the staff file
    }

    public function test_hr_opens_a_case_links_letters_records_the_reply_and_closes_it_with_one_outcome(): void
    {
        $staff = $this->actor('ca');
        $worker = $this->file($staff);
        $this->businessDetails();
        $showCause = $this->letter($worker, 'show_cause');
        $warning = $this->letter($worker, 'warning');
        $this->selectBranch($this->hr);
        $this->post(route('hr.discipline.store'), ['worker_id' => $worker->public_id, 'incident_on' => '2026-11-11', 'allegation' => self::ALLEGATION,
            'idempotency_key' => (string) Str::uuid()])->assertSessionHasErrors('incident_on'); // tomorrow
        $this->post(route('hr.discipline.store'), ['worker_id' => $worker->public_id, 'incident_on' => '2026-11-03', 'allegation' => 'short',
            'idempotency_key' => (string) Str::uuid()])->assertSessionHasErrors('allegation');
        $case = $this->openCase($worker);
        $row = DB::table('hr_discipline_cases')->sole();
        $this->assertStringNotContainsString('SYNTHETIC', (string) $row->allegation_encrypted);
        $this->assertSame(self::ALLEGATION, Crypt::decryptString((string) $row->allegation_encrypted));

        $this->post(route('hr.discipline.note', $case), ['note' => 'Spoke with the supervisor on duty.', 'idempotency_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
        $this->post(route('hr.discipline.show-cause', $case), ['letter_id' => $warning, 'idempotency_key' => (string) Str::uuid()])->assertSessionHasErrors('letter_id');
        $this->post(route('hr.discipline.show-cause', $case), ['letter_id' => $showCause, 'idempotency_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
        $this->post(route('hr.discipline.show-cause', $case), ['letter_id' => $showCause, 'idempotency_key' => (string) Str::uuid()])->assertSessionHasErrors('letter_id');
        $this->post(route('hr.discipline.reply', $case), ['reply' => self::REPLY, 'received_on' => '2026-11-07', 'idempotency_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
        $key = (string) Str::uuid();
        $this->post(route('hr.discipline.files.store', $case), ['file' => UploadedFile::fake()->createWithContent('statement.pdf', self::PDF), 'idempotency_key' => $key])
            ->assertSessionHasNoErrors();
        $this->post(route('hr.discipline.files.store', $case), ['file' => UploadedFile::fake()->createWithContent('statement.pdf', self::PDF), 'idempotency_key' => (string) Str::uuid()])
            ->assertSessionHasErrors('file');
        $file = DB::table('hr_discipline_files')->sole();
        $this->assertStringNotContainsString('SYNTHETIC', (string) Storage::disk(DocumentService::DISK)->get($file->stored_path));
        $this->assertSame(0, DB::table('hr_documents')->count()); // never in the staff file's vault

        $this->get(route('hr.discipline.show', $case))->assertOk()->assertInertia(fn (Assert $page) => $page->component('HR/DisciplineCase')
            ->where('case.allegation', self::ALLEGATION)->where('case.status', 'open')->has('case.timeline', 5)
            ->where('case.timeline.3.body', self::REPLY)->where('case.timeline.3.occurredOn', '7 Nov 2026')
            ->where('case.showCauseLetters', [])->has('case.warningLetters', 1));
        $this->get(route('hr.discipline.files.show', [$case, $file->public_id]))->assertOk()->assertHeader('Content-Type', 'application/pdf');

        $this->post(route('hr.discipline.close', $case), ['outcome' => 'written_warning', 'expected_lock_version' => 1, 'idempotency_key' => (string) Str::uuid()])
            ->assertSessionHasErrors('letter_id');
        $this->post(route('hr.discipline.close', $case), ['outcome' => 'written_warning', 'letter_id' => $showCause, 'expected_lock_version' => 1, 'idempotency_key' => (string) Str::uuid()])
            ->assertSessionHasErrors('letter_id');
        $this->post(route('hr.discipline.close', $case), ['outcome' => 'written_warning', 'letter_id' => $warning, 'note' => 'Second time this year.',
            'expected_lock_version' => 1, 'idempotency_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
        $this->post(route('hr.discipline.close', $case), ['outcome' => 'no_action', 'expected_lock_version' => 2, 'idempotency_key' => (string) Str::uuid()])
            ->assertSessionHasErrors('case');
        $this->post(route('hr.discipline.note', $case), ['note' => 'Late note', 'idempotency_key' => (string) Str::uuid()])->assertSessionHasErrors('case');
        $this->assertSame(['closed', 'written_warning'], [DB::table('hr_discipline_cases')->value('status'), DB::table('hr_discipline_cases')->value('outcome')]);
        // KPOne changes no account and ends no employment.
        $this->assertTrue((bool) $staff->fresh()?->is_active);

        $this->get(route('hr.discipline.index', ['tab' => 'closed']))->assertInertia(fn (Assert $page) => $page->where('discipline.counts', ['open' => 0, 'closed' => 1])
            ->where('discipline.cases.0.outcome', 'Written warning'));
        $audit = DB::table('audit_logs')->where('event', 'like', 'hr.discipline.%')->orderBy('id')->pluck('event')->all();
        $this->assertSame(['hr.discipline.case_opened', 'hr.discipline.note_added', 'hr.discipline.show_cause_added', 'hr.discipline.reply_added', 'hr.discipline.file_added',
            'hr.discipline.case_viewed', 'hr.discipline.file_opened', 'hr.discipline.case_closed'], $audit);
        $this->assertStringNotContainsString('SYNTHETIC', DB::table('audit_logs')->get()->toJson());
        $this->assertStringNotContainsString('Second time', DB::table('audit_logs')->get()->toJson());
    }

    public function test_who_may_handle_whose_case(): void
    {
        $otherHr = $this->actor('hr_manager');
        $hrFile = $this->file($otherHr, $this->director);
        $ownFile = $this->file($this->hr, $this->director);
        $this->selectBranch($this->hr);
        $this->post(route('hr.discipline.store'), $this->openInput($ownFile))->assertSessionHasErrors('case'); // yourself
        $this->post(route('hr.discipline.store'), $this->openInput($hrFile))->assertSessionHasErrors('case'); // another HR Manager: only a Director
        $this->get(route('hr.discipline.index'))->assertInertia(fn (Assert $page) => $page
            ->where('discipline.workers', fn ($workers): bool => array_intersect(array_column((array) json_decode((string) json_encode($workers), true), 'id'),
                [$hrFile->public_id, $ownFile->public_id]) === []));
        $this->assertSame(0, DB::table('hr_discipline_cases')->count());

        $this->selectBranch($this->director);
        $this->post(route('hr.discipline.store'), $this->openInput($hrFile))->assertSessionHasNoErrors();
        $case = (string) DB::table('hr_discipline_cases')->value('public_id');
        // The HR Manager cannot see that the case exists, nor open it.
        $this->selectBranch($this->hr);
        $this->get(route('hr.discipline.index'))->assertInertia(fn (Assert $page) => $page->where('discipline.cases', [])->where('discipline.counts', ['open' => 0, 'closed' => 0]));
        $this->get(route('hr.discipline.show', $case))->assertForbidden();
        $this->selectBranch($otherHr);
        $this->get(route('hr.discipline.show', $case))->assertForbidden();

        // A locum file (no account) can have a case.
        $locum = app(StaffFileService::class)->addLocum($this->hr, ['display_name' => 'Synthetic Locum', 'phone' => '012-345 6789', 'idempotency_key' => (string) Str::uuid()]);
        $this->selectBranch($this->hr);
        $this->post(route('hr.discipline.store'), $this->openInput($locum))->assertSessionHasNoErrors();
        $this->assertGreaterThanOrEqual(2, DB::table('audit_logs')->where('event', 'authorization.denied')->count());
    }

    public function test_postgresql_keeps_the_history(): void
    {
        $staff = $this->actor('ca');
        $worker = $this->file($staff);
        $this->openCase($worker);
        $case = DB::table('hr_discipline_cases')->sole();

        $this->assertRefused('P0001', fn () => DB::table('hr_discipline_cases')->where('id', $case->id)->update(['allegation_encrypted' => Crypt::encryptString('changed'), 'lock_version' => 2]));
        $this->assertRefused('P0001', fn () => DB::table('hr_discipline_cases')->where('id', $case->id)->delete());
        $this->assertRefused('P0001', fn () => DB::table('hr_discipline_events')->where('case_id', $case->id)->delete());
        // Closing without an outcome event is refused; a warning outcome needs a warning letter.
        $this->assertRefused('P0001', fn () => DB::table('hr_discipline_cases')->where('id', $case->id)->update(['status' => 'closed', 'outcome' => 'no_action',
            'closed_by_user_id' => $this->hr->id, 'closed_at' => now(), 'lock_version' => 2]));
        $this->assertRefused('23514', function () use ($case): void { // CHECK: a written warning needs its letter
            DB::table('hr_discipline_events')->insert(['organisation_id' => $case->organisation_id, 'case_id' => $case->id, 'kind' => 'outcome',
                'actor_user_id' => $this->hr->id, 'created_at' => now()]);
            DB::table('hr_discipline_cases')->where('id', $case->id)->update(['status' => 'closed', 'outcome' => 'written_warning',
                'closed_by_user_id' => $this->hr->id, 'closed_at' => now(), 'lock_version' => 2]);
        });
        // Nobody writes their own case.
        $this->assertRefused('23514', fn () => DB::table('hr_discipline_events')->insert(['organisation_id' => $case->organisation_id, 'case_id' => $case->id,
            'kind' => 'note', 'body_encrypted' => Crypt::encryptString('x'), 'actor_user_id' => $staff->id, 'created_at' => now()]));
        // A Director's or HR Manager's case is opened only by a Director.
        $otherHr = $this->actor('hr_manager');
        $hrFile = $this->file($otherHr, $this->director);
        $this->assertRefused('23514', fn () => DB::table('hr_discipline_cases')->insert(['public_id' => (string) Str::uuid(), 'organisation_id' => $case->organisation_id,
            'worker_id' => $hrFile->id, 'subject_user_id' => $otherHr->id, 'allegation_encrypted' => Crypt::encryptString('x'), 'incident_on' => '2026-11-01',
            'opened_by_user_id' => $this->hr->id, 'opened_at' => now(), 'status' => 'open', 'lock_version' => 1, 'created_at' => now(), 'updated_at' => now()]));
    }

    // ---- fixture ----

    /** @return array<string, string> */
    private function openInput(Worker $worker): array
    {
        return ['worker_id' => $worker->public_id, 'incident_on' => '2026-11-03', 'allegation' => self::ALLEGATION, 'idempotency_key' => (string) Str::uuid()];
    }

    private function openCase(Worker $worker): string
    {
        $this->selectBranch($this->hr);
        $this->post(route('hr.discipline.store'), $this->openInput($worker))->assertSessionHasNoErrors();

        return (string) DB::table('hr_discipline_cases')->orderByDesc('id')->value('public_id');
    }

    private function file(User $subject, ?User $manager = null): Worker
    {
        $manager ??= $this->hr;
        $files = app(StaffFileService::class);
        $worker = $files->openFile($manager, ['user_id' => $subject->id, 'idempotency_key' => (string) Str::uuid()]);
        $files->addPeriod($manager, $worker->public_id, ['type' => 'permanent', 'starts_on' => '2026-03-01', 'idempotency_key' => (string) Str::uuid()]);
        $files->addPosition($manager, $worker->public_id, ['title' => 'Clinic Assistant', 'from_date' => '2026-03-01', 'idempotency_key' => (string) Str::uuid()]);

        return $worker->fresh() ?? $worker;
    }

    /** Issues an HR-10a letter of the given type to the worker and returns its public id. */
    private function letter(Worker $worker, string $type): string
    {
        $this->selectBranch($this->hr);
        $body = "Dear {name},\n\n{details}\n\nReference {reference}, dated {date}.";
        $this->post(route('hr.letters.templates.store'), ['type' => $type, 'title' => 'Synthetic '.$type, 'title_bm' => 'Sintetik '.$type, 'body_en' => $body,
            'body_bm' => $body, 'idempotency_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
        $template = (string) DB::table('hr_letter_templates')->orderByDesc('id')->value('public_id');
        $this->post(route('hr.letters.issue'), ['worker_id' => $worker->public_id, 'template_id' => $template, 'language' => 'en', 'details' => 'Synthetic details.',
            'expected_template_version' => 1, 'idempotency_key' => (string) Str::uuid()])->assertSessionHasNoErrors();

        return (string) DB::table('hr_letters')->orderByDesc('id')->value('public_id');
    }

    private function businessDetails(): void
    {
        app(BusinessDetailsService::class)->save($this->director, [
            'expected_branch_id' => $this->branch->id, 'organisation_lock_version' => (int) OrganisationBusinessProfile::query()->value('lock_version'),
            'branch_lock_version' => (int) DB::table('branch_business_profiles')->where('branch_id', $this->branch->id)->value('lock_version'),
            'idempotency_key' => (string) Str::uuid(), 'legal_name' => 'Synthetic Group', 'clinic_name' => 'Synthetic Discipline Clinic',
            'address_line_1' => '1 Example Street', 'postcode' => '50000', 'city' => 'Example City', 'state' => 'Example State',
            'country' => 'MY', 'phone' => '03-2000 0001', 'email' => 'hr@clinic.test', 'registration_type' => 'BRN', 'registration_number' => 'SYN-1',
        ], null);
    }

    private function assertRefused(string $state, callable $write): void
    {
        try {
            DB::transaction(fn () => $write());
            $this->fail("Expected PostgreSQL to refuse with {$state}.");
        } catch (QueryException $e) {
            $this->assertSame($state, $e->errorInfo[0] ?? null, $e->getMessage());
        }
    }
}
