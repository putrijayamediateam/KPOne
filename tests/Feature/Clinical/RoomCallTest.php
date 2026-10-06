<?php

namespace Tests\Feature\Clinical;

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Clinical\Dispensary\Models\DispensaryCase;
use App\Domain\Clinical\Dispensary\Services\DispensaryHandoffService;
use App\Domain\Clinical\Models\MedicineCatalogueItem;
use App\Domain\Clinical\Services\PatientAllergyService;
use App\Domain\Clinical\Services\TreatmentPlanService;
use App\Domain\Queue\Display\QueueDisplayAdministrationService;
use App\Domain\Queue\Display\RoomCallService;
use App\Domain\Queue\Models\BranchRoom;
use App\Domain\Queue\Models\QueueCall;
use App\Domain\Queue\Models\QueueEntry;
use App\Domain\Visit\Models\Visit;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class RoomCallTest extends ClinicalTestCase
{
    public function test_staff_call_a_dispensary_patient_to_the_dispensary_room_without_changing_the_case(): void
    {
        [, $ca, $visit, $case] = $this->dispensaryFixture();
        $room = $this->room('dispensary', 'Farmasi');
        $before = $case->refresh()->only(['status', 'lock_version']);

        $this->selectBranch($ca, $visit->branch);
        $this->get(route('dispensary.show', $case))->assertOk()
            ->assertInertia(fn ($page) => $page->where('dispensary.tvCall.canCall', true)
                ->where('dispensary.tvCall.rooms.0.name', 'Farmasi'));
        $this->post(route('dispensary.call', $case), ['expected_branch_id' => $visit->branch_id])
            ->assertRedirect(route('dispensary.show', $case))->assertSessionHasNoErrors();

        $call = QueueCall::query()->where('service', QueueCall::SERVICE_DISPENSARY)->sole();
        $this->assertSame($room->id, $call->branch_room_id);
        $this->assertSame('Farmasi', $call->room_name);
        $this->assertFalse($call->is_recall);
        $this->assertSame($before, $case->refresh()->only(['status', 'lock_version']));
        $this->assertTrue(AuditLog::query()->where('event', 'queue.dispensary_called')->exists());

        $this->post(route('dispensary.call', $case), ['expected_branch_id' => $visit->branch_id])
            ->assertSessionHasErrors('queue');
        $this->travel(QueueCall::RECALL_COOLDOWN_SECONDS + 1)->seconds();
        $this->post(route('dispensary.call', $case), ['expected_branch_id' => $visit->branch_id])
            ->assertSessionHasNoErrors();
        $this->assertTrue(QueueCall::query()->where('service', QueueCall::SERVICE_DISPENSARY)->latest('id')->firstOrFail()->is_recall);

        $display = $this->actor('queue_display', $visit->branch);
        $this->selectBranch($display, $visit->branch);
        $this->getJson(route('queue-display.feed'))->assertOk()
            ->assertJsonCount(1, 'calls')
            ->assertJsonPath('calls.0.service', 'dispensary')
            ->assertJsonPath('calls.0.room', 'Farmasi');
    }

    public function test_a_choice_of_dispensary_room_is_needed_when_the_branch_has_several(): void
    {
        [, $ca, $visit, $case] = $this->dispensaryFixture();
        $this->room('dispensary', 'Farmasi 1');
        $second = $this->room('dispensary', 'Farmasi 2');
        $consultation = $this->room('consultation', 'Consultation Room 9');
        $this->selectBranch($ca, $visit->branch);

        $this->post(route('dispensary.call', $case), ['expected_branch_id' => $visit->branch_id])
            ->assertSessionHasErrors('branch_room_id');
        $this->post(route('dispensary.call', $case), ['expected_branch_id' => $visit->branch_id, 'branch_room_id' => $consultation->id])
            ->assertSessionHasErrors('branch_room_id');
        $this->post(route('dispensary.call', $case), ['expected_branch_id' => $visit->branch_id, 'branch_room_id' => $second->id])
            ->assertSessionHasNoErrors();
        $this->assertSame('Farmasi 2', QueueCall::query()->where('service', QueueCall::SERVICE_DISPENSARY)->sole()->room_name);
    }

    public function test_a_branch_without_dispensary_rooms_still_calls_and_other_roles_cannot(): void
    {
        [$doctor, $ca, $visit, $case] = $this->dispensaryFixture();
        $this->selectBranch($ca, $visit->branch);
        $this->post(route('dispensary.call', $case), ['expected_branch_id' => $visit->branch_id])->assertSessionHasNoErrors();
        $this->assertNull(QueueCall::query()->where('service', QueueCall::SERVICE_DISPENSARY)->sole()->room_name);

        $this->selectBranch($doctor, $visit->branch);
        $this->post(route('dispensary.call', $case), ['expected_branch_id' => $visit->branch_id])->assertForbidden();
    }

    public function test_doctor_calls_the_serving_patient_to_a_treatment_room_and_keeps_the_consultation(): void
    {
        [$doctor, , $visit, $queue] = $this->servingFixture();
        $this->startEncounter($doctor, $visit, $queue);
        $room = $this->room('treatment', 'Treatment Room 1');
        $this->selectBranch($doctor, $visit->branch);
        $this->travel(QueueCall::RECALL_COOLDOWN_SECONDS + 1)->seconds();

        $this->get(route('encounters.show', $visit->visit_number))->assertOk()
            ->assertInertia(fn ($page) => $page->where('treatmentRooms.0.name', 'Treatment Room 1'));
        $this->patch(route('queue.treatment-call', $visit->visit_number), [
            'expected_branch_id' => $visit->branch_id,
            'queue_lock_version' => $queue->refresh()->lock_version,
            'branch_room_id' => $room->id,
            'from' => 'consultation',
        ])->assertRedirect(route('encounters.show', $visit->visit_number))->assertSessionHasNoErrors();

        $call = QueueCall::query()->where('service', QueueCall::SERVICE_TREATMENT)->sole();
        $this->assertSame('Treatment Room 1', $call->room_name);
        $this->assertSame(QueueEntry::STATUS_SERVING, $queue->refresh()->status);
        $this->assertTrue(AuditLog::query()->where('event', 'queue.treatment_called')->exists());

        $display = $this->actor('queue_display', $visit->branch);
        $this->selectBranch($display, $visit->branch);
        $this->getJson(route('queue-display.feed'))->assertOk()
            ->assertJsonCount(1, 'calls')
            ->assertJsonPath('calls.0.service', 'treatment')
            ->assertJsonPath('calls.0.room', 'Treatment Room 1');
    }

    public function test_treatment_call_needs_a_treatment_room_a_serving_unheld_patient_and_call_authority(): void
    {
        [$doctor, , $visit, $queue] = $this->servingFixture();
        $encounter = $this->startEncounter($doctor, $visit, $queue);
        $treatment = $this->room('treatment', 'Treatment Room 1');
        $dispensary = $this->room('dispensary', 'Farmasi');
        $calls = app(RoomCallService::class);
        $attributes = fn (): array => ['expected_branch_id' => $visit->branch_id, 'queue_lock_version' => $queue->refresh()->lock_version];
        $this->selectBranch($doctor, $visit->branch);

        try {
            $calls->callToTreatment($doctor, $visit, $dispensary->id, $attributes());
            $this->fail('A dispensary room is not a treatment room.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('branch_room_id', $exception->errors());
        }

        $otherDoctor = $this->doctor($visit->branch);
        $this->selectBranch($otherDoctor, $visit->branch);
        $this->patch(route('queue.treatment-call', $visit->visit_number), [
            ...$attributes(), 'branch_room_id' => $treatment->id,
        ])->assertForbidden();

        $ca = $this->actor('ca', $visit->branch);
        $this->selectBranch($ca, $visit->branch);
        $this->patch(route('queue.treatment-call', $visit->visit_number), [
            ...$attributes(), 'branch_room_id' => $treatment->id,
        ])->assertForbidden();

        $this->holdEncounter($doctor, $visit, $queue, $encounter);
        $this->selectBranch($doctor, $visit->branch);
        try {
            $calls->callToTreatment($doctor, $visit->refresh(), $treatment->id, $attributes());
            $this->fail('A held consultation cannot be called to a treatment room.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('queue', $exception->errors());
        }
        $this->assertSame(0, QueueCall::query()->where('service', QueueCall::SERVICE_TREATMENT)->count());
    }

    public function test_supervisor_calls_to_a_treatment_room_from_the_queue_board(): void
    {
        [, , $visit, $queue] = $this->servingFixture();
        $room = $this->room('treatment', 'Treatment Room 2');
        $supervisor = $this->actor('ca_supervisor', $visit->branch);
        $this->selectBranch($supervisor, $visit->branch);

        $this->get(route('queue.index'))->assertOk()
            ->assertInertia(fn ($page) => $page->where('treatmentRooms.0.name', 'Treatment Room 2'));
        $this->patch(route('queue.treatment-call', $visit->visit_number), [
            'expected_branch_id' => $visit->branch_id,
            'queue_lock_version' => $queue->refresh()->lock_version,
            'branch_room_id' => $room->id,
        ])->assertRedirect(route('queue.index'))->assertSessionHasNoErrors();
        $this->assertSame(1, QueueCall::query()->where('service', QueueCall::SERVICE_TREATMENT)->count());
    }

    private function room(string $kind, string $name): BranchRoom
    {
        return app(QueueDisplayAdministrationService::class)->createRoom($this->actor('director'), $this->branch, [
            'kind' => $kind, 'name' => $name, 'sort_order' => 1,
        ]);
    }

    /** @return array{User, User, Visit, DispensaryCase} */
    private function dispensaryFixture(): array
    {
        [$doctor, $ca, $visit, $queue] = $this->servingFixture();
        $this->startEncounter($doctor, $visit, $queue);
        $allergies = app(PatientAllergyService::class);
        $profile = $allergies->declareNoKnown($doctor, $visit, ['expected_branch_id' => $visit->branch_id, 'profile_lock_version' => null]);
        $allergies->review($doctor, $visit, ['expected_branch_id' => $visit->branch_id, 'profile_lock_version' => $profile->lock_version]);

        $medicine = new MedicineCatalogueItem;
        $medicine->forceFill([
            'public_id' => (string) Str::uuid(), 'organisation_id' => $doctor->organisation_id,
            'code' => 'SYN-MED-'.Str::upper(Str::random(8)), 'display_name' => 'Synthetic medicine', 'strength_text' => 'Synthetic strength',
            'dosage_form' => 'Synthetic form', 'order_unit' => 'unit', 'authorisation_class' => MedicineCatalogueItem::AUTHORISATION_DOCTOR_REQUIRED,
            'is_active' => true, 'created_by_user_id' => $doctor->id, 'updated_by_user_id' => $doctor->id,
        ])->save();
        $plan = app(TreatmentPlanService::class)->save($doctor, $visit, [
            'expected_branch_id' => $visit->branch_id, 'lock_version' => null, 'services' => [],
            'medicines' => [['public_id' => null, 'catalogue_public_id' => $medicine->public_id, 'quantity_ordered' => 1, 'dosage' => 'Synthetic dosage', 'frequency' => 'Synthetic frequency', 'duration' => null, 'route' => null, 'administration_instruction' => null, 'indication' => null, 'precaution' => null]],
        ]);
        $case = app(DispensaryHandoffService::class)->send($doctor, $visit, [
            'expected_branch_id' => $visit->branch_id,
            'lock_version' => $plan->lock_version,
            'service_deliveries' => [],
        ]);

        return [$doctor, $ca, $visit->refresh(), $case->refresh()];
    }
}
