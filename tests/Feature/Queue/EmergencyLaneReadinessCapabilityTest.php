<?php

namespace Tests\Feature\Queue;

use App\Domain\Queue\Readiness\CallHoldProjection;
use App\Domain\Queue\Readiness\CallHoldService;
use App\Domain\Queue\Services\QueueDirectoryService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Clinical\Triage\TriageTestCase;

class EmergencyLaneReadinessCapabilityTest extends TriageTestCase
{
    public function test_latest_red_disables_only_new_flag_capability_and_preserves_existing_flag_actions(): void
    {
        $this->approvedGuideline();
        [$ca, $visit, $entry] = $this->waitingFixture();
        $projection = app(CallHoldProjection::class);
        $this->assertTrue($projection->rows($ca, [$entry->id])[$entry->id]['canSet']);
        app(CallHoldService::class)->change($ca, $visit, ['expected_branch_id' => $this->branch->id, 'queue_lock_version' => $entry->lock_version, 'reason_code' => 'awaiting_panel_confirmation', 'idempotency_key' => (string) Str::uuid()], false);
        $before = $projection->rows($ca, [$entry->id])[$entry->id];
        $this->triage($ca, $visit, ['temperature_celsius' => '40', 'chosen_zone' => 'red']);
        $red = $projection->rows($ca, [$entry->id])[$entry->id];
        $this->assertFalse($red['canSet']);
        foreach (['id', 'lockVersion', 'active', 'reason', 'canClear', 'canOverride'] as $field) {
            $this->assertSame($before[$field], $red[$field], $field.' must not be changed by the display capability');
        }
        $this->selectBranch($ca);
        $snapshot = app(QueueDirectoryService::class)->snapshot($ca);
        $this->assertFalse($snapshot['emergency']['data'][0]['boardRow']['callHold']['canSet']);
        $this->assertTrue($snapshot['emergency']['data'][0]['boardRow']['callHold']['canClear']);
        $this->triage($ca, $visit, ['chosen_zone' => 'green']);
        $this->assertTrue($projection->rows($ca, [$entry->id])[$entry->id]['canSet'], 'Use latest assessment, not any historical red assessment.');
    }

    public function test_latest_zone_lookup_retains_two_queries_for_one_and_twenty_six_rows(): void
    {
        $this->approvedGuideline();
        $ca = $this->actor('ca');
        $doctor = $this->doctor();
        $ids = [];
        for ($i = 0; $i < 26; $i++) {
            $visit = $this->consultationVisit($ca, $doctor);
            $ids[] = $this->send($ca, $visit)->id;
            $this->triage($ca, $visit, ['red_flag_codes' => ['rf_01'], 'chosen_zone' => 'red']);
        }
        $projection = app(CallHoldProjection::class);
        $projection->rows($ca, $ids);
        foreach ([1, 26] as $count) {
            DB::enableQueryLog();
            DB::flushQueryLog();
            try {
                $rows = $projection->rows($ca, array_slice($ids, 0, $count));
                $queries = count(DB::getQueryLog());
            } finally {
                DB::disableQueryLog();
            }
            $this->assertSame(2, $queries);
            $this->assertCount($count, $rows);
            $this->assertSame([false], array_values(array_unique(array_column($rows, 'canSet'))));
        }
    }
}
