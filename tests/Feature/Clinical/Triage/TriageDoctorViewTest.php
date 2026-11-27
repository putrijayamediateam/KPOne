<?php

namespace Tests\Feature\Clinical\Triage;

use App\Domain\Access\PermissionCatalogue;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Clinical\Models\ClinicalEncounter;
use App\Domain\Clinical\Triage\TriagePermissions;
use App\Domain\Clinical\Triage\TriageViewProjection;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Queue\Services\QueueDirectoryService;
use App\Domain\Visit\Services\VisitAdministrationService;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Tests\Support\SyntheticVisitCompletion;

/** TRI-1e: the patient's own doctor's read-only triage view (TRI-1e-DOCTOR-VIEW sections 2 and 7). Synthetic data only. */
class TriageDoctorViewTest extends TriageTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::now($this->branch->timezone)->setTime(10, 0));
    }

    public function test_the_own_doctor_reads_every_save_and_the_open_is_audited_first(): void
    {
        $this->approvedGuideline();
        // This test scans the whole rendered payload for forbidden field tokens.
        // Random display names such as "Lockman" can collide with "lock".
        $ca = $this->actor('ca');
        $ca->forceFill(['name' => 'Synthetic Triage Recorder'])->save();
        [$ca, $visit] = $this->waitingFixture($ca, ['full_name' => 'Synthetic Triage Patient']);
        $doctor = User::query()->findOrFail($visit->assigned_doctor_user_id);
        $this->triage($ca, $visit, ['pulse_bpm' => 130, 'yellow_sign_codes' => ['ys_01'], 'chosen_zone' => 'yellow']);
        $this->travel(2)->minutes();
        $this->triage($ca, $visit, ['red_flag_codes' => ['rf_02'], 'chosen_zone' => 'red']);

        $this->selectBranch($doctor);
        $page = $this->actingAs($doctor)->get(route('visits.triage.view', $visit))->assertOk()
            ->assertHeader('Cache-Control', 'max-age=0, must-revalidate, no-store, private')
            ->viewData('page');
        $this->assertSame('Triage/View', $page['component']);
        $props = $page['props'];
        $this->assertSame('red', $props['currentZone']);
        $this->assertSame([2, 1], array_column($props['saves'], 'sequence'), 'newest first');
        $this->assertSame(['visitNumber', 'patientName', 'patientNumber', 'queueNumber', 'priority'], array_keys($props['visit']));
        $this->assertSame(
            ['sequence', 'recordedAt', 'recordedBy', 'readings', 'guidelineVersion', 'redFlags', 'yellowSigns', 'suggestedZone', 'chosenZone', 'reason'],
            array_keys($props['saves'][0]),
        );
        $this->assertSame(['Synthetic red flag two'], $props['saves'][0]['redFlags']);
        $this->assertSame(['Synthetic yellow sign one'], $props['saves'][1]['yellowSigns']);
        $this->assertSame('130', $props['saves'][1]['readings']['pulse_bpm']);
        $this->assertSame($ca->name, $props['saves'][0]['recordedBy']);
        $this->assertSame(1, $props['saves'][0]['guidelineVersion']);
        $json = json_encode(['visit' => $props['visit'], 'currentZone' => $props['currentZone'], 'saves' => $props['saves']], JSON_THROW_ON_ERROR);
        foreach (['lock', 'idempotency', 'payload_hash', 'age_band', 'ageBand', 'expected'] as $forbidden) {
            $this->assertStringNotContainsStringIgnoringCase($forbidden, $json, "no {$forbidden} on a view-only page");
        }

        $audit = AuditLog::query()->where('event', 'triage.viewed')->sole();
        $this->assertSame($doctor->id, $audit->actor_user_id);
        $this->assertSame(['ids' => ['visit' => $visit->id, 'patient' => $visit->patient_id]], $audit->metadata);
        $this->assertStringNotContainsString('130', json_encode($audit->metadata, JSON_THROW_ON_ERROR));
    }

    public function test_reasons_show_as_words_and_unknown_signs_and_missing_guidelines_are_named(): void
    {
        [$ca, $visit] = $this->waitingFixture();
        $this->triage($ca, $visit, ['chosen_zone' => null]); // readings only, before any guideline
        $this->approvedGuideline();
        $this->triage($ca, $visit, ['chosen_zone' => 'yellow', 'override_reason_code' => 'staff_concern']);

        $view = app(TriageViewProjection::class)->view($visit->refresh(), $this->branch);
        $this->assertSame('Staff concern', $view['saves'][0]['reason']);
        $this->assertSame('green', $view['saves'][0]['suggestedZone']);
        $this->assertNull($view['saves'][1]['guidelineVersion'], 'a readings-only save before any guideline');
        $this->assertNull($view['saves'][1]['chosenZone']);
    }

    public function test_only_the_patients_own_doctor_may_open_it(): void
    {
        $this->approvedGuideline();
        [$ca, $visit] = $this->waitingFixture();
        $this->triage($ca, $visit);

        $roles = array_diff(array_keys(PermissionCatalogue::roles()), ['resident_doctor']);
        foreach ([...$roles, 'another doctor'] as $role) {
            $actor = $role === 'another doctor' ? $this->doctor() : $this->actor($role);
            $this->selectBranch($actor);
            $status = $this->actingAs($actor)->get(route('visits.triage.view', $visit))->status();
            $this->assertSame(403, $status, $role);
        }
        $this->assertSame(0, AuditLog::query()->where('event', 'triage.viewed')->count(), 'a refused open records nothing');

        // The recording POST still refuses the doctor.
        $doctor = User::query()->findOrFail($visit->assigned_doctor_user_id);
        $this->selectBranch($doctor);
        $this->actingAs($doctor)->post(route('visits.triage.store', $visit), [])->assertForbidden();
    }

    public function test_once_started_the_attending_clinician_is_the_own_doctor_never_both(): void
    {
        $this->approvedGuideline();
        [$doctor, $ca, $visit, $queue] = $this->servingFixture();
        $this->triage($ca, $visit);
        $this->startEncounter($doctor, $visit, $queue);
        $other = $this->doctor();
        DB::table('visits')->where('id', $visit->id)->update(['assigned_doctor_user_id' => $other->id]);

        $this->selectBranch($other);
        $this->actingAs($other)->get(route('visits.triage.view', $visit))->assertForbidden();
        $this->selectBranch($doctor);
        $this->actingAs($doctor)->get(route('visits.triage.view', $visit))->assertOk();
        $this->assertSame($doctor->id, TriageViewProjection::ownDoctorId($visit->refresh()));
        $this->assertNotNull(ClinicalEncounter::query()->where('visit_id', $visit->id)->value('id'));
    }

    public function test_held_completed_and_cancelled_visits_follow_the_same_own_doctor_rule(): void
    {
        $this->approvedGuideline();
        [$doctor, $ca, $visit, $queue] = $this->servingFixture();
        $this->triage($ca, $visit);
        $encounter = $this->startEncounter($doctor, $visit, $queue);

        $this->holdEncounter($doctor, $visit, $queue, $encounter);
        $this->selectBranch($doctor);
        $this->actingAs($doctor)->get(route('visits.triage.view', $visit))->assertOk();

        SyntheticVisitCompletion::complete($visit->refresh());
        $this->actingAs($doctor)->get(route('visits.triage.view', $visit))->assertOk();

        // Cancelled before any consultation: the assigned doctor still reads what triage recorded.
        [, $cancelled, $cancelledQueue] = $this->waitingFixture($ca);
        $this->triage($ca, $cancelled);
        $this->selectBranch($ca);
        app(VisitAdministrationService::class)->cancel($cancelled->refresh(), [
            'expected_branch_id' => $cancelled->branch_id, 'lock_version' => $cancelled->lock_version,
            'queue_lock_version' => $cancelledQueue->refresh()->lock_version, 'cancellation_reason' => 'Synthetic cancellation',
        ], $ca);
        $assigned = User::query()->findOrFail($cancelled->assigned_doctor_user_id);
        $this->selectBranch($assigned);
        $this->actingAs($assigned)->get(route('visits.triage.view', $cancelled))->assertOk();
    }

    public function test_a_head_request_is_refused_like_a_get_and_records_nothing(): void
    {
        $this->approvedGuideline();
        [, $visit] = $this->waitingFixture();
        $doctor = User::query()->findOrFail($visit->assigned_doctor_user_id);
        $other = $this->doctor();

        $this->selectBranch($other);
        $this->actingAs($other)->call('HEAD', route('visits.triage.view', $visit))->assertForbidden();
        $this->selectBranch($doctor);
        $this->actingAs($doctor)->call('HEAD', route('visits.triage.view', $visit))->assertNoContent();
        $this->assertSame(0, AuditLog::query()->where('event', 'triage.viewed')->count());
    }

    public function test_a_visit_at_another_branch_is_not_found(): void
    {
        $this->approvedGuideline();
        [$ca, $visit] = $this->waitingFixture();
        $doctor = User::query()->findOrFail($visit->assigned_doctor_user_id);
        $other = Branch::query()->where('organisation_id', $this->branch->organisation_id)->whereKeyNot($this->branch->id)->firstOrFail();
        $elsewhere = $this->actor('resident_doctor', $other);
        DB::table('visits')->where('id', $visit->id)->update(['assigned_doctor_user_id' => $elsewhere->id]);

        $this->selectBranch($elsewhere, $other);
        $this->actingAs($elsewhere)->get(route('visits.triage.view', $visit))->assertNotFound();
        $this->assertSame(0, AuditLog::query()->where('event', 'triage.viewed')->count());
        $this->assertNotSame($doctor->id, $elsewhere->id);
    }

    public function test_a_failed_audit_write_shows_nothing_and_opening_never_writes(): void
    {
        $this->approvedGuideline();
        [$ca, $visit] = $this->waitingFixture();
        $doctor = User::query()->findOrFail($visit->assigned_doctor_user_id);
        $this->triage($ca, $visit, ['pulse_bpm' => 131, 'chosen_zone' => 'yellow']);

        $tables = ['visit_triage_assessments', 'visits', 'queue_entries', 'clinical_encounters', 'encounter_vital_observations'];
        $before = collect($tables)->mapWithKeys(fn (string $t): array => [$t => DB::table($t)->orderBy('id')->get()->toJson()]);
        $this->selectBranch($doctor);
        $this->actingAs($doctor)->get(route('visits.triage.view', $visit))->assertOk();
        $this->assertSame($before->all(), collect($tables)->mapWithKeys(fn (string $t): array => [$t => DB::table($t)->orderBy('id')->get()->toJson()])->all());

        $this->app->instance(AuditRecorder::class, new class extends AuditRecorder
        {
            public function record(string $event, ?Model $subject = null, array $metadata = [], ?User $actor = null, ?Branch $branch = null, ?int $organisationId = null): ?AuditLog
            {
                return null;
            }
        });
        config(['app.debug' => false]);
        $response = $this->actingAs($doctor)->get(route('visits.triage.view', $visit));
        $response->assertStatus(500);
        $this->assertStringNotContainsString($visit->patient->full_name, (string) $response->getContent());
        $this->assertStringNotContainsString('131', (string) $response->getContent());
    }

    public function test_the_open_is_never_wrapped_in_a_transaction(): void
    {
        // ReadOnlyReviewAudit commits the audit row on its own only when the caller holds no transaction.
        $controller = (string) file_get_contents(app_path('Http/Controllers/TriageViewController.php'));
        $projection = (string) file_get_contents(app_path('Domain/Clinical/Triage/TriageViewProjection.php'));
        $this->assertStringNotContainsString('DB::transaction', $controller);
        $this->assertStringNotContainsString('DB::transaction', $projection);
        $this->assertStringNotContainsString('beginTransaction', $controller.$projection);
        $this->assertMatchesRegularExpression('/\$this->authorise\(\$actor, \$visit\);\s+\$this->audit->record\(\$actor, \'triage\.viewed\'/', $projection, 'authorise, then audit, then build');
    }

    public function test_links_appear_only_for_the_own_doctor(): void
    {
        $this->approvedGuideline();
        [$ca, $visit] = $this->waitingFixture();
        $doctor = User::query()->findOrFail($visit->assigned_doctor_user_id);
        $this->triage($ca, $visit);

        $this->selectBranch($doctor);
        $row = collect(app(QueueDirectoryService::class)->snapshot($doctor)['waiting']['data'])->firstWhere('visitNumber', $visit->visit_number);
        $this->assertSame(route('visits.triage.view', $visit), $row['triageViewUrl']);
        $this->selectBranch($ca);
        $row = collect(app(QueueDirectoryService::class)->snapshot($ca)['waiting']['data'])->firstWhere('visitNumber', $visit->visit_number);
        $this->assertNull($row['triageViewUrl']);

        [$attending, , $serving, $queue] = $this->servingFixture(null, $ca);
        $this->startEncounter($attending, $serving, $queue);

        $this->selectBranch($attending);
        $this->actingAs($attending)->get(route('encounters.show', $serving))->assertOk()
            ->assertInertia(fn ($page) => $page->where('clinical.visit.triageViewUrl', route('visits.triage.view', $serving)));

        // Serving row: the link follows the attending clinician, never the assigned doctor alone. Before any change the
        // attending (also assigned) doctor has the link; after the assigned doctor is rewritten (only possible by a direct
        // write, since the doctor cannot change after Call In) the newly assigned doctor sees the row on their own board
        // but gets no link.
        $servingRow = fn (User $viewer) => collect(app(QueueDirectoryService::class)->snapshot($viewer, ['status' => 'serving'])['serving'])
            ->firstWhere('visitNumber', $serving->visit_number);
        $this->selectBranch($attending);
        $this->assertSame(route('visits.triage.view', $serving), $servingRow($attending)['triageViewUrl']);
        $reassigned = $this->doctor();
        DB::table('visits')->where('id', $serving->id)->update(['assigned_doctor_user_id' => $reassigned->id]);
        $this->selectBranch($reassigned);
        $row = $servingRow($reassigned);
        $this->assertNotNull($row, 'the reassigned doctor sees the row on their own board');
        $this->assertNull($row['triageViewUrl'], 'but gets no link: the attending clinician is the own doctor');
    }

    public function test_the_permission_is_the_resident_doctors_alone_and_the_grant_replays_without_revoking(): void
    {
        foreach (PermissionCatalogue::roles() as $name => $permissions) {
            $this->assertSame($name === 'resident_doctor', in_array(TriagePermissions::VIEW_OWN, $permissions, true), $name);
        }
        $this->assertFalse(PermissionCatalogue::isAdministrativeAuthority(TriagePermissions::VIEW_OWN));

        $role = Role::findByName('resident_doctor', 'web');
        $role->revokePermissionTo(TriagePermissions::VIEW_OWN);
        $migration = require database_path('migrations/2026_11_20_500700_grant_triage_view_permission.php');
        $migration->up();
        $migration->up();
        $this->assertTrue($role->fresh()->hasPermissionTo(TriagePermissions::VIEW_OWN));
        $this->assertSame(1, DB::table('permissions')->where('name', TriagePermissions::VIEW_OWN)->count());
        $migration->down();
        $this->assertTrue($role->fresh()->hasPermissionTo(TriagePermissions::VIEW_OWN), 'down() keeps the grant');
    }
}
