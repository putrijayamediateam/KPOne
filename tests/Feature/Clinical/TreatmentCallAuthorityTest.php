<?php

namespace Tests\Feature\Clinical;

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Clinical\Services\ConsultationHoldService;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Queue\Display\QueueDisplayAdministrationService;
use App\Domain\Queue\Display\RoomCallService;
use App\Domain\Queue\Models\QueueCall;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Str;

class TreatmentCallAuthorityTest extends ClinicalTestCase
{
    public function test_narrow_authority_calls_held_patient_without_changing_clinical_state_and_keeps_cooldown(): void
    {
        [$doctor, , $visit, $queue] = $this->servingFixture();
        $encounter = $this->startEncounter($doctor, $visit, $queue);
        $this->holdEncounter($doctor, $visit, $queue, $encounter);
        $hold = $encounter->activeHold()->sole();
        $before = [$visit->refresh()->getAttributes(), $queue->refresh()->getAttributes(), $encounter->refresh()->getAttributes(), $hold->getAttributes()];
        $room = app(QueueDisplayAdministrationService::class)->createRoom($this->actor('director'), $this->branch, ['kind' => 'treatment', 'name' => 'Synthetic treatment room', 'sort_order' => 1]);
        $attributes = ['expected_branch_id' => $visit->branch_id, 'queue_lock_version' => $queue->lock_version, 'branch_room_id' => $room->id];
        foreach (['medical_assistant', 'ca_supervisor'] as $role) {
            $actor = $this->actor($role);
            $this->selectBranch($actor, $this->branch);
            $this->patch(route('queue.treatment-call', $visit->visit_number), $attributes)->assertRedirect(route('queue.index'))->assertSessionHasNoErrors();
            $this->patch(route('queue.treatment-call', $visit->visit_number), $attributes)->assertRedirect()->assertSessionHasErrors('queue');
            $this->travel(QueueCall::RECALL_COOLDOWN_SECONDS + 1)->seconds();
        }
        $this->assertSame($before, [$visit->refresh()->getAttributes(), $queue->refresh()->getAttributes(), $encounter->refresh()->getAttributes(), $hold->refresh()->getAttributes()]);
        $this->assertSame(2, QueueCall::query()->where('service', 'treatment')->count());
        $this->assertTrue(QueueCall::query()->where('service', 'treatment')->latest('id')->firstOrFail()->is_recall);
        $this->assertSame(2, AuditLog::query()->where('event', 'queue.treatment_called')->count());
    }

    public function test_resume_makes_old_call_version_stale_and_fresh_call_does_not_rehold(): void
    {
        [$doctor, , $visit, $queue] = $this->servingFixture();
        $encounter = $this->startEncounter($doctor, $visit, $queue);
        $this->holdEncounter($doctor, $visit, $queue, $encounter);
        $room = app(QueueDisplayAdministrationService::class)->createRoom($this->actor('director'), $this->branch, ['kind' => 'treatment', 'name' => 'Synthetic treatment room', 'sort_order' => 1]);
        $attributes = ['expected_branch_id' => $visit->branch_id, 'queue_lock_version' => $queue->refresh()->lock_version, 'branch_room_id' => $room->id];
        $this->selectBranch($doctor, $this->branch);
        app(ConsultationHoldService::class)->resume($doctor, $visit->refresh(), [...$attributes, 'visit_lock_version' => $visit->lock_version, 'encounter_lock_version' => $encounter->refresh()->lock_version, 'idempotency_key' => (string) Str::uuid()]);
        $ma = $this->actor('medical_assistant');
        $this->selectBranch($ma, $this->branch);
        $this->patch(route('queue.treatment-call', $visit->visit_number), $attributes)->assertSessionHasErrors('lock_version');
        $this->assertSame(0, QueueCall::query()->where('service', 'treatment')->count());
        $this->patch(route('queue.treatment-call', $visit->visit_number), [...$attributes, 'queue_lock_version' => $queue->refresh()->lock_version])->assertRedirect(route('queue.index'))->assertSessionHasNoErrors();
        $this->assertFalse($encounter->activeHold()->exists());
    }

    public function test_wrong_branch_room_stale_version_and_waiting_patient_are_refused(): void
    {
        [, , $visit, $queue] = $this->servingFixture();
        $ma = $this->actor('medical_assistant');
        $room = app(QueueDisplayAdministrationService::class)->createRoom($this->actor('director'), $this->branch, ['kind' => 'treatment', 'name' => 'Synthetic treatment room', 'sort_order' => 1]);
        $this->selectBranch($ma, $this->branch);
        $attributes = ['expected_branch_id' => $visit->branch_id, 'queue_lock_version' => $queue->lock_version, 'branch_room_id' => $room->id];
        foreach ([['expected_branch_id', 0, 'expected_branch_id'], ['queue_lock_version', $queue->lock_version + 1, 'lock_version'], ['branch_room_id', 0, 'branch_room_id']] as [$field, $value, $error]) {
            $this->patch(route('queue.treatment-call', $visit->visit_number), [...$attributes, $field => $value])->assertSessionHasErrors($error);
        }
        $waitingVisit = $this->consultationVisit($ma, $this->doctor());
        $waitingQueue = $this->send($ma, $waitingVisit);
        $this->patch(route('queue.treatment-call', $waitingVisit->visit_number), [...$attributes, 'queue_lock_version' => $waitingQueue->lock_version])->assertRedirect()->assertSessionHasErrors('queue');
        $this->assertSame(0, QueueCall::query()->where('service', 'treatment')->count());
        $otherBranch = Branch::query()->where('organisation_id', $this->organisation->id)->whereKeyNot($this->branch->id)->firstOrFail();
        $outsider = $this->actor('medical_assistant', $otherBranch);
        $this->selectBranch($outsider, $otherBranch);
        $this->patch(route('queue.treatment-call', $visit->visit_number), [...$attributes, 'expected_branch_id' => $otherBranch->id])->assertNotFound();
        $this->selectBranch($ma, $this->branch);
        $ma->syncRoles(['ca']);
        $this->expectException(AuthorizationException::class);
        app(RoomCallService::class)->callToTreatment($ma, $visit, $room->id, $attributes);
    }

    public function test_inactive_actor_is_rechecked_by_domain_and_narrow_permission_does_not_grant_doctor_calls(): void
    {
        [, , $visit, $queue] = $this->servingFixture();
        $ma = $this->actor('medical_assistant');
        $this->selectBranch($ma, $this->branch);
        $this->assertFalse($ma->can('queue.call.own'));
        $this->assertFalse($ma->can('queue.call.branch'));
        $attributes = ['expected_branch_id' => $visit->branch_id, 'queue_lock_version' => $queue->lock_version];
        $this->patch(route('queue.recall', $visit->visit_number), $attributes)->assertForbidden();
        $this->patch(route('queue.call', $visit->visit_number), $attributes)->assertForbidden();
        $this->post(route('queue.call-next'), $attributes)->assertForbidden();
        $ma->forceFill(['is_active' => false])->save();
        $this->expectException(AuthorizationException::class);
        app(RoomCallService::class)->callToTreatment($ma, $visit, 0, $attributes);
    }
}
