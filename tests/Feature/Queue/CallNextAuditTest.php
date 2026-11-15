<?php

namespace Tests\Feature\Queue;

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Queue\Services\QueueEntryService;
use App\Domain\Visit\Models\Visit;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Feature\Clinical\ClinicalTestCase;

/** Q-4c: the queue.called audit row says when Call next was used and where (owner decisions 2026-11-06). */
class CallNextAuditTest extends ClinicalTestCase
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

    /**
     * @param  array<string, mixed>  $extra
     * @return TestResponse<Response>
     */
    private function callNext(User $doctor, array $extra = []): TestResponse
    {
        $this->selectBranch($doctor);
        $candidate = $this->actingAs($doctor)->getJson(route('queue.call-next.block', [
            'expected_branch_id' => $this->branch->id,
        ]))->assertOk()->json('callNext.candidate');

        return $this->actingAs($doctor)->post(route('queue.call-next'), [
            'expected_branch_id' => $this->branch->id,
            'doctor_user_id' => $doctor->id,
            'visit_number' => $candidate['visitNumber'],
            'visit_lock_version' => $candidate['visitLockVersion'],
            'queue_lock_version' => $candidate['queueLockVersion'],
            ...$extra,
        ]);
    }

    /** @return array<string, mixed> */
    private function lastCalledMetadata(): array
    {
        return AuditLog::query()->where('event', 'queue.called')->latest('id')->firstOrFail()->metadata;
    }

    /** @return array{0: User, 1: User, 2: Visit} */
    private function heldConsultation(): array
    {
        [$doctor, $ca, $visit, $queue] = $this->servingFixture();
        $encounter = $this->startEncounter($doctor, $visit, $queue);
        $this->holdEncounter($doctor, $visit->refresh(), $queue->refresh(), $encounter->refresh());

        return [$doctor, $ca, $visit->refresh()];
    }

    public function test_call_next_from_the_board_marks_the_audit_row_and_an_ordinary_call_in_does_not(): void
    {
        $ca = $this->actor('ca');
        $doctor = $this->doctor();
        $first = $this->consultationVisit($ca, $doctor);
        $entry = $this->send($ca, $first);

        // The ordinary Call In keeps exactly today's audit row.
        $this->selectBranch($doctor);
        $called = app(QueueEntryService::class)->call($doctor, $first, [
            'expected_branch_id' => $this->branch->id,
            'visit_lock_version' => $first->lock_version,
            'queue_lock_version' => $entry->lock_version,
        ]);
        $this->assertSame([
            'from_state' => 'waiting',
            'to_state' => 'serving',
            'record_version' => $called->lock_version,
            'branch_room_id' => null,
        ], $this->lastCalledMetadata());

        // Another doctor uses Call next from the board.
        $nextDoctor = $this->doctor();
        $second = $this->consultationVisit($ca, $nextDoctor);
        $this->send($ca, $second);
        $this->callNext($nextDoctor)->assertRedirect(route('queue.index'))->assertSessionHasNoErrors();

        $this->assertSame([
            'from_state' => 'waiting',
            'to_state' => 'serving',
            'record_version' => $second->queueEntry()->sole()->lock_version,
            'branch_room_id' => null,
            'via' => 'call_next',
            'call_next_source' => 'board',
        ], $this->lastCalledMetadata());
    }

    public function test_held_consultation_is_recorded_only_for_the_doctors_own_held_consultation(): void
    {
        [$doctor, $ca, $held] = $this->heldConsultation();
        $next = $this->consultationVisit($ca, $doctor);
        $this->send($ca, $next);

        $this->callNext($doctor, ['from' => 'consultation', 'from_visit' => $held->visit_number])
            ->assertRedirect(route('queue.index'))->assertSessionHasNoErrors();

        $metadata = $this->lastCalledMetadata();
        $this->assertSame('call_next', $metadata['via']);
        $this->assertSame('held_consultation', $metadata['call_next_source']);
        // Structural only: no patient, visit, queue number or doctor in the row.
        foreach (['patient', 'patient_name', 'visit_number', 'queue_number', 'doctor', 'from_visit'] as $field) {
            $this->assertArrayNotHasKey($field, $metadata);
        }
    }

    public function test_a_from_visit_that_is_not_the_doctors_own_held_consultation_counts_as_the_board(): void
    {
        $ca = $this->actor('ca');
        $doctor = $this->doctor();
        // The doctor's own visit, waiting and not held.
        $own = $this->consultationVisit($ca, $doctor);
        $this->send($ca, $own);
        // Another doctor's held consultation.
        [$otherDoctor, , $othersHeld] = $this->heldConsultation();

        $this->callNext($doctor, ['from' => 'consultation', 'from_visit' => $othersHeld->visit_number])
            ->assertSessionHasNoErrors();
        $this->assertSame('board', $this->lastCalledMetadata()['call_next_source']);
        $this->assertNotSame($otherDoctor->id, $doctor->id);
    }

    public function test_the_audit_log_page_shows_where_call_next_was_used_and_nothing_for_call_in(): void
    {
        [$doctor, $ca, $held] = $this->heldConsultation();
        $next = $this->consultationVisit($ca, $doctor);
        $this->send($ca, $next);
        $this->callNext($doctor, ['from' => 'consultation', 'from_visit' => $held->visit_number])
            ->assertSessionHasNoErrors();

        $director = $this->actor('director');
        $this->actingAs($director)->get(route('audit-logs.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('logs.data', /** @param Collection<int, array<string, mixed>> $logs */ function (Collection $logs): bool {
                $called = $logs->where('event', 'queue.called')->values();

                // The servingFixture call is an ordinary Call In; the second is Call next from the held page.
                return $called->count() === 2
                    && $called->pluck('detail')->sort()->values()->all() === [null, 'Via Call next, from a held consultation']
                    && $logs->where('event', '!=', 'queue.called')->every(fn (array $log): bool => $log['detail'] === null);
            }));
    }

    public function test_an_ordinary_call_in_request_cannot_add_the_call_next_mark(): void
    {
        $ca = $this->actor('ca');
        $doctor = $this->doctor();
        $visit = $this->consultationVisit($ca, $doctor);
        $entry = $this->send($ca, $visit);
        $this->selectBranch($doctor);

        $this->actingAs($doctor)->patch(route('queue.call', $visit->visit_number), [
            'expected_branch_id' => $this->branch->id,
            'visit_lock_version' => $visit->refresh()->lock_version,
            'queue_lock_version' => $entry->lock_version,
            'via' => 'call_next',
            'call_next_source' => 'held_consultation',
        ])->assertSessionHasNoErrors();

        $this->assertSame([
            'from_state' => 'waiting',
            'to_state' => 'serving',
            'record_version' => $entry->refresh()->lock_version,
            'branch_room_id' => null,
        ], $this->lastCalledMetadata());
    }

    public function test_the_doctors_own_visit_that_is_not_held_counts_as_the_board(): void
    {
        $ca = $this->actor('ca');
        $doctor = $this->doctor();
        $own = $this->consultationVisit($ca, $doctor);
        $this->send($ca, $own);

        $this->callNext($doctor, ['from' => 'consultation', 'from_visit' => $own->visit_number])
            ->assertSessionHasNoErrors();
        $this->assertSame('board', $this->lastCalledMetadata()['call_next_source']);
    }

    public function test_old_rows_and_unknown_sources_are_safe_on_the_audit_log_page(): void
    {
        $this->heldConsultation();
        $row = AuditLog::query()->where('event', 'queue.called')->latest('id')->firstOrFail();
        // Audit rows are append-only; the test adds the two edge shapes as new rows beside the real one.
        $copy = (array) DB::table('audit_logs')->where('id', $row->id)->first();
        unset($copy['id']);
        DB::table('audit_logs')->insert([...$copy, 'metadata' => null]);
        DB::table('audit_logs')->insert([...$copy, 'metadata' => json_encode(['via' => 'call_next', 'call_next_source' => 'somewhere_else'])]);

        $this->actingAs($this->actor('director'))->get(route('audit-logs.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('logs.data', /** @param Collection<int, array<string, mixed>> $logs */ function (Collection $logs): bool {
                $details = $logs->where('event', 'queue.called')->pluck('detail')->sort()->values()->all();

                return $details === [null, null, 'Via Call next'];
            }));
    }
}
