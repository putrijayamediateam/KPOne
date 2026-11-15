<?php

namespace Tests\Feature\Queue;

use App\Domain\Queue\QueueNumberFormat;
use App\Domain\Queue\Readiness\CallHoldProjection;
use App\Domain\Queue\Readiness\CallHoldService;
use App\Domain\Visit\Services\VisitAdministrationService;
use Illuminate\Support\Str;

class CallReadinessCountTest extends QueueTestCase
{
    public function test_dropdown_keeps_its_shape_plus_only_one_scoped_aggregate_integer(): void
    {
        $ca = $this->actor('ca');
        $doctor = $this->doctor();
        $otherDoctor = $this->doctor();
        foreach ([$doctor, $otherDoctor] as $assigned) {
            $visit = $this->consultationVisit($ca, $assigned);
            $queue = $this->send($ca, $visit);
            $this->selectBranch($ca);
            app(CallHoldService::class)->change($ca, $visit, [
                'expected_branch_id' => $this->branch->id,
                'queue_lock_version' => $queue->lock_version,
                'reason_code' => 'awaiting_panel_confirmation',
                'idempotency_key' => (string) Str::uuid(),
            ], false);
        }
        $this->selectBranch($doctor);
        $url = route('queue.waiting-counts', ['expected_branch_id' => $this->branch->id]);
        $legacy = $this->getJson($url)->assertOk()->json();
        $this->getJson($url.'&include_readiness=1&scope=branch')->assertOk()
            ->assertExactJson([...$legacy, 'notReady' => 1]);
        $this->assertSame(['branchId', 'own', 'branch'], array_keys($legacy));
    }

    public function test_clear_signal_is_identity_free_and_delivered_only_once_to_its_assigned_doctor(): void
    {
        $ca = $this->actor('ca');
        $doctor = $this->doctor();
        $visit = $this->consultationVisit($ca, $doctor);
        $queue = $this->send($ca, $visit);
        $this->selectBranch($ca);
        $input = ['expected_branch_id' => $this->branch->id, 'queue_lock_version' => $queue->lock_version];
        $service = app(CallHoldService::class);
        $flag = $service->change($ca, $visit, [...$input, 'reason_code' => 'awaiting_patient_details', 'idempotency_key' => (string) Str::uuid()], false);
        $this->selectBranch($doctor);
        $projection = app(CallHoldProjection::class);
        $initial = $projection->updates($doctor, $this->branch->id, null, false);
        $this->assertSame(['cursor' => 0, 'ready' => false], $initial);
        $this->selectBranch($ca);
        $service->change($ca, $visit, [...$input, 'call_hold_id' => $flag['id'], 'lock_version' => $flag['lockVersion'], 'idempotency_key' => (string) Str::uuid()], true);
        $this->selectBranch($doctor);
        $update = $projection->updates($doctor, $this->branch->id, $initial['cursor'], false);
        $this->assertSame(['cursor', 'ready'], array_keys($update));
        $this->assertTrue($update['ready']);
        $this->assertFalse($projection->updates($doctor, $this->branch->id, $update['cursor'], false)['ready']);
        $this->assertSame([QueueNumberFormat::format($queue->queue_number)], $projection->updates($doctor, $this->branch->id, 0, true)['numbers']);
    }

    public function test_cancellation_drops_the_aggregate_without_a_ready_signal_and_reflagged_entries_do_not_alert(): void
    {
        $ca = $this->actor('ca');
        $doctor = $this->doctor();
        $visit = $this->consultationVisit($ca, $doctor);
        $queue = $this->send($ca, $visit);
        $this->selectBranch($ca);
        $input = ['expected_branch_id' => $this->branch->id, 'queue_lock_version' => $queue->lock_version];
        $service = app(CallHoldService::class);
        $flag = $service->change($ca, $visit, [...$input, 'reason_code' => 'other_administrative', 'idempotency_key' => (string) Str::uuid()], false);
        $service->change($ca, $visit, [...$input, 'call_hold_id' => $flag['id'], 'lock_version' => $flag['lockVersion'], 'idempotency_key' => (string) Str::uuid()], true);
        $service->change($ca, $visit, [...$input, 'reason_code' => 'awaiting_panel_confirmation', 'idempotency_key' => (string) Str::uuid()], false);
        $projection = app(CallHoldProjection::class);
        $this->selectBranch($doctor);
        $this->assertFalse($projection->updates($doctor, $this->branch->id, 0, false)['ready']);
        $this->selectBranch($ca);
        app(VisitAdministrationService::class)->cancel($visit, ['expected_branch_id' => $this->branch->id, 'lock_version' => $visit->lock_version, 'queue_lock_version' => $queue->lock_version, 'cancellation_reason' => 'Synthetic cancellation'], $ca);
        $this->selectBranch($doctor);
        $this->assertFalse($projection->updates($doctor, $this->branch->id, 0, false)['ready']);
        $this->getJson(route('queue.waiting-counts', ['expected_branch_id' => $this->branch->id, 'include_readiness' => 1]))->assertOk()->assertExactJson(['branchId' => $this->branch->id, 'own' => 0, 'branch' => null, 'notReady' => 0]);
    }
}
