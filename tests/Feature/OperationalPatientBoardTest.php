<?php

namespace Tests\Feature;

use App\Domain\Queue\Services\QueueEntryService;
use Tests\Feature\Queue\QueueTestCase;

class OperationalPatientBoardTest extends QueueTestCase
{
    public function test_registration_board_discards_out_of_order_frontend_search_responses(): void
    {
        $source = file_get_contents(resource_path('js/pages/Registration/Index.vue'));

        $this->assertIsString($source);
        $this->assertStringContainsString('let searchGeneration = 0;', $source);
        $this->assertStringContainsString('const generation = ++searchGeneration;', $source);
        $this->assertStringContainsString('if (generation !== searchGeneration)', $source);
        $this->assertStringContainsString('if (generation === searchGeneration)', $source);
    }

    public function test_registration_board_statuses_are_server_scoped_and_future_states_are_empty(): void
    {
        $ca = $this->actor('ca_supervisor');
        $doctor = $this->doctor();
        $waiting = $this->send($ca, $this->consultationVisit($ca, $doctor));
        $serving = $this->send($ca, $this->consultationVisit($ca, $doctor));
        $this->selectBranch($doctor);
        app(QueueEntryService::class)->call($doctor, $serving->visit, [
            'expected_branch_id' => $this->branch->id,
            'visit_lock_version' => $serving->visit->lock_version,
            'queue_lock_version' => $serving->lock_version,
        ]);
        $this->selectBranch($ca);

        $this->postJson(route('registration.search'), ['board_status' => 'waiting'])
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.visitNumber', $waiting->visit->visit_number)
            ->assertJsonPath('data.0.queueStatus', 'waiting');
        $this->postJson(route('registration.search'), ['board_status' => 'serving'])
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.visitNumber', $serving->visit->visit_number)
            ->assertJsonPath('data.0.queueStatus', 'serving');

        foreach (['dispensary', 'completed'] as $future) {
            $this->postJson(route('registration.search'), ['board_status' => $future])
                ->assertOk()
                ->assertJsonPath('total', 0)
                ->assertJsonCount(0, 'data');
        }
    }

    public function test_patient_board_projection_is_structural_and_actions_are_server_derived(): void
    {
        $ca = $this->actor('ca');
        $visit = $this->consultationVisit($ca, $this->doctor(), [
            'visit_reason' => 'Synthetic operational reason',
        ]);
        $this->selectBranch($ca);

        $this->postJson(route('registration.search'))
            ->assertOk()
            ->assertJsonPath('data.0.visitNumber', $visit->visit_number)
            ->assertJsonPath('data.0.can.sendToWaiting', true)
            ->assertJsonMissingPath('data.0.clinicalNote')
            ->assertJsonMissingPath('data.0.vitals')
            ->assertJsonMissingPath('data.0.diagnoses')
            ->assertJsonMissingPath('data.0.treatmentPlan')
            ->assertJsonMissingPath('data.0.dateOfBirth')
            ->assertJsonMissingPath('data.0.sex');
    }

    public function test_doctor_queue_scope_cannot_be_broadened_by_browser_filters(): void
    {
        $ca = $this->actor('ca');
        $doctor = $this->doctor();
        $other = $this->doctor();
        $own = $this->send($ca, $this->consultationVisit($ca, $doctor));
        $this->send($ca, $this->consultationVisit($ca, $other));
        $this->selectBranch($doctor);

        $this->postJson(route('queue.search'), ['doctor_id' => $other->id, 'scope' => 'branch'])
            ->assertOk()
            ->assertJsonPath('scope', 'own')
            ->assertJsonCount(1, 'waiting.data')
            ->assertJsonPath('waiting.data.0.visitNumber', $own->visit->visit_number)
            ->assertJsonMissingPath('waiting.data.0.clinicalNote')
            ->assertJsonMissingPath('waiting.data.0.treatmentPlan');
    }
}
