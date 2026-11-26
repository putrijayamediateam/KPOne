<?php

namespace Tests\Feature\Queue;

use App\Domain\Organisation\Models\Branch;
use App\Domain\Queue\Emergency\EmergencyLaneProjection;
use App\Domain\Queue\Readiness\CallHoldService;
use App\Domain\Queue\Services\QueueDirectoryService;
use App\Domain\Visit\Services\VisitDirectoryService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Clinical\Triage\TriageTestCase;

class EmergencyLaneProjectionTest extends TriageTestCase
{
    protected function tearDown(): void
    {
        Date::setTestNow();
        parent::tearDown();
    }

    public function test_lane_reads_all_red_waiting_across_dates_without_ordinary_page_or_filter_dependency(): void
    {
        Date::setTestNow(CarbonImmutable::parse('2026-08-24 09:00:00', $this->branch->timezone)->utc());
        $this->approvedGuideline();
        $ca = $this->actor('ca');
        $doctor = $this->doctor();
        $carry = $this->consultationVisit($ca, $doctor);
        $this->send($ca, $carry);
        app(CallHoldService::class)->change($ca, $carry, ['expected_branch_id' => $this->branch->id, 'queue_lock_version' => $carry->queueEntry->lock_version, 'reason_code' => 'awaiting_panel_confirmation', 'idempotency_key' => (string) Str::uuid()], false);
        $this->triage($ca, $carry->refresh(), ['chosen_zone' => 'red']);
        Date::setTestNow(CarbonImmutable::parse('2026-08-25 09:00:00', $this->branch->timezone)->utc());
        $today = $this->consultationVisit($ca, $doctor);
        $this->send($ca, $today);
        $this->triage($ca, $today->refresh(), ['chosen_zone' => 'red']);
        $normal = $this->consultationVisit($ca, $doctor);
        $this->send($ca, $normal);
        $this->selectBranch($ca);
        $queue = app(QueueDirectoryService::class)->snapshot($ca, ['page' => 8, 'carry_page' => 9, 'query' => 'no such synthetic patient']);
        $this->assertSame([$carry->visit_number, $today->visit_number], array_column($queue['emergency']['data'], 'visitNumber'));
        $this->assertSame(2, $queue['emergency']['total']);
        $this->assertFalse($queue['emergency']['data'][0]['boardRow']['canCall'], 'CA identity access must not grant doctor Call In authority.');
        $this->assertSame($carry->refresh()->lock_version, $queue['emergency']['data'][0]['boardRow']['visitLockVersion']);
        $this->assertSame($carry->queueEntry->refresh()->lock_version, $queue['emergency']['data'][0]['boardRow']['queueLockVersion']);
        $this->assertGreaterThanOrEqual(1440, $queue['emergency']['data'][0]['waitingMinutes']);
        $this->assertNotNull($queue['emergency']['data'][0]['boardRow']['callHold']);
        $this->assertSame([], $queue['waiting']['data']->all());
        $unfiltered = app(QueueDirectoryService::class)->poll($ca);
        $this->assertSame([$normal->visit_number], $unfiltered['waiting']['data']->pluck('visitNumber')->all());
        $this->assertSame(1, $unfiltered['waiting']['total']);
        $this->assertSame(0, $unfiltered['carryOver']['total']);
        $this->assertSame(1, $unfiltered['tabCounts']['waiting']);
        $registration = app(VisitDirectoryService::class)->search($ca, ['page' => 8, 'patient_query' => 'no such synthetic patient']);
        $this->assertSame([$carry->visit_number, $today->visit_number], array_column($registration['emergency']['data'], 'visitNumber'));
        $registration = app(VisitDirectoryService::class)->search($ca, []);
        $this->assertSame([$normal->visit_number], $registration['data']->pluck('visitNumber')->all());
        $this->assertSame(1, $registration['total']);
        $supervisor = $this->actor('ca_supervisor');
        $this->selectBranch($supervisor);
        $registration = app(VisitDirectoryService::class)->search($supervisor, []);
        $this->assertTrue($registration['emergency']['data'][0]['boardRow']['can']['call']);
        $this->assertSame($carry->queueEntry->refresh()->lock_version, $registration['emergency']['data'][0]['boardRow']['queueLockVersion']);
    }

