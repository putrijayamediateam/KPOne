<?php

namespace Tests\Feature\Agents;

use App\Domain\Agents\Tools\ClinicDaySummaryTool;
use App\Domain\Queue\Readiness\CallHoldService;
use Illuminate\Support\Str;
use Tests\Feature\Queue\QueueTestCase;
use Tests\Support\AsksTheConnector;

/** CN-3: visits_by_state and queue_now reconcile with the clinic day summary and the Registration and Queue boards' data. */
class ConnectorQueueTest extends QueueTestCase
{
    use AsksTheConnector;

    protected function setUp(): void
    {
        parent::setUp();
        $this->connectorSetUp();
    }

    public function test_visits_by_state_counts_unfinished_and_reconciles_with_registered(): void
    {
        $ca = $this->actor('ca');
        $doctor = $this->doctor();
        $first = $this->consultationVisit($ca, $doctor);
        $this->consultationVisit($ca, $doctor);
        $this->send($ca, $first);
        $director = $this->actor('director');

        $entry = $this->connectorAnswer($director, 'visits_by_state')['branches'][0];
        $source = app(ClinicDaySummaryTool::class)->run($director, $this->branch, [])['branches'][0];

        $this->assertSame(2, $entry['unfinished']);
        $this->assertSame(0, $entry['completed']);
        $this->assertSame(0, $entry['cancelled']);
        $this->assertSame(2, $entry['totalIncludingCancelled']);
        $this->assertSame($source['registered'], $entry['totalNotCancelled']);
        $this->assertSame('CHERAS', $entry['branch']);
    }

    public function test_queue_now_counts_waiting_not_ready_and_matches_waiting_now(): void
    {
        $ca = $this->actor('ca');
        $doctor = $this->doctor();
        $waiting = $this->consultationVisit($ca, $doctor);
        $flagged = $this->consultationVisit($ca, $doctor);
        $this->send($ca, $waiting);
        $queue = $this->send($ca, $flagged);
        $this->selectBranch($ca);
        app(CallHoldService::class)->change($ca, $flagged, [
            'expected_branch_id' => $this->branch->id, 'queue_lock_version' => $queue->lock_version,
            'reason_code' => 'awaiting_panel_confirmation', 'idempotency_key' => (string) Str::uuid(),
        ], false);
        $director = $this->actor('director');

        $entry = $this->connectorAnswer($director, 'queue_now')['branches'][0];
        $source = app(ClinicDaySummaryTool::class)->run($director, $this->branch, [])['branches'][0];

        $this->assertSame(2, $entry['waiting']);
        $this->assertSame(1, $entry['notReadyToCall']);
        $this->assertSame(0, $entry['serving']);
        $this->assertSame(0, $entry['onHold']);
        $this->assertSame($source['waitingNow'], $entry['waiting']);
        $this->assertArrayNotHasKey('queueNumber', $entry);
    }

    public function test_queue_now_counts_a_waiting_patient_carried_over_from_an_earlier_day(): void
    {
        $ca = $this->actor('ca');
        $doctor = $this->doctor();
        $this->send($ca, $this->consultationVisit($ca, $doctor));
        $carried = $this->send($ca, $this->consultationVisit($ca, $doctor));
        \Illuminate\Support\Facades\DB::table('queue_entries')->where('id', $carried->id)->update(['operational_date' => now($this->branch->timezone)->subDay()->toDateString()]);

        $entry = $this->connectorAnswer($this->actor('director'), 'queue_now')['branches'][0];

        $this->assertSame(2, $entry['waiting']);
        $this->assertSame(1, $entry['carriedOver']);
    }
}
