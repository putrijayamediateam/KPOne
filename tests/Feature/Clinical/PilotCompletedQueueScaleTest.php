<?php

namespace Tests\Feature\Clinical;

use App\Domain\Clinical\Services\CheckoutReopenEligibility;
use App\Domain\Clinical\Services\CompleteConsultationService;
use App\Domain\Queue\Services\QueueDirectoryService;
use Illuminate\Support\Facades\DB;
use Tests\Support\ReportEvidence;

class PilotCompletedQueueScaleTest extends ClinicalTestCase
{
    use ReportEvidence;

    public function test_twenty_five_completed_consultations_keep_reopen_hints_with_bounded_queries(): void
    {
        $doctor = $this->doctor();
        $ca = $this->actor('ca');
        $visits = collect();
        for ($index = 0; $index < 25; $index++) {
            [, , $visit, $queue] = $this->servingFixture($doctor, $ca);
            $encounter = $this->startEncounter($doctor, $visit, $queue);
            app(CompleteConsultationService::class)->complete($doctor, $visit, [
                'expected_branch_id' => $visit->branch_id, 'visit_lock_version' => $visit->lock_version,
                'queue_lock_version' => $queue->lock_version, 'encounter_lock_version' => $encounter->lock_version,
                'lock_version' => null, 'service_deliveries' => [],
            ]);
            $visits->push($visit->refresh());
        }
        $before = $this->sourceEvidence();
        $eligibility = app(CheckoutReopenEligibility::class);
        $hints = $eligibility->forVisits($doctor, $visits);
        foreach ($visits as $visit) {
            $this->assertTrue($eligibility->allows($doctor, $visit));
            $this->assertTrue($hints[$visit->id] ?? false);
        }
        DB::enableQueryLog();
        DB::flushQueryLog();
        $snapshot = app(QueueDirectoryService::class)->snapshot($doctor, ['status' => 'removed']);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertLessThanOrEqual(30, count($queries));
        $this->assertCount(25, $snapshot['removed']);
        foreach ($snapshot['removed'] as $row) {
            $this->assertTrue($row['canOpenEncounter']);
        }
        $this->assertSame($before, $this->sourceEvidence());
    }
}
