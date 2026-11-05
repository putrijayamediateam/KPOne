<?php

namespace Tests\Feature\Patient;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Organisation\Models\Organisation;
use App\Domain\Patient\Models\Patient;
use App\Domain\Patient\Services\PatientAdministrationService;
use App\Domain\Patient\Services\PatientChangeHistoryService;
use App\Domain\Patient\Services\PatientMergeService;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Feature\Visit\VisitTestCase;

/** REG-4 (owner decisions 2026-11-05): field names only, CA Supervisor and Director only, changes only. */
class PatientChangeHistoryTest extends VisitTestCase
{
    private function edit(Patient $patient, User $actor, array $changes): Patient
    {
        $patient->refresh();
        $this->selectBranch($actor);
        app(PatientAdministrationService::class)->update($patient, array_merge([
            'full_name' => $patient->full_name, 'sex' => $patient->sex, 'date_of_birth' => $patient->date_of_birth?->format('Y-m-d'),
            'mobile_phone' => $patient->mobile_phone, 'phone_country' => 'MY', 'email' => $patient->email,
            'lock_version' => $patient->lock_version, 'expected_branch_id' => $this->branch->id,
        ], $changes), $actor);

        return $patient->refresh();
    }

    public function test_director_and_ca_supervisor_see_field_names_and_never_values(): void
    {
        $supervisor = $this->actor('ca_supervisor');
        $patient = $this->patient();
        $this->edit($patient, $supervisor, ['full_name' => 'Synthetic Renamed Historyperson', 'mobile_phone' => '+60123456782']);

        foreach (['ca_supervisor', 'director'] as $role) {
            $viewer = $this->actor($role);
            $this->selectBranch($viewer);
            $response = $this->actingAs($viewer)->get('/patients/'.$patient->patient_number)->assertOk()
                ->assertInertia(fn ($page) => $page->component('Patient/Show')
                    ->where('history.rows.0.label', 'Contact details changed')
                    ->where('history.rows.0.fields', ['Mobile phone'])
                    ->where('history.rows.0.actorName', $supervisor->name)
                    ->where('history.rows.1.label', 'Personal details changed')
                    ->where('history.rows.1.fields', ['Full name'])
                    ->missing('patient.activity'));
            $history = json_encode($response->viewData('page')['props']['history']);
            $this->assertStringNotContainsString('Historyperson', $history);
            $this->assertStringNotContainsString('60123456782', $history);
            $this->assertStringNotContainsString('patient.contact.updated', json_encode(array_column($response->viewData('page')['props']['history']['rows'], 'label')));
        }
    }

    public function test_other_roles_get_no_history_and_the_endpoint_refuses_them(): void
    {
        $patient = $this->patient();
        foreach (['ca', 'resident_doctor'] as $role) {
            $viewer = $this->actor($role);
            $this->selectBranch($viewer);
            $this->actingAs($viewer)->get('/patients/'.$patient->patient_number)->assertOk()
                ->assertInertia(fn ($page) => $page->where('history', null)->missing('patient.activity'));
            $this->actingAs($viewer)->getJson('/patients/'.$patient->patient_number.'/history')->assertForbidden();
        }
        foreach (['hr_manager', 'technical_admin', 'finance_officer'] as $role) {
            $this->actingAs($this->actor($role))->getJson('/patients/'.$patient->patient_number.'/history')->assertForbidden();
        }
    }

    public function test_only_changes_are_listed_and_whatsapp_opens_are_not(): void
    {
        $supervisor = $this->actor('ca_supervisor');
        $patient = $this->patient();
        app(AuditRecorder::class)->record('patient.whatsapp_opened', $patient, ['patient_id' => $patient->id], $supervisor);
        app(AuditRecorder::class)->record('patient.identifier.added', $patient, ['identifier_type' => 'passport', 'issuing_country_code' => 'MY', 'record_version' => 1], $supervisor);

        $this->selectBranch($supervisor);
        $rows = app(PatientChangeHistoryService::class)->page($supervisor, $patient)['rows'];

        $this->assertNotContains('patient.whatsapp_opened', array_column($rows, 'event'));
        $this->assertSame('Passport added', $rows[0]['label']);
        $this->assertSame([], $rows[0]['fields']);
    }

