<?php

namespace Tests\Feature\Clinical;

use App\Domain\Clinical\Services\ClinicalSafetyDirectoryService;
use App\Domain\Clinical\Services\PatientProblemService;
use App\Domain\Patient\Models\Patient;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\MergeFixture;

/** REG-5: the doctor sees problems recorded on merged records, read only and labelled; they cannot be changed here. */
class MergedProblemListTest extends ClinicalTestCase
{
    public function test_merged_records_problems_are_listed_read_only_with_their_kp_number(): void
    {
        [$doctor, $ca, $visit, $queue] = $this->servingFixture();
        $encounter = $this->startEncounter($doctor, $visit, $queue);
        $duplicate = $this->patient();
        $active = $this->problem($duplicate, 'Synthetic merged asthma', 'active', $doctor);
        $this->problem($duplicate, 'Synthetic merged fracture', 'resolved', $doctor);
        $this->problem($duplicate, 'Synthetic merged error', 'entered_in_error', $doctor);
        MergeFixture::merge($visit->patient, $duplicate, $ca, $this->actor('ca_supervisor'));

        $this->selectBranch($doctor, $visit->branch);
        $problems = app(ClinicalSafetyDirectoryService::class)->problems($doctor, $encounter->refresh());

        $this->assertSame([], $problems['active']->all());
        $this->assertSame(['Synthetic merged asthma', 'Synthetic merged fracture'], array_column($problems['merged'], 'condition'));
        $this->assertSame([$duplicate->patient_number, $duplicate->patient_number], array_column($problems['merged'], 'fromPatientNumber'));
        $this->assertSame(['key', 'condition', 'status', 'onsetDate', 'resolvedDate', 'fromPatientNumber'], array_keys($problems['merged'][0]));

        try {
            app(PatientProblemService::class)->resolve($doctor, $visit, $active, ['expected_branch_id' => $visit->branch_id, 'lock_version' => 1, 'resolved_date' => now()->toDateString()]);
            $this->fail('A merged record\'s problem must not be changeable from the kept record\'s consultation.');
        } catch (HttpException $exception) {
            $this->assertSame(404, $exception->getStatusCode());
        }
        $this->assertSame('active', DB::table('patient_problem_records')->where('public_id', $active)->value('status'));
    }

    public function test_without_a_merge_the_list_has_no_merged_rows(): void
    {
        [$doctor, , $visit, $queue] = $this->servingFixture();
        $encounter = $this->startEncounter($doctor, $visit, $queue);
        $this->problem($this->patient(), 'Synthetic unrelated condition', 'active', $doctor);

        $this->selectBranch($doctor, $visit->branch);
        $problems = app(ClinicalSafetyDirectoryService::class)->problems($doctor, $encounter->refresh());
        $this->assertSame([], $problems['merged']);
        $this->assertFalse($problems['mergedTruncated']);
    }

    public function test_active_merged_problems_come_first_and_the_list_says_when_it_is_cut(): void
    {
        [$doctor, $ca, $visit, $queue] = $this->servingFixture();
        $encounter = $this->startEncounter($doctor, $visit, $queue);
        $duplicate = $this->patient();
        foreach (range(1, 50) as $index) {
            $this->problem($duplicate, 'Synthetic resolved '.$index, 'resolved', $doctor);
        }
        $this->problem($duplicate, 'Synthetic active one', 'active', $doctor);
        MergeFixture::merge($visit->patient, $duplicate, $ca, $this->actor('ca_supervisor'));

        $this->selectBranch($doctor, $visit->branch);
        $problems = app(ClinicalSafetyDirectoryService::class)->problems($doctor, $encounter->refresh());

        $this->assertCount(50, $problems['merged']);
        $this->assertTrue($problems['mergedTruncated']);
        $this->assertSame('Synthetic active one', $problems['merged'][0]['condition']);
        $this->assertSame('active', $problems['merged'][0]['status']);
    }

    public function test_the_kept_patients_problem_routes_refuse_a_merged_records_problem_and_leave_its_own_as_today(): void
    {
        [$doctor, $ca, $visit, $queue] = $this->servingFixture();
        $encounter = $this->startEncounter($doctor, $visit, $queue);
        $own = $this->problem($visit->patient, 'Synthetic own condition', 'active', $doctor);
        $duplicate = $this->patient();
        $merged = $this->problem($duplicate, 'Synthetic merged condition', 'active', $doctor);
        MergeFixture::merge($visit->patient, $duplicate, $ca, $this->actor('ca_supervisor'));

        $this->selectBranch($doctor, $visit->branch);
        $base = '/visits/'.$visit->visit_number.'/encounter/problems/'.$merged;
        $this->actingAs($doctor)->patch($base, ['expected_branch_id' => $visit->branch_id, 'condition_text' => 'Synthetic changed', 'lock_version' => 1])->assertNotFound();
        $this->actingAs($doctor)->patch($base.'/resolve', ['expected_branch_id' => $visit->branch_id, 'lock_version' => 1, 'resolved_date' => now()->toDateString()])->assertNotFound();
        $this->actingAs($doctor)->patch($base.'/entered-in-error', ['expected_branch_id' => $visit->branch_id, 'lock_version' => 1])->assertNotFound();
        $this->assertSame(['active', 'Synthetic merged condition', 1], array_values((array) DB::table('patient_problem_records')->where('public_id', $merged)->first(['status', 'condition_text', 'lock_version'])));

        $problems = app(ClinicalSafetyDirectoryService::class)->problems($doctor, $encounter->refresh());
        $this->assertSame([$own], $problems['active']->pluck('publicId')->all());
        $this->assertTrue($problems['canUpdate']);
        $this->assertSame(['Synthetic merged condition'], array_column($problems['merged'], 'condition'));
    }

    private function problem(Patient $patient, string $condition, string $status, User $by): string
    {
        $publicId = (string) Str::uuid();
        DB::table('patient_problem_records')->insert([
            'public_id' => $publicId, 'organisation_id' => $patient->organisation_id, 'patient_id' => $patient->id,
            'condition_text' => $condition, 'status' => $status, 'onset_date' => '2020-01-01',
            'resolved_date' => $status === 'resolved' ? '2021-01-01' : null, 'resolved_at' => $status === 'resolved' ? now() : null,
            'resolved_by_user_id' => $status === 'resolved' ? $by->id : null,
            'entered_in_error_at' => $status === 'entered_in_error' ? now() : null, 'entered_in_error_by_user_id' => $status === 'entered_in_error' ? $by->id : null,
            'recorded_by_user_id' => $by->id, 'updated_by_user_id' => $by->id, 'lock_version' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $publicId;
    }
}
