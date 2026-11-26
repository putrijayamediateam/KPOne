<?php

namespace Tests\Feature\Queue;

use App\Domain\Clinical\Dispensary\Services\DispensaryService;
use App\Domain\Clinical\Dispensary\Services\DoctorDispensaryAttentionService;
use App\Domain\Clinical\Services\CompleteConsultationService;
use App\Domain\Clinical\TreatmentRoom\TreatmentRoomService;
use App\Domain\Queue\Services\QueueDirectoryService;
use App\Domain\Queue\Treatment\TreatmentStep;
use App\Domain\Queue\Treatment\TreatmentStepService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Clinical\ClinicalTestCase;
use Tests\Support\DoctorCheckFixtures;

class QueueWorkloadTest extends ClinicalTestCase
{
    use DoctorCheckFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        Date::setTestNow(CarbonImmutable::parse('2026-11-26 09:00:00', $this->branch->timezone)->utc());
    }

    public function test_old_held_patient_is_found_outside_latest_serving_page_and_other_doctor_is_excluded(): void
    {
        [$doctor, $ca, $visit, $queue] = $this->servingFixture();
        $encounter = $this->startEncounter($doctor, $visit, $queue);
        $this->holdEncounter($doctor, $visit, $queue, $encounter);
        Date::setTestNow(now()->addMinutes(5));
        for ($i = 0; $i < 26; $i++) {
            $entry = $this->send($ca, $this->consultationVisit($ca, $doctor));
            $entry->forceFill(['status' => 'serving', 'called_at' => now(), 'called_by_user_id' => $doctor->id])->save();
        }
        $this->selectBranch($doctor);
        $directory = app(QueueDirectoryService::class);
        $this->assertNotContains($visit->visit_number, collect($directory->poll($doctor, ['status' => 'serving'])['serving'])->pluck('visitNumber'));
        $result = $directory->workload($doctor, $this->branch->id, 'held');
        $this->assertSame(1, $result['total']);
        $this->assertSame($visit->visit_number, $result['data'][0]['visitNumber']);
        $this->assertTrue($result['data'][0]['isHeld']);
        $this->assertSame($queue->refresh()->lock_version, $result['data'][0]['queueLockVersion']);
        $this->assertNull($encounter->holds()->sole()->resumed_at, 'Reading never resumes a consultation.');
        $other = $this->doctor();
        $this->selectBranch($other);
        $this->assertSame(0, $directory->workload($other, $this->branch->id, 'held')['total']);
        $this->selectBranch($ca);
        $this->assertSame(1, $directory->workload($ca, $this->branch->id, 'held')['total']);
    }

    public function test_treatment_membership_is_unacknowledged_done_and_pages_are_bounded_and_clamped(): void
    {
        $doctor = $this->doctor();
        $ca = $this->actor('ca');
        $visits = [];
        $countQueries = function () use ($ca): int {
            $this->selectBranch($ca);
            app(QueueDirectoryService::class)->workload($ca, $this->branch->id, 'treatment');
            DB::enableQueryLog();
            DB::flushQueryLog();
            app(QueueDirectoryService::class)->workload($ca, $this->branch->id, 'treatment');
            $count = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $count;
        };
        $singleQueries = 0;
        for ($i = 0; $i < 26; $i++) {
            $visit = $this->consultationVisit($ca, $doctor);
            $entry = $this->send($ca, $visit);
            $entry->forceFill(['status' => 'serving', 'called_at' => now(), 'called_by_user_id' => $doctor->id])->save();
            $step = app(TreatmentStepService::class)->start($ca, $visit, ['expected_branch_id' => $this->branch->id, 'step_code' => 'nebuliser', 'idempotency_key' => (string) Str::uuid()]);
            app(TreatmentStepService::class)->complete($ca, $visit, ['expected_branch_id' => $this->branch->id, 'treatment_step_id' => $step['id'], 'lock_version' => 1, 'idempotency_key' => (string) Str::uuid()]);
            $visits[] = $visit;
            if ($i === 0) {
                $singleQueries = $countQueries();
            }
        }
        $this->assertSame($singleQueries, $countQueries(), 'One and twenty-five hydrated rows use a constant query count.');
        $this->selectBranch($doctor);
        $directory = app(QueueDirectoryService::class);
        $first = $directory->workload($doctor, $this->branch->id, 'treatment');
        $last = $directory->workload($doctor, $this->branch->id, 'treatment', 2);
        $this->assertSame(26, $first['total']);
        $this->assertCount(25, $first['data']);
        $this->assertCount(1, $last['data']);
        $this->assertSame($visits[25]->visit_number, $last['data'][0]['visitNumber']);
        $this->assertSame('done', $last['data'][0]['treatmentProgress']['state']);
        $this->assertSame(26, TreatmentStep::query()->whereNull('closed_at')->count(), 'Polling acknowledges nothing.');
        app(TreatmentStepService::class)->acknowledgeOnOpen($doctor, $visits[25]);
        $clamped = $directory->workload($doctor, $this->branch->id, 'treatment', 2);
        $this->assertSame(25, $clamped['total']);
        $this->assertSame(1, $clamped['currentPage']);
        $this->assertCount(25, $clamped['data']);
        $this->selectBranch($other = $this->doctor());
        $this->assertSame(0, $directory->workload($other, $this->branch->id, 'treatment')['total']);
    }

    public function test_medicine_check_matches_existing_count_and_row_policy_without_medicine_payload(): void
    {
        $f = $this->prepared();
        $this->switchOn($f['visit']->branch);
        $this->selectBranch($f['ca']);
        app(DispensaryService::class)->sendForDoctorCheck($f['ca'], $f['case']->refresh(), [
            'expected_branch_id' => $this->branch->id, 'case_lock_version' => $f['case']->lock_version, 'retry_key' => (string) Str::uuid(),
        ]);
        $this->selectBranch($f['doctor']);
        $directory = app(QueueDirectoryService::class);
        $result = $directory->workload($f['doctor'], $this->branch->id, 'checks');
        $this->assertSame(1, $result['total']);
        $this->assertSame(app(DoctorDispensaryAttentionService::class)->checksWaitingCount($f['doctor'], $this->branch), $result['total']);
        $this->assertTrue($result['data'][0]['dispensaryCheckWaiting']);
        $this->assertTrue($result['data'][0]['canOpenEncounter']);
        $this->assertArrayNotHasKey('lines', $result['data'][0]);
        foreach ([$f['ca'], $this->doctor(), $this->actor('director')] as $actor) {
            $this->selectBranch($actor);
            $this->assertSame(0, $directory->workload($actor, $this->branch->id, 'checks')['total']);
        }
    }

    public function test_not_successful_treatment_remains_attention_but_clinical_outcome_requires_its_existing_policy(): void
    {
        [$doctor, , $visit, $queue] = $this->servingFixture();
        $encounter = $this->startEncounter($doctor, $visit, $queue);
        $this->holdEncounter($doctor, $visit, $queue, $encounter);
        $ma = $this->actor('medical_assistant');
        $this->selectBranch($ma);
        $versions = fn (): array => ['expected_branch_id' => $this->branch->id, 'visit_lock_version' => $visit->refresh()->lock_version, 'queue_lock_version' => $queue->refresh()->lock_version];
        $service = app(TreatmentRoomService::class);
        $started = $service->start($ma, $visit, [...$versions(), 'procedure_code' => 'urine_sample', 'idempotency_key' => (string) Str::uuid()]);
        $step = TreatmentStep::findOrFail($started['id']);
        $service->recordOutcome($ma, $visit, [...$versions(), 'treatment_step_id' => $step->id, 'step_lock_version' => $step->lock_version,
            'procedure_code' => 'urine_sample', 'outcome_code' => 'not_successful', 'reason_code' => 'patient_declined', 'idempotency_key' => (string) Str::uuid()]);
        $directory = app(QueueDirectoryService::class);
        $this->selectBranch($doctor);
        $own = $directory->workload($doctor, $this->branch->id, 'treatment');
        $this->assertSame(1, $own['total']);
        $this->assertSame('Not successful', $own['data'][0]['treatmentProgress']['outcome']['outcomeLabel']);
        $this->assertTrue($own['data'][0]['isHeld']);
        $this->selectBranch($director = $this->actor('director'));
        $branch = $directory->workload($director, $this->branch->id, 'treatment');
        $this->assertSame(1, $branch['total'], 'Existing step-only board visibility is unchanged.');
        $this->assertSame('done', $branch['data'][0]['treatmentProgress']['state']);
        $this->assertArrayNotHasKey('outcome', $branch['data'][0]['treatmentProgress']);
        $this->assertNull($encounter->holds()->sole()->resumed_at);
    }

    public function test_seeing_now_excludes_active_holds_and_applies_doctor_and_search_filters(): void
    {
        [$doctor, $ca, $held, $queue] = $this->servingFixture();
        $encounter = $this->startEncounter($doctor, $held, $queue);
        $this->holdEncounter($doctor, $held, $queue, $encounter);
        [, , $seeing] = $this->servingFixture($doctor, $ca);
        $this->selectBranch($ca);
        $directory = app(QueueDirectoryService::class);
        $result = $directory->workload($ca, $this->branch->id, 'seeing', 1, ['doctor_id' => $doctor->id]);
        $this->assertSame(1, $result['counts']['seeing']);
        $this->assertSame(1, $result['counts']['held']);
        $this->assertSame([$seeing->visit_number], $result['data']->pluck('visitNumber')->all());
        $this->assertFalse($result['data'][0]['isHeld']);
        $filtered = $directory->workload($ca, $this->branch->id, 'seeing', 1, ['query' => $held->patient->patient_number]);
        $this->assertSame(0, $filtered['total']);
        $this->assertSame(1, $filtered['counts']['held']);
    }

    public function test_done_today_uses_branch_local_checkout_day_with_inclusive_start_exclusive_end(): void
    {
        $start = CarbonImmutable::parse('2026-11-26 00:00:00', $this->branch->timezone);
        Date::setTestNow($start->addMinutes(5)->utc());
        $times = [$start->subSecond(), $start, $start->addDay()->subSecond(), $start->addDay()];
        $expected = [];
        foreach ($times as $index => $time) {
            [$doctor, , $visit, $queue] = $this->servingFixture();
            $encounter = $this->startEncounter($doctor, $visit, $queue);
            app(CompleteConsultationService::class)->complete($doctor, $visit, [
                'expected_branch_id' => $this->branch->id, 'visit_lock_version' => $visit->refresh()->lock_version,
                'queue_lock_version' => $queue->refresh()->lock_version, 'encounter_lock_version' => $encounter->refresh()->lock_version,
                'lock_version' => null, 'service_deliveries' => [],
            ]);
            // Synthetic read-boundary fixture: actual checkout state, with its timestamp placed at each day edge.
            $queue->refresh()->forceFill(['removed_at' => $time->utc()])->save();
            if (in_array($index, [1, 2], true)) {
                $expected[] = $visit->visit_number;
            }
        }
        $this->selectBranch($ca = $this->actor('ca'));
        $result = app(QueueDirectoryService::class)->workload($ca, $this->branch->id, 'completed');
        $this->assertSame(2, $result['total']);
        $this->assertSame($expected, $result['data']->pluck('visitNumber')->all());
        $this->assertSame(2, $result['counts']['completed']);
        $this->assertSame('2026-11-25', $start->utc()->toDateString(), 'MYT midnight belongs to the previous UTC date.');
    }

    public function test_endpoint_checks_branch_section_and_read_authority(): void
    {
        $doctor = $this->doctor();
        $this->selectBranch($doctor);
        $url = route('queue.workload', ['expected_branch_id' => $this->branch->id, 'section' => 'held']);
        $this->getJson($url)->assertOk()->assertJsonPath('total', 0)->assertJsonPath('perPage', 25);
        $this->getJson(route('queue.workload', ['expected_branch_id' => $this->branch->id + 1000, 'section' => 'held']))->assertForbidden();
        $this->getJson(route('queue.workload', ['expected_branch_id' => $this->branch->id, 'section' => 'invented']))->assertUnprocessable();
        $this->selectBranch($this->actor('finance_officer'));
        $this->getJson($url)->assertForbidden();
    }
}
