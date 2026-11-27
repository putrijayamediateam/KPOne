<?php

namespace Tests\Feature\Clinical;

use App\Domain\Queue\Display\QueueDisplayAdministrationService;
use App\Domain\Queue\Services\QueueDirectoryService;

class TreatmentCallProjectionTest extends ClinicalTestCase
{
    public function test_queue_consumer_exposes_narrow_call_for_held_ma_and_supervisor_without_granting_doctor_calls(): void
    {
        [$doctor, , $visit, $queue] = $this->servingFixture();
        $encounter = $this->startEncounter($doctor, $visit, $queue);
        $room = app(QueueDisplayAdministrationService::class)->createRoom($this->actor('director'), $this->branch, ['kind' => 'treatment', 'name' => 'Synthetic treatment room', 'sort_order' => 1]);
        $this->selectBranch($doctor);
        $before = app(QueueDirectoryService::class)->snapshot($doctor)['serving'][0];
        $this->assertTrue($before['canCallTreatment']);
        $this->holdEncounter($doctor, $visit, $queue, $encounter);
        foreach (['medical_assistant' => true, 'ca_supervisor' => true, 'ca' => false] as $role => $allowed) {
            $actor = $this->actor($role);
            $this->selectBranch($actor);
            $this->get(route('queue.index'))->assertOk()->assertInertia(fn ($page) => $page
                ->where('snapshot.serving.0.visitNumber', $visit->visit_number)
                ->where('snapshot.serving.0.isHeld', true)
                ->where('snapshot.serving.0.canCallTreatment', $allowed)
                ->where('snapshot.serving.0.canCall', $role === 'ca_supervisor')
                ->where('treatmentRooms', $allowed ? [['id' => $room->id, 'name' => $room->name]] : []));
        }
        $this->selectBranch($doctor);
        $held = app(QueueDirectoryService::class)->snapshot($doctor)['serving'][0];
        $this->assertTrue($held['canCall']);
        $this->assertFalse($held['canCallTreatment']);
    }
}
