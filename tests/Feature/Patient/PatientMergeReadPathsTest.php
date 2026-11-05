<?php

namespace Tests\Feature\Patient;

use App\Domain\Patient\Models\Patient;
use App\Domain\Patient\Models\PatientMergeRequest;
use App\Domain\Patient\Services\PatientAdministrationService;
use App\Domain\Patient\Services\PatientDirectoryService;
use App\Domain\Patient\Services\PatientIdentityService;
use App\Domain\Patient\Services\PatientMergeLinks;
use App\Domain\Patient\Services\PatientMergeService;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Visit\VisitTestCase;

/** REG-3: after a merge, readers land on the kept record and nothing is re-pointed. */
class PatientMergeReadPathsTest extends VisitTestCase
{
    /** @return array{0: Patient, 1: Patient, 2: User} */
    private function merged(array $duplicateOverrides = []): array
    {
        $ca = $this->actor('ca');
        $supervisor = $this->actor('ca_supervisor');
        $kept = $this->patient();
        $duplicate = $this->patient(null, $duplicateOverrides);
        $this->selectBranch($ca);
        $request = app(PatientMergeService::class)->propose($ca, $kept, [
            'kind' => 'merge', 'other_patient_number' => $duplicate->patient_number, 'reason_code' => 'same_person_registered_twice',
            'expected_branch_id' => $this->branch->id, 'kept_lock_version' => $kept->lock_version,
            'other_lock_version' => $duplicate->lock_version, 'idempotency_key' => (string) Str::uuid(),
        ]);
        $this->selectBranch($supervisor);
        app(PatientMergeService::class)->approve($supervisor, $request, ['expected_branch_id' => $this->branch->id, 'lock_version' => $request->lock_version]);
        $this->selectBranch($ca);

        return [$kept->refresh(), $duplicate->refresh(), $ca];
    }

    public function test_search_hides_the_merged_away_record_and_old_details_find_the_kept_one(): void
    {
        [$kept, $duplicate, $ca] = $this->merged(['full_name' => 'Synthetic Merge Searchname']);
        $directory = app(PatientDirectoryService::class);
        $numbers = fn (array $criteria): array => collect($directory->search($ca, $criteria)['data'])->pluck('patientNumber')->all();

        $this->assertSame([$kept->patient_number], $numbers(['search_type' => 'patient_number', 'query' => $duplicate->patient_number]));
        $this->assertSame([$kept->patient_number], $numbers(['search_type' => 'name', 'query' => 'Synthetic Merge Searchname']));
        $passport = $duplicate->identifiers()->sole()->normalized_value;
        $this->assertSame([$kept->patient_number], $numbers(['search_type' => 'passport', 'query' => $passport, 'issuing_country_code' => 'MY']));
        $this->assertNotContains($duplicate->patient_number, $numbers(['search_type' => 'name', 'query' => 'Synthetic']));
        $this->assertSame([], $directory->summaries($ca, [$duplicate->id]));
    }

    public function test_duplicate_check_offers_the_kept_record(): void
    {
        [$kept, $duplicate] = $this->merged(['full_name' => 'Synthetic Duplicate Check', 'date_of_birth' => '1985-05-05']);
        $identity = app(PatientIdentityService::class);
        $found = $identity->possibleDuplicates($this->organisation->id, $identity->normalizePatient(['full_name' => 'Synthetic Duplicate Check', 'date_of_birth' => '1985-05-05']));

        $this->assertSame([$kept->id], $found->pluck('id')->all());
    }

    public function test_the_merged_away_page_opens_the_kept_record_and_edits_are_refused(): void
    {
        [$kept, $duplicate, $ca] = $this->merged();

        $this->actingAs($ca)->get('/patients/'.$duplicate->patient_number)->assertRedirect('/patients/'.$kept->patient_number);
        $this->actingAs($ca)->get('/patients/'.$duplicate->patient_number.'/edit')->assertRedirect('/patients/'.$duplicate->patient_number);

        $this->expectException(ValidationException::class);
        app(PatientAdministrationService::class)->update($duplicate, $this->patientAttributesFor($duplicate), $ca);
    }

