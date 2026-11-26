<?php

namespace Tests\Feature\Queue;

use App\Domain\Queue\Models\QueueEntry;
use App\Domain\Queue\Services\CallNextService;
use App\Domain\Visit\Models\Visit;
use App\Models\User;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Str;
use Tests\Feature\Clinical\Triage\TriageTestCase;

/** Q-16a: red zone first, then yellow, then the rest; inside each zone Urgent, then arrival (owner decision 25 Nov 2026). */
class QueueWaitingOrderTest extends TriageTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Date::setTestNow('2026-08-25 01:00:00 UTC');
    }

    protected function tearDown(): void
    {
        Date::setTestNow();
        parent::tearDown();
    }

    /** @return array{User, User} CA and the doctor who owns every patient below */
    private function people(): array
    {
        $this->approvedGuideline();

        return [$this->actor('ca'), $this->doctor()];
    }

    private function waiting(User $ca, User $doctor, string $at, array $visit = [], ?string $zone = null): Visit
    {
        Date::setTestNow($at);
        $v = $this->consultationVisit($ca, $doctor, $visit);
        $this->send($ca, $v);
        if ($zone !== null) {
            $this->triage($ca, $v->refresh(), ['chosen_zone' => $zone]);
        }

        return $v->refresh();
    }

    /** @return list<string> */
    private function board(User $actor): array
    {
        $this->selectBranch($actor);

        return collect($this->actingAs($actor)->postJson(route('queue.search'), [])->assertOk()->json('waiting.data'))
            ->pluck('visitNumber')->all();
    }

    private function next(User $doctor): ?string
    {
        $this->selectBranch($doctor);

        return $this->actingAs($doctor)->postJson(route('queue.search'), [])->assertOk()->json('callNext.candidate.visitNumber');
    }

    public function test_red_then_yellow_then_the_rest_and_arrival_inside_a_zone(): void
    {
        [$ca, $doctor] = $this->people();
        $green1 = $this->waiting($ca, $doctor, '2026-08-25 01:00:00 UTC', [], 'green');
        $untriaged = $this->waiting($ca, $doctor, '2026-08-25 01:01:00 UTC');
        $yellow = $this->waiting($ca, $doctor, '2026-08-25 01:02:00 UTC', [], 'yellow');
        $red = $this->waiting($ca, $doctor, '2026-08-25 01:03:00 UTC', [], 'red');
        $green2 = $this->waiting($ca, $doctor, '2026-08-25 01:04:00 UTC', [], 'green');

        $expected = [$red, $yellow, $green1, $untriaged, $green2];
        $numbers = array_map(fn (Visit $v) => $v->visit_number, $expected);
        $this->assertSame($numbers, $this->board($ca));
        $this->assertSame($red->visit_number, $this->next($doctor));
    }

    public function test_a_manually_urgent_green_now_waits_behind_yellow_and_a_retake_keeps_urgent(): void
    {
        [$ca, $doctor] = $this->people();
        $urgentGreen = $this->waiting($ca, $doctor, '2026-08-25 01:00:00 UTC', ['priority' => 'urgent'], 'green');
        $yellow = $this->waiting($ca, $doctor, '2026-08-25 01:05:00 UTC', [], 'yellow');
        $this->assertSame([$yellow->visit_number, $urgentGreen->visit_number], $this->board($ca));

        // Re-triaged green after yellow: the patient stays Urgent (TRI-1a never lowers it) but now ranks as green.
        $this->triage($ca, $yellow->refresh(), ['chosen_zone' => 'green']);
        $this->assertSame('urgent', $yellow->refresh()->priority);
        $this->assertSame([$urgentGreen->visit_number, $yellow->visit_number], $this->board($ca));
    }

    public function test_urgent_is_first_inside_a_zone(): void
    {
        [$ca, $doctor] = $this->people();
        $normal = $this->waiting($ca, $doctor, '2026-08-25 01:00:00 UTC', [], 'green');
        $urgent = $this->waiting($ca, $doctor, '2026-08-25 01:05:00 UTC', ['priority' => 'urgent'], 'green');

        $this->assertSame([$urgent->visit_number, $normal->visit_number], $this->board($ca));
        $this->assertSame($urgent->visit_number, $this->next($doctor));
    }

    public function test_emergency_red_carry_over_precedes_todays_non_red_patients(): void
    {
        [$ca, $doctor] = $this->people();
        $carry = $this->waiting($ca, $doctor, '2026-08-24 01:00:00 UTC', [], 'red');
        $today = $this->waiting($ca, $doctor, '2026-08-25 01:00:00 UTC', [], 'green');

        $this->assertSame($carry->visit_number, $this->next($doctor));
        $today->queueEntry->forceFill(['status' => QueueEntry::STATUS_REMOVED, 'removed_at' => now()->utc(), 'removal_reason' => 'cancelled'])->save();
        $this->assertSame($carry->visit_number, $this->next($doctor));
    }

    public function test_a_flagged_red_is_still_skipped_by_call_next(): void
    {
        [$ca, $doctor] = $this->people();
        $red = $this->waiting($ca, $doctor, '2026-08-25 01:00:00 UTC', [], 'green');
        $green = $this->waiting($ca, $doctor, '2026-08-25 01:01:00 UTC', [], 'green');
        $this->selectBranch($ca);
        $this->actingAs($ca)->post(route('queue.readiness.set', $red->visit_number), [
            'expected_branch_id' => $this->branch->id,
            'queue_lock_version' => $red->queueEntry->refresh()->lock_version,
            'reason_code' => 'other_administrative',
            'idempotency_key' => (string) Str::uuid(),
        ])->assertRedirect();

        $this->triage($ca, $red->refresh(), ['chosen_zone' => 'red']);

        $this->assertSame($green->visit_number, $this->next($doctor));
        $this->assertSame(CallNextService::STATE_READY, $this->actingAs($doctor)->postJson(route('queue.search'), [])->json('callNext.state'));
    }

    public function test_the_order_never_puts_a_zone_in_a_viewers_payload(): void
    {
        [$ca, $doctor] = $this->people();
        $this->waiting($ca, $doctor, '2026-08-25 01:00:00 UTC', [], 'red');
        $other = $this->doctor();
        $this->selectBranch($other);
        $rows = $this->actingAs($other)->postJson(route('queue.search'), [])->assertOk()->json('waiting.data');

        // Another doctor sees none of this patient's rows and no zone is carried to anyone who may not see it.
        $this->assertSame([], $rows);
    }
}
