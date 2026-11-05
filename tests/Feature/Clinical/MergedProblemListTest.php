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
        $this->assertSame([], app(ClinicalSafetyDirectoryService::class)->problems($doctor, $encounter->refresh())['merged']);
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