    public function test_the_kept_page_lists_the_merged_record(): void
    {
        [$kept, $duplicate, $ca] = $this->merged();

        $this->actingAs($ca)->get('/patients/'.$kept->patient_number)->assertOk()
            ->assertInertia(fn ($page) => $page->component('Patient/Show')
                ->where('merge.mergedFrom.0.patientNumber', $duplicate->patient_number)
                ->where('merge.canPropose', true)
                ->where('merge.canApprove', false));
    }

    public function test_a_visit_cannot_be_registered_on_the_merged_away_record(): void
    {
        [, $duplicate, $ca] = $this->merged();

        try {
            $this->register($ca, $duplicate);
            $this->fail('Registration on a merged-away record must be refused.');
        } catch (ValidationException $exception) {
            $this->assertSame(['This record was merged into another patient. Register the visit on the kept record.'], $exception->errors()['patient_number']);
        }
    }

    public function test_group_ids_include_the_kept_and_merged_records_from_either_side(): void
    {
        [$kept, $duplicate] = $this->merged();

        $this->assertSame([$kept->id, $duplicate->id], PatientMergeLinks::groupIds($kept->id));
        $this->assertSame([$kept->id, $duplicate->id], PatientMergeLinks::groupIds($duplicate->id));
        $this->assertSame($kept->id, PatientMergeLinks::kept($duplicate)->id);
    }

    public function test_compare_route_shows_masked_details_and_blockers_to_proposers_only(): void
    {
        $ca = $this->actor('ca');
        $kept = $this->patient();
        $duplicate = $this->patient(null, ['mobile_phone' => '+60123456781']);
        $this->register($ca, $duplicate);

        $response = $this->actingAs($ca)->getJson('/patients/'.$kept->patient_number.'/merge-compare?other_patient_number='.$duplicate->patient_number)->assertOk();
        $this->assertSame($duplicate->patient_number, $response->json('other.patientNumber'));
        $this->assertStringNotContainsString('60123456781', $response->getContent());
        $this->assertContains('One of these records has a visit that is not finished. Complete or cancel it first.', $response->json('blockers'));

        $this->actingAs($this->actor('resident_doctor'))->getJson('/patients/'.$kept->patient_number.'/merge-compare?other_patient_number='.$duplicate->patient_number)->assertForbidden();
    }

    public function test_http_propose_inbox_and_approve(): void
    {
        $ca = $this->actor('ca');
        $supervisor = $this->actor('ca_supervisor');
        $kept = $this->patient();
        $duplicate = $this->patient();
        $this->selectBranch($ca);

        $this->actingAs($ca)->post('/patients/'.$kept->patient_number.'/merge-requests', [
            'kind' => 'merge', 'other_patient_number' => $duplicate->patient_number, 'reason_code' => 'wrong_identifier_typed',
            'expected_branch_id' => $this->branch->id, 'kept_lock_version' => $kept->lock_version,
            'other_lock_version' => $duplicate->lock_version, 'idempotency_key' => (string) Str::uuid(),
        ])->assertRedirect('/patients/'.$kept->patient_number);
        $request = PatientMergeRequest::query()->sole();

        $this->selectBranch($supervisor);
        $this->actingAs($supervisor)->get('/patient-merges')->assertOk()
            ->assertInertia(fn ($page) => $page->component('Patient/MergeRequests')
                ->where('requests.open.0.publicId', $request->public_id)
                ->where('requests.open.0.canDecide', true));
        $this->actingAs($supervisor)->post('/patient-merges/'.$request->public_id.'/approve', ['expected_branch_id' => $this->branch->id, 'lock_version' => $request->lock_version])
            ->assertRedirect('/patient-merges');

        $this->assertSame($kept->id, $duplicate->refresh()->merged_into_patient_id);
        $this->actingAs($ca)->post('/patient-merges/'.$request->public_id.'/approve', ['expected_branch_id' => $this->branch->id, 'lock_version' => 2])->assertForbidden();
        $this->actingAs($this->actor('finance_officer'))->get('/patient-merges')->assertForbidden();
    }

    /** @return array<string, mixed> */
    private function patientAttributesFor(Patient $patient): array
    {
        return ['full_name' => 'Synthetic Edited', 'sex' => 'unknown', 'date_of_birth' => '1990-01-01', 'mobile_phone' => '+60123456789',
            'phone_country' => 'MY', 'lock_version' => $patient->lock_version, 'expected_branch_id' => $this->branch->id];
    }
}
