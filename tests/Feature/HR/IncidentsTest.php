<?php

namespace Tests\Feature\HR;

use App\Domain\Access\PermissionCatalogue;
use App\Domain\HR\Discipline\Services\DisciplineService;
use App\Domain\HR\Incidents\IncidentPermissions;
use App\Domain\HR\Records\Services\DocumentService;
use App\Domain\HR\Records\Services\StaffFileService;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use Symfony\Component\HttpFoundation\Response;
use Tests\Feature\Visit\VisitTestCase;

/**
 * HR-10d incident reports (HR-PLAN 20.6; owner, 2026-11-10). Synthetic data only.
 */
class IncidentsTest extends VisitTestCase
{
    private const TEXT = 'SYNTHETIC-INCIDENT: a box of syringes fell from the top shelf in the store room.';

    private User $hr;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(DocumentService::DISK);
        $this->travelTo(CarbonImmutable::parse('2026-11-10 09:00', $this->branch->timezone));
        $this->hr = $this->actor('hr_manager');
    }

    public function test_grants_are_exact_and_the_tv_account_gets_nothing(): void
    {
        $roles = PermissionCatalogue::roles();
        $holders = fn (string $p): array => collect($roles)->filter(fn (array $ps) => in_array($p, $ps, true))->keys()->sort()->values()->all();
        $this->assertSame(['director', 'hr_manager'], $holders(IncidentPermissions::MANAGE));
        $this->assertCount(11, $holders(IncidentPermissions::REPORT));
        $this->assertNotContains('queue_display', $holders(IncidentPermissions::REPORT));
        $this->assertFalse(PermissionCatalogue::isAdministrativeAuthority(IncidentPermissions::MANAGE));
        $this->selectBranch($this->actor('queue_display'));
        $this->get(route('hr.incidents.index'))->assertForbidden();
        $ca = $this->actor('ca');
        $this->selectBranch($ca);
        $this->get(route('hr.incidents.index'))->assertOk()->assertInertia(fn (Assert $page) => $page->component('HR/Incidents')
            ->where('incidents.tabs', ['mine'])->where('incidents.canManage', false)->where('incidents.reports', []));
        $this->post(route('hr.incidents.acknowledge', (string) Str::uuid()), [])->assertForbidden();
    }

    public function test_staff_report_managers_handle_and_the_reporter_sees_the_status_only(): void
    {
        $ca = $this->actor('ca');
        $this->selectBranch($ca);
        $this->report(['occurred_on' => '2026-11-11'])->assertSessionHasErrors('occurred_on'); // tomorrow
        $this->report(['description' => 'short'])->assertSessionHasErrors('description');
        $this->report(['category' => 'gossip'])->assertSessionHasErrors('category');
        $this->report(['branch_id' => 'NOWHERE'])->assertSessionHasErrors('branch_id');
        $this->report()->assertSessionHasNoErrors();
        $incident = (string) DB::table('hr_incidents')->value('public_id');
        $row = DB::table('hr_incidents')->sole();
        $this->assertStringNotContainsString('SYNTHETIC', (string) $row->description_encrypted);
        $this->assertSame('2026-11-10 00:30:00', CarbonImmutable::parse((string) $row->occurred_at, 'UTC')->format('Y-m-d H:i:s')); // 08:30 Kuala Lumpur

        // The reporter cannot handle their own report.
        $this->post(route('hr.incidents.acknowledge', $incident), ['expected_lock_version' => 1, 'idempotency_key' => (string) Str::uuid()])->assertForbidden();

        $this->selectBranch($this->hr);
        $this->get(route('hr.incidents.index'))->assertInertia(fn (Assert $page) => $page->where('incidents.counts.open', 1)->has('incidents.reports', 1)
            ->missing('incidents.reports.0.description'));
        $this->post(route('hr.incidents.close', $incident), ['outcome' => 'Shelf fixed', 'expected_lock_version' => 1, 'idempotency_key' => (string) Str::uuid()])
            ->assertSessionHasErrors('incident'); // acknowledge first
        $this->post(route('hr.incidents.acknowledge', $incident), ['expected_lock_version' => 1, 'idempotency_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
        $this->post(route('hr.incidents.note', $incident), ['note' => 'SYNTHETIC-NOTE: checked the store room.', 'idempotency_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
        $this->post(route('hr.incidents.action', $incident), ['description' => 'Fit a shelf lip', 'owner_id' => $ca->id, 'due_on' => '2026-11-09',
            'idempotency_key' => (string) Str::uuid()])->assertSessionHasErrors('due_on');
        $this->post(route('hr.incidents.action', $incident), ['description' => 'Fit a shelf lip', 'owner_id' => $ca->id, 'due_on' => '2026-11-14',
            'idempotency_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
        $action = DB::table('hr_incident_actions')->sole();
        $this->post(route('hr.incidents.close', $incident), ['outcome' => 'Shelf fixed', 'expected_lock_version' => 2, 'idempotency_key' => (string) Str::uuid()])
            ->assertSessionHasErrors('incident'); // an action is still open
        $this->post(route('hr.incidents.action-done', [$incident, $action->public_id]), ['expected_lock_version' => 1, 'idempotency_key' => (string) Str::uuid()])
            ->assertSessionHasNoErrors();
        $this->post(route('hr.incidents.close', $incident), ['outcome' => 'SYNTHETIC-OUTCOME: shelf lip fitted.', 'expected_lock_version' => 2, 'idempotency_key' => (string) Str::uuid()])
            ->assertSessionHasNoErrors();
        $this->post(route('hr.incidents.note', $incident), ['note' => 'Late', 'idempotency_key' => (string) Str::uuid()])->assertSessionHasErrors('incident');
        $this->get(route('hr.incidents.show', $incident))->assertInertia(fn (Assert $page) => $page->component('HR/Incident')->where('incident.manager', true)
            ->where('incident.status', 'closed')->where('incident.outcome', 'SYNTHETIC-OUTCOME: shelf lip fitted.')->has('incident.notes', 1)->has('incident.actions', 1));

        // The reporter sees the status, never the notes, actions or outcome.
        $this->selectBranch($ca);
        $this->get(route('hr.incidents.show', $incident))->assertOk()->assertInertia(fn (Assert $page) => $page->where('incident.manager', false)
            ->where('incident.status', 'closed')->where('incident.description', self::TEXT)->missing('incident.notes')->missing('incident.actions')
            ->missing('incident.outcome'));
        $this->get(route('hr.incidents.index'))->assertInertia(fn (Assert $page) => $page->has('incidents.mine', 1)->where('incidents.mine.0.status', 'closed'));
        // Another staff member cannot open it.
        $this->selectBranch($this->actor('ca'));
        $this->get(route('hr.incidents.show', $incident))->assertForbidden();

        // The reporter's own views are not recorded; a manager's open is.
        $this->assertSame(['hr.incidents.reported', 'hr.incidents.acknowledged', 'hr.incidents.note_added', 'hr.incidents.action_added',
            'hr.incidents.action_done', 'hr.incidents.closed', 'hr.incidents.viewed'],
            DB::table('audit_logs')->where('event', 'like', 'hr.incidents.%')->orderBy('id')->pluck('event')->all());
        $this->assertStringNotContainsString('SYNTHETIC', DB::table('audit_logs')->get()->toJson());
        $this->assertSame(0, DB::table('audit_logs')->where('event', 'like', 'hr.incidents.%')->whereNotNull('subject_id')->count());
    }

    public function test_patient_care_reports_are_listed_apart_for_clinical_review(): void
    {
        $this->selectBranch($this->actor('ca'));
        $this->report(['category' => 'patient_care'])->assertSessionHasNoErrors();
        $this->report()->assertSessionHasNoErrors();
        $this->selectBranch($this->hr);
        $this->get(route('hr.incidents.index', ['tab' => 'clinical']))->assertInertia(fn (Assert $page) => $page->where('incidents.counts.clinical', 1)
            ->where('incidents.counts.open', 1)->has('incidents.reports', 1)->where('incidents.reports.0.needsClinicalReview', true));
    }

    public function test_a_staff_conduct_report_is_linked_to_a_case_only_by_someone_who_handles_it(): void
    {
        $subject = $this->actor('ca');
        $worker = app(StaffFileService::class)->openFile($this->hr, ['user_id' => $subject->id, 'idempotency_key' => (string) Str::uuid()]);
        $case = app(DisciplineService::class)->open($this->hr, ['worker_id' => $worker->public_id, 'incident_on' => '2026-11-09',
            'allegation' => 'Synthetic allegation about conduct at the counter.', 'idempotency_key' => (string) Str::uuid()]);
        $reporter = $this->actor('ca');
        $this->selectBranch($reporter);
        $this->report(['category' => 'staff_conduct'])->assertSessionHasNoErrors();
        $this->report()->assertSessionHasNoErrors();
        [$conduct, $other] = DB::table('hr_incidents')->orderBy('id')->pluck('public_id')->all();

        $this->selectBranch($this->hr);
        $this->get(route('hr.incidents.show', $conduct))->assertInertia(fn (Assert $page) => $page->where('incident.link.canLink', true)
            ->where('incident.link.options.0.value', $case));
        $this->post(route('hr.incidents.link', $other), ['case_id' => $case, 'expected_lock_version' => 1, 'idempotency_key' => (string) Str::uuid()])
            ->assertSessionHasErrors('case_id'); // not a staff-conduct report
        $this->post(route('hr.incidents.link', $conduct), ['case_id' => $case, 'expected_lock_version' => 1, 'idempotency_key' => (string) Str::uuid()])
            ->assertSessionHasNoErrors();
        $this->assertNotNull(DB::table('hr_incidents')->where('public_id', $conduct)->value('discipline_case_id'));
        $this->get(route('hr.incidents.show', $conduct))->assertInertia(fn (Assert $page) => $page->where('incident.link.linked', true)->where('incident.link.caseId', $case));
        $this->post(route('hr.incidents.link', $conduct), ['case_id' => $case, 'expected_lock_version' => 2, 'idempotency_key' => (string) Str::uuid()])
            ->assertSessionHasErrors('case_id'); // once
    }

    public function test_postgresql_keeps_the_history(): void
    {
        $ca = $this->actor('ca');
        $this->selectBranch($ca);
        $this->report()->assertSessionHasNoErrors();
        $incident = DB::table('hr_incidents')->sole();

        $this->assertRefused('P0001', fn () => DB::table('hr_incidents')->where('id', $incident->id)->update(['description_encrypted' => Crypt::encryptString('x'), 'lock_version' => 2]));
        $this->assertRefused('P0001', fn () => DB::table('hr_incidents')->where('id', $incident->id)->update(['status' => 'closed', 'outcome_encrypted' => Crypt::encryptString('x'),
            'closed_by_user_id' => $this->hr->id, 'closed_at' => now(), 'lock_version' => 2])); // not acknowledged
        $this->assertRefused('P0001', fn () => DB::table('hr_incidents')->where('id', $incident->id)->delete());
        // The reporter never handles their own report.
        $this->assertRefused('23514', fn () => DB::table('hr_incident_notes')->insert(['organisation_id' => $incident->organisation_id, 'incident_id' => $incident->id,
            'body_encrypted' => Crypt::encryptString('x'), 'author_user_id' => $ca->id, 'created_at' => now()]));
        $this->assertRefused('23514', fn () => DB::table('hr_incidents')->where('id', $incident->id)->update(['status' => 'acknowledged',
            'acknowledged_by_user_id' => $ca->id, 'acknowledged_at' => now(), 'lock_version' => 2]));
        // A link only by someone who handles discipline.
        $finance = $this->actor('finance_officer');
        $subject = $this->actor('ca');
        $worker = app(StaffFileService::class)->openFile($this->hr, ['user_id' => $subject->id, 'idempotency_key' => (string) Str::uuid()]);
        $case = app(DisciplineService::class)->open($this->hr, ['worker_id' => $worker->public_id, 'incident_on' => '2026-11-09',
            'allegation' => 'Synthetic allegation for the link guard.', 'idempotency_key' => (string) Str::uuid()]);
        $caseId = (int) DB::table('hr_discipline_cases')->where('public_id', $case)->value('id');
        $this->assertRefused('23514', fn () => DB::table('hr_incidents')->where('id', $incident->id)->update(['discipline_case_id' => $caseId,
            'linked_by_user_id' => $finance->id, 'linked_at' => now(), 'lock_version' => 2]));
    }

    #[RequiresPhpExtension('gd')]
    public function test_photos_are_re_encoded_encrypted_and_at_most_three(): void
    {
        $this->selectBranch($this->actor('ca'));
        $photos = array_map(fn (int $i): UploadedFile => UploadedFile::fake()->image("photo{$i}.jpg", 40 + $i, 40), [1, 2, 3, 4]);
        $this->report([], $photos)->assertSessionHasErrors('photos');
        $this->report([], array_slice($photos, 0, 2))->assertSessionHasNoErrors();
        $this->assertSame(2, DB::table('hr_incident_photos')->count());
        $photo = DB::table('hr_incident_photos')->first();
        $this->assertStringStartsNotWith("\xFF\xD8", (string) Storage::disk(DocumentService::DISK)->get((string) $photo->stored_path)); // stored encrypted
        $incident = (string) DB::table('hr_incidents')->value('public_id');
        $this->get(route('hr.incidents.photos.show', [$incident, $photo->public_id]))->assertOk()->assertHeader('Content-Type', 'image/jpeg');
        $this->report([], [UploadedFile::fake()->createWithContent('scan.pdf', "%PDF-1.4\n%%EOF\n")])->assertSessionHasErrors('photos.0');
    }

    // ---- fixture ----

    /**
     * @param  array<string, mixed>  $overrides
     * @param  list<UploadedFile>  $photos
     * @return TestResponse<Response>
     */
    private function report(array $overrides = [], array $photos = []): TestResponse
    {
        return $this->post(route('hr.incidents.store'), ['branch_id' => (string) $this->branch->code, 'occurred_on' => '2026-11-10', 'occurred_time' => '08:30',
            'category' => 'workplace_safety', 'description' => self::TEXT, 'idempotency_key' => (string) Str::uuid(), 'photos' => $photos, ...$overrides]);
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
