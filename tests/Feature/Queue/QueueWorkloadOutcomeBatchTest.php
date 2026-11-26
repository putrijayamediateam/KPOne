<?php

namespace Tests\Feature\Queue;

use App\Domain\Clinical\TreatmentRoom\TreatmentRoomService;
use App\Domain\Queue\Services\QueueDirectoryService;
use App\Domain\Queue\Treatment\TreatmentStep;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Clinical\ClinicalTestCase;

class QueueWorkloadOutcomeBatchTest extends ClinicalTestCase
{
    protected function tearDown(): void
    {
        Date::setTestNow();
        parent::tearDown();
    }

    public function test_authorized_outcomes_stay_bounded_and_query_constant_from_one_to_twenty_six_held_patients(): void
    {
        Date::setTestNow(CarbonImmutable::parse('2026-11-26 09:00:00', $this->branch->timezone)->utc());
        $doctor = $this->doctor();
        $ca = $this->actor('ca');
        $ma = $this->actor('medical_assistant');
        $supervisor = $this->actor('ca_supervisor');
        $service = app(TreatmentRoomService::class);
        $directory = app(QueueDirectoryService::class);
        $singleQueries = [];
        for ($i = 1; $i <= 26; $i++) {
            // Respect the real three-held-patient cap: own scope has three;
            // remaining branch-visible patients belong to distinct doctors.
            $assignedDoctor = $i <= 3 ? $doctor : $this->doctor();
            [, , $visit, $queue] = $this->servingFixture($assignedDoctor, $ca);
            $encounter = $this->startEncounter($assignedDoctor, $visit, $queue);
            $this->holdEncounter($assignedDoctor, $visit, $queue, $encounter);
            $this->selectBranch($ma);
            $versions = fn (): array => ['expected_branch_id' => $this->branch->id, 'visit_lock_version' => $visit->refresh()->lock_version, 'queue_lock_version' => $queue->refresh()->lock_version];
            $started = $service->start($ma, $visit, [...$versions(), 'procedure_code' => 'urine_sample', 'idempotency_key' => (string) Str::uuid()]);
            $step = TreatmentStep::findOrFail($started['id']);
            $service->recordOutcome($ma, $visit, [...$versions(), 'treatment_step_id' => $step->id, 'step_lock_version' => $step->lock_version,
                'procedure_code' => 'urine_sample', 'outcome_code' => 'not_successful', 'reason_code' => 'patient_declined', 'idempotency_key' => (string) Str::uuid()]);
            foreach (['own' => $doctor, 'branch' => $supervisor] as $scope => $reader) {
                $boundary = $scope === 'own' ? 3 : 26;
                if (! in_array($i, [1, $boundary], true)) {
                    continue;
                }
                $this->selectBranch($reader);
                $directory->workload($reader, $this->branch->id, 'treatment');
                DB::enableQueryLog();
                DB::flushQueryLog();
                try {
                    $page = $directory->workload($reader, $this->branch->id, 'treatment');
                    $queries = count(DB::getQueryLog());
                } finally {
                    DB::disableQueryLog();
                }
                if ($i === 1) {
                    $singleQueries[$scope] = $queries;
                } else {
                    $this->assertSame($singleQueries[$scope], $queries, $scope.' query count is independent of returned rows');
                }
                $this->assertCount(min($i, 25), $page['data']);
                $this->assertSame($i, $page['total']);
                $this->assertSame($i, $page['counts']['held']);
                $this->assertSame(0, $page['counts']['seeing']);
                foreach ($page['data'] as $row) {
                    $this->assertTrue($row['isHeld']);
                    $this->assertSame('Not successful', $row['treatmentProgress']['outcome']['outcomeLabel']);
                }
            }
        }
        $this->selectBranch($supervisor);
        $last = $directory->workload($supervisor, $this->branch->id, 'treatment', 2);
        $this->assertCount(1, $last['data']);
        $this->assertSame('Not successful', $last['data'][0]['treatmentProgress']['outcome']['outcomeLabel']);
        $this->selectBranch($director = $this->actor('director'));
        $branch = $directory->workload($director, $this->branch->id, 'treatment');
        $this->assertSame(26, $branch['total']);
        foreach ($branch['data'] as $row) {
            $this->assertArrayNotHasKey('outcome', $row['treatmentProgress']);
        }
    }
}
