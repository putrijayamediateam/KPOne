<?php

namespace Tests\Feature\Queue;

use App\Domain\Queue\Models\QueueCall;
use App\Domain\Queue\Models\QueueEntry;
use App\Domain\Queue\Services\CallNextService;
use App\Domain\Visit\Models\Visit;
use App\Models\User;
use Illuminate\Support\Facades\Date;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Feature\Clinical\ClinicalTestCase;

/** Q-4b Call next from a held consultation (owner decisions 2026-11-06). */
class CallNextHeldConsultationTest extends ClinicalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // 09:00 in Kuala Lumpur, so "today" never depends on the hour the suite runs.
        Date::setTestNow('2026-08-25 01:00:00 UTC');
    }

    protected function tearDown(): void
    {
        Date::setTestNow();
        parent::tearDown();
    }

    /** @return TestResponse<Response> */
    private function ownBlock(User $actor, ?int $branchId = null): TestResponse
    {
        $this->selectBranch($actor);

        return $this->actingAs($actor)->getJson(route('queue.call-next.block', [
            'expected_branch_id' => $branchId ?? $this->branch->id,
        ]));
    }

    /**
     * @param  array<string, mixed>  $candidate
     * @param  array<string, mixed>  $extra
     * @return TestResponse<Response>
     */
    private function callNext(User $actor, array $candidate, array $extra = []): TestResponse
    {
        $this->selectBranch($actor);

        return $this->actingAs($actor)->post(route('queue.call-next'), [
            'expected_branch_id' => $this->branch->id,
            'doctor_user_id' => $actor->id,
            'visit_number' => $candidate['visitNumber'],
            'visit_lock_version' => $candidate['visitLockVersion'],
            'queue_lock_version' => $candidate['queueLockVersion'],
            ...$extra,
        ]);
    }

    /** @return array{0: User, 1: User, 2: Visit} */
    private function heldConsultation(): array
    {
        [$doctor, $ca, $visit, $queue] = $this->servingFixture();
        $encounter = $this->startEncounter($doctor, $visit, $queue);
        $this->holdEncounter($doctor, $visit->refresh(), $queue->refresh(), $encounter->refresh());

        return [$doctor, $ca, $visit->refresh()];
    }

    public function test_a_doctor_is_busy_until_the_consultation_is_held_then_sees_their_next_patient(): void
    {
        [$doctor, $ca, $visit, $queue] = $this->servingFixture();
        $next = $this->consultationVisit($ca, $doctor);
        $this->send($ca, $next);
        $encounter = $this->startEncounter($doctor, $visit, $queue);

        $block = $this->ownBlock($doctor)->assertOk()->json('callNext');
        $this->assertSame('own', $block['mode']);
        $this->assertSame(CallNextService::STATE_BUSY, $block['state']);
        $this->assertNull($block['candidate']);

        $this->holdEncounter($doctor, $visit->refresh(), $queue->refresh(), $encounter->refresh());
        $response = $this->ownBlock($doctor)->assertOk();
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $block = $response->json('callNext');
        $this->assertSame(CallNextService::STATE_READY, $block['state']);
        $this->assertSame($next->visit_number, $block['candidate']['visitNumber']);
    }

    public function test_the_block_is_only_for_the_doctors_own_queue_at_the_active_branch(): void
    {
        [$doctor, $ca] = $this->heldConsultation();

        $this->ownBlock($ca)->assertForbidden();
        $this->ownBlock($doctor, $this->branch->id + 999)->assertUnprocessable()
            ->assertJsonValidationErrors(['expected_branch_id' => 'The active branch changed. Reload the page.']);
        $this->actingAs($doctor)->getJson(route('queue.call-next.block'))
            ->assertUnprocessable()->assertJsonValidationErrors('expected_branch_id');

        // Someone who also sees the whole branch board calls from there, never from here.
        $doctor->givePermissionTo('queue.view.branch');
        $this->ownBlock($doctor)->assertOk()->assertExactJson(['callNext' => null]);
    }

    public function test_calling_from_a_held_consultation_goes_to_the_queue_board_and_keeps_the_held_patient_held(): void
    {
        [$doctor, $ca, $held] = $this->heldConsultation();
        $next = $this->consultationVisit($ca, $doctor);
        $this->send($ca, $next);
        $candidate = $this->ownBlock($doctor)->json('callNext.candidate');

        $this->callNext($doctor, $candidate, ['from' => 'consultation', 'from_visit' => $held->visit_number])
            ->assertRedirect(route('queue.index'))
            ->assertSessionHasNoErrors();

        $this->assertSame(QueueEntry::STATUS_SERVING, $next->queueEntry()->sole()->status);
        $this->assertSame(QueueEntry::STATUS_SERVING, $held->queueEntry()->sole()->status);
        $this->assertNotNull($held->clinicalEncounter()->sole()->activeHold);
        $this->assertSame(1, QueueCall::query()->where('queue_entry_id', $next->queueEntry->id)->count());
    }

    public function test_a_refusal_returns_to_the_doctors_own_held_consultation_and_nowhere_else(): void
    {
        [$doctor, $ca, $held] = $this->heldConsultation();
        $normal = $this->consultationVisit($ca, $doctor);
        $this->send($ca, $normal);
        $stale = $this->ownBlock($doctor)->json('callNext.candidate');
        Date::setTestNow('2026-08-25 01:02:00 UTC');
        $this->send($ca, $this->consultationVisit($ca, $doctor, ['priority' => 'urgent']));

        $this->callNext($doctor, $stale, ['from' => 'consultation', 'from_visit' => $held->visit_number])
            ->assertRedirect(route('encounters.show', $held->visit_number))
            ->assertSessionHasErrors('next_changed');

        // Another doctor's visit, an unknown visit or no "from" never becomes the return page.
        $otherDoctor = $this->doctor();
        $other = $this->consultationVisit($ca, $otherDoctor);
        foreach ([
            ['from' => 'consultation', 'from_visit' => $other->visit_number],
            ['from' => 'consultation', 'from_visit' => 'KPV-NOPE'],
            ['from' => 'consultation', 'from_visit' => str_repeat('A', 41)],
            ['from' => 'elsewhere', 'from_visit' => $held->visit_number],
            [],
        ] as $extra) {
            $this->callNext($doctor, $stale, $extra)
                ->assertRedirect(route('queue.index'))
                ->assertSessionHasErrors('next_changed');
        }
        $this->assertSame(0, QueueCall::query()->where('queue_entry_id', $normal->queueEntry->id)->count());
    }
}
