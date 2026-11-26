<?php

namespace Tests\Feature\Clinical;

use App\Domain\Clinical\TreatmentRoom\TreatmentRoomDirectory;
use App\Domain\Clinical\TreatmentRoom\TreatmentRoomProjection;
use App\Domain\Clinical\TreatmentRoom\TreatmentRoomService;
use App\Domain\Queue\Treatment\TreatmentStep;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class TreatmentRoomPaginationTest extends ClinicalTestCase
{
    public function test_current_and_history_pages_use_full_evidence_and_void_never_revives_a_predecessor(): void
    {
        [$ma, $visit, $queue] = $this->heldPatient();
        $service = app(TreatmentRoomService::class);
        $original = $latest = $this->record($ma, $visit, $queue);
        for ($i = 0; $i < 25; $i++) {
            $latest = $service->correctOutcome($ma, $visit, [...$this->versions($visit, $queue), 'target_record_id' => $latest->id, 'correction_code' => 'wrong_outcome', 'procedure_code' => 'urine_sample', 'outcome_code' => $i % 2 === 0 ? 'not_successful' : 'done', 'reason_code' => $i % 2 === 0 ? 'patient_declined' : null, 'idempotency_key' => (string) Str::uuid()]);
        }
        $projection = app(TreatmentRoomProjection::class);
        $first = $projection->forVisit($ma, $visit);
        $second = $projection->forVisit($ma, $visit, false, 1, 2);
        $this->assertCount(25, $first['history']);
        $this->assertCount(1, $second['history']);
        $this->assertSame(26, $second['historyPagination']['total']);
        $this->assertSame($original->id, $second['history'][0]['id']);
        $this->assertSame('superseded', $second['history'][0]['status']);
        $this->assertSame('wrong_outcome', $second['history'][0]['successorCorrectionCode']);
        $this->assertSame([$latest->id], array_column($second['current'], 'id'));
        $service->voidWrongPatient($ma, $visit, [...$this->versions($visit, $queue), 'target_record_id' => $latest->id, 'reason_code' => 'wrong_patient', 'idempotency_key' => (string) Str::uuid()]);
        $after = $projection->forVisit($ma, $visit, false, 999, 999);
        $this->assertSame([], $after['current']);
        $this->assertSame(0, $after['currentPagination']['total']);
        $this->assertSame(2, $after['historyPagination']['currentPage']);
        $this->assertSame('superseded', $after['history'][0]['status']);
    }

    public function test_nested_steps_and_current_outcomes_are_bounded_and_reachable_without_changing_versions(): void
    {
        [$ma, $visit, $queue] = $this->heldPatient();
        $ids = [];
        for ($i = 0; $i < 26; $i++) {
            $ids[] = $this->record($ma, $visit, $queue)->id;
        }
        $before = $this->versions($visit, $queue);
        $directory = app(TreatmentRoomDirectory::class);
        $list = $directory->snapshot($ma);
        $first = $directory->context($ma, $visit);
        $second = $directory->context($ma, $visit, 999);
        $this->assertCount(25, $list['rows'][0]['steps']);
        $this->assertSame(26, $list['rows'][0]['stepsPagination']['total']);
        $this->assertCount(25, $first['steps']);
        $this->assertCount(1, $second['steps']);
        $this->assertSame(2, $second['stepsPagination']['currentPage']);
        $this->assertTrue($second['steps'][0]['hasOutcome']);
        $this->assertFalse($second['steps'][0]['canRecordOutcome']);
        $this->assertSame([], array_intersect(array_column($first['steps'], 'id'), array_column($second['steps'], 'id')));
        $projection = app(TreatmentRoomProjection::class);
        $records = $projection->forVisit($ma, $visit, false, 2);
        $this->assertSame([$ids[0]], array_column($records['current'], 'id'));
        $this->assertSame(26, $records['currentPagination']['total']);
        $this->assertSame($before, $this->versions($visit, $queue));
        app(TreatmentRoomService::class)->start($ma, $visit, [...$this->versions($visit, $queue), 'procedure_code' => 'urine_sample', 'idempotency_key' => (string) Str::uuid()]);
        $offPage = $directory->context($ma, $visit, 2);
        $this->assertNotContains('started', array_column($offPage['steps'], 'state'));
        $this->assertTrue($offPage['hasStartedStep']);
        $this->assertTrue($directory->snapshot($ma)['rows'][0]['hasStartedStep']);
    }

    public function test_directory_is_independently_paged_and_query_count_does_not_grow_with_patients(): void
    {
        [$ma, $visit, $queue, $doctor, $ca] = $this->heldPatient();
        $this->record($ma, $visit, $queue);
        $directory = app(TreatmentRoomDirectory::class);
        $directory->snapshot($ma); // Warm permission metadata before measuring both cardinalities.
        DB::enableQueryLog();
        DB::flushQueryLog();
        $one = $directory->snapshot($ma);
        $oneCount = count(DB::getQueryLog());
        DB::disableQueryLog();
        for ($i = 0; $i < 25; $i++) {
            $doctor = $this->doctor();
            [, , $next, $entry] = $this->servingFixture($doctor, $ca);
            $encounter = $this->startEncounter($doctor, $next, $entry);
            $this->holdEncounter($doctor, $next, $entry, $encounter);
            $this->selectBranch($ma);
            $this->record($ma, $next, $entry);
        }
        DB::enableQueryLog();
        DB::flushQueryLog();
        $many = $directory->snapshot($ma);
        $manyCount = count(DB::getQueryLog());
        DB::disableQueryLog();
        $last = $directory->snapshot($ma, 999);
        $this->assertCount(1, $one['rows']);
        $this->assertCount(25, $many['rows']);
        $this->assertCount(1, $last['rows']);
        $this->assertSame(26, $many['pagination']['total']);
        $this->assertSame(2, $last['pagination']['currentPage']);
        $this->assertSame([], array_intersect(array_column($many['rows'], 'visitNumber'), array_column($last['rows'], 'visitNumber')));
        $this->assertSame($oneCount, $manyCount);
    }

    private function heldPatient(): array
    {
        [$doctor, $ca, $visit, $queue] = $this->servingFixture();
        $encounter = $this->startEncounter($doctor, $visit, $queue);
        $this->holdEncounter($doctor, $visit, $queue, $encounter);
        $ma = $this->actor('medical_assistant');
        $this->selectBranch($ma);

        return [$ma, $visit, $queue, $doctor, $ca];
    }

    private function record($ma, $visit, $queue)
    {
        $service = app(TreatmentRoomService::class);
        $start = $service->start($ma, $visit, [...$this->versions($visit, $queue), 'procedure_code' => 'urine_sample', 'idempotency_key' => (string) Str::uuid()]);
        $step = TreatmentStep::findOrFail($start['id']);

        return $service->recordOutcome($ma, $visit, [...$this->versions($visit, $queue), 'treatment_step_id' => $step->id, 'step_lock_version' => $step->lock_version, 'procedure_code' => 'urine_sample', 'outcome_code' => 'done', 'reason_code' => null, 'idempotency_key' => (string) Str::uuid()]);
    }

    private function versions($visit, $queue): array
    {
        return ['expected_branch_id' => $visit->branch_id, 'visit_lock_version' => $visit->refresh()->lock_version, 'queue_lock_version' => $queue->refresh()->lock_version];
    }
}
