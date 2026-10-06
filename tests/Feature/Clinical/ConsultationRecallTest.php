<?php

namespace Tests\Feature\Clinical;

use App\Domain\Queue\Models\QueueCall;

class ConsultationRecallTest extends ClinicalTestCase
{
    public function test_doctor_calls_the_patient_again_from_the_consultation_page_and_stays_on_it(): void
    {
        [$doctor, , $visit, $queue] = $this->servingFixture();
        $this->startEncounter($doctor, $visit, $queue);
        $this->selectBranch($doctor, $visit->branch);

        $this->get(route('encounters.show', $visit->visit_number))->assertOk()
            ->assertInertia(fn ($page) => $page->where('clinical.queue.canRecall', true));

        $this->travel(QueueCall::RECALL_COOLDOWN_SECONDS + 1)->seconds();
        $this->patch(route('queue.recall', $visit->visit_number), [
            'expected_branch_id' => $visit->branch_id,
            'queue_lock_version' => $queue->refresh()->lock_version,
            'from' => 'consultation',
        ])->assertRedirect(route('encounters.show', $visit->visit_number))->assertSessionHasNoErrors();
        $this->assertSame(1, QueueCall::query()->where('is_recall', true)->count());

        $this->patch(route('queue.recall', $visit->visit_number), [
            'expected_branch_id' => $visit->branch_id,
            'queue_lock_version' => $queue->refresh()->lock_version,
            'from' => 'consultation',
        ])->assertRedirect(route('encounters.show', $visit->visit_number))->assertSessionHasErrors('queue');
    }

    public function test_a_held_consultation_offers_no_recall_and_refuses_one(): void
    {
        [$doctor, , $visit, $queue] = $this->servingFixture();
        $encounter = $this->startEncounter($doctor, $visit, $queue);
        $this->holdEncounter($doctor, $visit, $queue, $encounter);
        $this->selectBranch($doctor, $visit->branch);

        $this->get(route('encounters.show', $visit->visit_number))->assertOk()
            ->assertInertia(fn ($page) => $page->where('clinical.queue.canRecall', false));

        $this->travel(QueueCall::RECALL_COOLDOWN_SECONDS + 1)->seconds();
        $this->patch(route('queue.recall', $visit->visit_number), [
            'expected_branch_id' => $visit->branch_id,
            'queue_lock_version' => $queue->refresh()->lock_version,
            'from' => 'consultation',
        ])->assertRedirect(route('encounters.show', $visit->visit_number))->assertSessionHasErrors('queue');
        $this->assertSame(0, QueueCall::query()->where('is_recall', true)->count());
    }
}