    public function test_emergency_permission_does_not_expand_ordinary_board_identity_scope(): void
    {
        $this->approvedGuideline();
        $ca = $this->actor('ca');
        $doctor = $this->doctor();
        $other = $this->doctor();
        $visit = $this->consultationVisit($ca, $doctor);
        $this->send($ca, $visit);
        $this->triage($ca, $visit->refresh(), ['chosen_zone' => 'red', 'pulse_bpm' => 130]);
        $lane = app(EmergencyLaneProjection::class);
        $this->selectBranch($other);
        $this->assertTrue($other->can('queue.emergency.respond.branch'));
        $this->assertSame(['data' => [], 'total' => 0, 'currentPage' => 1, 'lastPage' => 1, 'perPage' => 25, 'hasMore' => false], $lane->snapshot($other, $this->branch));
        $this->selectBranch($doctor);
        $row = $lane->snapshot($doctor, $this->branch)['data'][0];
        $this->assertSame($visit->patient->full_name, $row['patientName']);
        $ownQueue = app(QueueDirectoryService::class)->snapshot($doctor);
        $this->assertTrue($ownQueue['emergency']['data'][0]['boardRow']['canCall']);
        $this->assertSame($visit->refresh()->lock_version, $ownQueue['emergency']['data'][0]['boardRow']['visitLockVersion']);
        $this->assertSame(['visitNumber', 'queueNumber', 'patientName', 'assignedDoctorName', 'waitingMinutes', 'operationalDate', 'episodeId', 'alertStartedAt', 'alertOrigin', 'takenBy', 'takenAt'], array_keys($row));
        $this->assertStringNotContainsString('pulse', json_encode($row));
        $director = $this->actor('director');
        $this->selectBranch($director);
        $this->assertSame(1, $lane->snapshot($director, $this->branch)['total']);
        $finance = $this->actor('finance_officer');
        $this->selectBranch($finance);
        $this->assertSame(['data' => [], 'total' => 0, 'currentPage' => 1, 'lastPage' => 1, 'perPage' => 25, 'hasMore' => false], $lane->snapshot($finance, $this->branch));
        $this->assertSame(['data' => [], 'total' => 0, 'currentPage' => 1, 'lastPage' => 1, 'perPage' => 25, 'hasMore' => false], $lane->snapshot($doctor, Branch::query()->where('organisation_id', $this->organisation->id)->where('id', '!=', $this->branch->id)->firstOrFail()));
    }

    public function test_more_than_one_normal_page_of_red_patients_remains_visible_and_lowered_row_returns_to_normal(): void
    {
        $this->approvedGuideline();
        $ca = $this->actor('ca');
        $doctor = $this->doctor();
        $visits = [];
        $oneRowQueries = null;
        for ($i = 0; $i < 26; $i++) {
            $visit = $this->consultationVisit($ca, $doctor);
            $this->send($ca, $visit);
            $this->triage($ca, $visit->refresh(), ['chosen_zone' => 'red']);
            $visits[] = $visit;
            if ($i === 0) {
                $this->selectBranch($ca);
                app(EmergencyLaneProjection::class)->snapshot($ca, $this->branch);
                DB::enableQueryLog();
                DB::flushQueryLog();
                app(EmergencyLaneProjection::class)->snapshot($ca, $this->branch);
                $oneRowQueries = count(DB::getQueryLog());
                DB::disableQueryLog();
            }
        }
        DB::enableQueryLog();
        DB::flushQueryLog();
        app(EmergencyLaneProjection::class)->snapshot($ca, $this->branch);
        $manyRowQueries = count(DB::getQueryLog());
        DB::disableQueryLog();
        $this->assertSame($oneRowQueries, $manyRowQueries, 'Adding emergency rows must not add per-patient queries.');
        $this->selectBranch($ca);
        $snapshot = app(QueueDirectoryService::class)->poll($ca, ['page' => 2]);
        $this->assertCount(25, $snapshot['emergency']['data']);
        $this->assertSame(2, $snapshot['emergency']['lastPage']);
        $this->assertTrue($snapshot['emergency']['hasMore']);
        $second = app(QueueDirectoryService::class)->poll($ca, ['emergency_page' => 2]);
        $this->assertSame([$visits[25]->visit_number], array_column($second['emergency']['data'], 'visitNumber'));
        $this->assertFalse($second['emergency']['hasMore']);
        $this->actingAs($ca)->postJson(route('queue.search'), ['emergency_page' => 2])->assertOk()->assertJsonCount(1, 'emergency.data')->assertJsonPath('emergency.currentPage', 2);
        $this->actingAs($ca)->postJson(route('registration.search'), ['emergency_page' => 2])->assertOk()->assertJsonCount(1, 'emergency.data');
        $this->assertSame(0, $second['waiting']['total']);
        $registration = app(VisitDirectoryService::class)->search($ca, ['emergency_page' => 2]);
        $this->assertCount(1, $registration['emergency']['data']);
        $this->assertSame(0, $registration['total']);
        $this->assertSame(26, $snapshot['emergency']['total']);
        $this->assertSame(0, $snapshot['waiting']['total']);
        $this->triage($ca, $visits[0]->refresh(), ['chosen_zone' => 'green']);
        $snapshot = app(QueueDirectoryService::class)->poll($ca, ['emergency_page' => 2]);
        $this->assertSame(1, $snapshot['emergency']['currentPage']);
        $this->assertCount(25, $snapshot['emergency']['data']);
        $this->assertSame([$visits[0]->visit_number], $snapshot['waiting']['data']->pluck('visitNumber')->all());
    }
}