    public function test_the_first_page_holds_ten_rows_and_older_rows_load_by_cursor_without_overlap(): void
    {
        $supervisor = $this->actor('ca_supervisor');
        $patient = $this->patient();
        foreach (range(1, 13) as $index) {
            DB::table('audit_logs')->insert([
                'request_id' => (string) Str::uuid(), 'organisation_id' => $patient->organisation_id, 'actor_user_id' => $supervisor->id,
                'event' => 'patient.address.updated', 'subject_type' => $patient->getMorphClass(), 'subject_id' => $patient->id,
                'metadata' => json_encode(['changed_fields' => ['city'], 'record_version' => $index]), 'occurred_at' => now()->subMinutes(100 - $index),
            ]);
        }
        $this->selectBranch($supervisor);
        $first = app(PatientChangeHistoryService::class)->page($supervisor, $patient, null, PatientChangeHistoryService::FIRST_PAGE);
        $this->assertCount(10, $first['rows']);
        $this->assertNotNull($first['nextCursor']);

        $older = $this->actingAs($supervisor)->getJson('/patients/'.$patient->patient_number.'/history?cursor='.urlencode($first['nextCursor']))
            ->assertOk()->json();
        $this->assertStringContainsString('no-store', (string) $this->actingAs($supervisor)->getJson('/patients/'.$patient->patient_number.'/history')->headers->get('Cache-Control'));
        $ids = array_merge(array_column($first['rows'], 'id'), array_column($older['rows'], 'id'));
        $this->assertCount(count(array_unique($ids)), $ids);
        $this->assertNull($older['nextCursor']);
        $total = DB::table('audit_logs')->where('subject_type', $patient->getMorphClass())->where('subject_id', $patient->id)
            ->whereIn('event', array_keys(PatientChangeHistoryService::EVENTS))->count();
        $this->assertCount($total, $ids);
        $this->assertSame('City', $older['rows'][0]['fields'][0]);
    }

    public function test_the_kept_record_shows_the_merged_records_changes_and_the_merge(): void
    {
        $ca = $this->actor('ca');
        $supervisor = $this->actor('ca_supervisor');
        $kept = $this->patient();
        $duplicate = $this->patient();
        $this->edit($duplicate, $supervisor, ['email' => 'synthetic.history@kpone.test']);
        $this->selectBranch($ca);
        $request = app(PatientMergeService::class)->propose($ca, $kept, [
            'kind' => 'merge', 'other_patient_number' => $duplicate->patient_number, 'reason_code' => 'same_person_registered_twice',
            'expected_branch_id' => $this->branch->id, 'kept_lock_version' => $kept->lock_version,
            'other_lock_version' => $duplicate->refresh()->lock_version, 'idempotency_key' => (string) Str::uuid(),
        ]);
        $this->selectBranch($supervisor);
        app(PatientMergeService::class)->approve($supervisor, $request, ['expected_branch_id' => $this->branch->id, 'lock_version' => $request->lock_version]);

        $rows = app(PatientChangeHistoryService::class)->page($supervisor, $kept->refresh())['rows'];
        $this->assertSame('Records merged', $rows[0]['label']);
        $this->assertSame($kept->patient_number, $rows[0]['keptPatientNumber']);
        $this->assertSame($duplicate->patient_number, $rows[0]['mergedPatientNumber']);
        $contact = collect($rows)->firstWhere('event', 'patient.contact.updated');
        $this->assertSame(['Email'], $contact['fields']);
        $this->assertSame($duplicate->patient_number, $contact['recordNumber']);
        $this->assertFalse($contact['isThisRecord']);
        $this->assertStringNotContainsString('synthetic.history', json_encode($rows));

        $this->actingAs($supervisor)->getJson('/patients/'.$duplicate->patient_number.'/history')->assertOk()
            ->assertJsonPath('patientNumber', $kept->patient_number);
    }

    public function test_the_permission_belongs_to_ca_supervisor_and_director_only_and_the_grant_is_additive(): void
    {
        $holders = Role::query()->whereHas('permissions', fn ($query) => $query->where('name', PatientChangeHistoryService::PERMISSION))
            ->orderBy('name')->pluck('name')->all();
        $this->assertSame(['ca_supervisor', 'director'], $holders);

        Role::findByName('ca_supervisor', 'web')->revokePermissionTo(PatientChangeHistoryService::PERMISSION);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $migration = require database_path('migrations/2026_11_06_100300_grant_patient_history_permission.php');
        $migration->up();
        $migration->up();

        $this->assertTrue(Role::findByName('ca_supervisor', 'web')->hasPermissionTo(PatientChangeHistoryService::PERMISSION));
        $this->assertFalse(Role::findByName('ca', 'web')->hasPermissionTo(PatientChangeHistoryService::PERMISSION));
    }

    public function test_an_unchanged_date_of_birth_is_not_logged_as_changed(): void
    {
        $supervisor = $this->actor('ca_supervisor');
        $patient = $this->edit($this->patient(), $supervisor, ['mobile_phone' => '+60123456783']);
        $this->edit($patient, $supervisor, ['date_of_birth' => '1991-02-03']);

        $events = DB::table('audit_logs')->where('subject_id', $patient->id)->where('subject_type', $patient->getMorphClass())
            ->whereIn('event', ['patient.demographics.updated', 'patient.contact.updated'])->orderBy('id')->get(['event', 'metadata']);
        $this->assertSame(['patient.contact.updated', 'patient.demographics.updated'], $events->pluck('event')->all());
        $this->assertSame(['date_of_birth'], json_decode($events[1]->metadata, true)['changed_fields']);
    }

    public function test_staff_of_another_organisation_get_the_same_refusal_for_a_merged_away_record(): void
    {
        $patient = $this->patient();
        $duplicate = $this->patient();
        $this->mergeInto($patient, $duplicate);
        $foreign = new Organisation;
        $foreign->forceFill(['code' => 'REG4_FOREIGN', 'name' => 'Synthetic foreign organisation'])->save();
        $outsider = User::factory()->create(['organisation_id' => $foreign->id, 'name' => 'Synthetic outsider', 'email' => 'outsider@reg4-history.test']);
        $outsider->assignRole('ca_supervisor');

        // The patient policy answers "not found" for another organisation, the same for a merged-away record, so
        // an outsider cannot tell whether either KP number exists.
        foreach ([$patient, $duplicate] as $target) {
            $this->actingAs($outsider)->getJson('/patients/'.$target->patient_number.'/history')->assertNotFound()
                ->assertJsonMissingPath('rows');
        }
    }

    public function test_a_tampered_cursor_is_refused_cleanly(): void
    {
        $supervisor = $this->actor('ca_supervisor');
        $patient = $this->patient();
        $this->selectBranch($supervisor);
        $encode = fn (array $values): string => rtrim(strtr(base64_encode((string) json_encode($values)), '+/', '-_'), '=');

        foreach ([
            'not-a-cursor',
            $encode(['_pointsToNextItems' => true]),
            $encode(['id' => 5, 'occurred_at' => 'x', '_pointsToNextItems' => true]),
            $encode(['id' => '5 OR 1=1', 'occurred_at' => '2026-11-05 09:00:00', '_pointsToNextItems' => true]),
            $encode(['id' => 5, 'occurred_at' => '2026-11-05 09:00:00', 'event' => 'x', '_pointsToNextItems' => true]),
        ] as $cursor) {
            $this->actingAs($supervisor)->getJson('/patients/'.$patient->patient_number.'/history?cursor='.urlencode($cursor))
                ->assertStatus(422)->assertJsonValidationErrors('cursor');
        }
    }

    public function test_after_an_unmerge_both_records_keep_the_merge_story(): void
    {
        $supervisor = $this->actor('ca_supervisor');
        $kept = $this->patient();
        $duplicate = $this->patient();
        $this->edit($duplicate, $supervisor, ['email' => 'synthetic.unmerge@kpone.test']);
        $this->mergeInto($kept, $duplicate);
        $this->mergeInto($kept, $duplicate, 'unmerge');

        $this->selectBranch($supervisor);
        $history = app(PatientChangeHistoryService::class);
        $keptRows = $history->page($supervisor, $kept->refresh())['rows'];
        $this->assertSame(['Merge undone', 'Records merged'], array_column(array_slice($keptRows, 0, 2), 'label'));
        $this->assertNull(collect($keptRows)->firstWhere('event', 'patient.contact.updated'));

        $duplicateRows = $history->page($supervisor, $duplicate->refresh())['rows'];
        $this->assertSame(['patient.unmerged', 'patient.merged'], array_column(array_slice($duplicateRows, 0, 2), 'event'));
        $this->assertTrue($duplicateRows[0]['isThisRecord']);
        $this->assertNotNull(collect($duplicateRows)->firstWhere('event', 'patient.contact.updated'));
    }

    public function test_creation_lists_its_identifier_types_and_an_nric_retirement_is_labelled(): void
    {
        $supervisor = $this->actor('ca_supervisor');
        $patient = $this->patient();
        app(AuditRecorder::class)->record('patient.created', $patient, ['record_version' => 1, 'identifier_types' => ['nric', 'passport']], $supervisor);
        app(AuditRecorder::class)->record('patient.identifier.retired', $patient, ['identifier_type' => 'nric', 'record_version' => 2], $supervisor);

        $this->selectBranch($supervisor);
        $rows = app(PatientChangeHistoryService::class)->page($supervisor, $patient)['rows'];
        $this->assertSame('NRIC retired', $rows[0]['label']);
        $this->assertSame(['NRIC', 'Passport'], $rows[1]['fields']);
    }

    public function test_an_address_only_edit_writes_no_personal_details_row_and_the_query_count_is_flat(): void
    {
        $supervisor = $this->actor('ca_supervisor');
        $patient = $this->patient();
        $before = DB::table('audit_logs')->where('event', 'patient.demographics.updated')->count();
        foreach (range(1, 6) as $index) {
            $patient = $this->edit($patient, $supervisor, ['city' => 'Synthetic City '.$index, 'address_line_1' => 'No. '.$index, 'state' => 'Selangor', 'postcode' => '43200', 'country_code' => 'MY']);
        }
        $this->assertSame($before, DB::table('audit_logs')->where('event', 'patient.demographics.updated')->count());

        $this->selectBranch($supervisor);
        DB::flushQueryLog();
        DB::enableQueryLog();
        app(PatientChangeHistoryService::class)->page($supervisor, $patient);
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();
        $this->assertLessThanOrEqual(12, $queries);
    }

    public function test_staff_names_follow_the_staff_visibility_rule(): void
    {
        $patient = $this->patient();
        $elsewhere = User::factory()->create(['organisation_id' => $patient->organisation_id, 'name' => 'Synthetic Elsewhere Staff', 'email' => 'elsewhere@reg4-history.test']);
        DB::table('audit_logs')->insert([
            'request_id' => (string) Str::uuid(), 'organisation_id' => $patient->organisation_id, 'actor_user_id' => $elsewhere->id,
            'event' => 'patient.contact.updated', 'subject_type' => $patient->getMorphClass(), 'subject_id' => $patient->id,
            'metadata' => json_encode(['changed_fields' => ['email'], 'record_version' => 2]), 'occurred_at' => now()->addMinute(),
        ]);

        $supervisor = $this->actor('ca_supervisor');
        $this->selectBranch($supervisor);
        $this->assertSame('Staff member', app(PatientChangeHistoryService::class)->page($supervisor, $patient)['rows'][0]['actorName']);

        $director = $this->actor('director');
        $this->selectBranch($director);
        $this->assertSame('Synthetic Elsewhere Staff', app(PatientChangeHistoryService::class)->page($director, $patient)['rows'][0]['actorName']);
    }

    private function mergeInto(Patient $kept, Patient $duplicate, string $kind = 'merge'): void
    {
        $ca = $this->actor('ca');
        $this->selectBranch($ca);
        $request = app(PatientMergeService::class)->propose($ca, $kept->refresh(), [
            'kind' => $kind, 'other_patient_number' => $duplicate->patient_number,
            'reason_code' => $kind === 'merge' ? 'same_person_registered_twice' : 'merged_in_error',
            'expected_branch_id' => $this->branch->id, 'kept_lock_version' => $kept->lock_version,
            'other_lock_version' => $duplicate->refresh()->lock_version, 'idempotency_key' => (string) Str::uuid(),
        ]);
        $supervisor = $this->actor('ca_supervisor');
        $this->selectBranch($supervisor);
        app(PatientMergeService::class)->approve($supervisor, $request, ['expected_branch_id' => $this->branch->id, 'lock_version' => $request->lock_version]);
    }
}
