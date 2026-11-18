<?php

namespace Tests\Feature\Queue;

use App\Domain\HR\Roster\Models\RosterVersion;
use App\Domain\Queue\Display\DoctorRoomService;
use App\Domain\Queue\Models\QueueCall;
use App\Domain\Queue\Services\QueueEntryService;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Q-10 (owner decision 2026-11-18, via B.E.R.N.I.S.S): the doctor's room for the day comes from the approved roster shift they are
 * on, and the doctor can still pick a room by hand. Order: a hand choice made at or after the start of the current shift, then that
 * shift's room, then an older hand choice, then none. Queue only calls HR's RosterRoomLookup; it reads no hr_ table.
 */
class DoctorRoomRosterTest extends QueueTestCase
{
    private CarbonImmutable $week;

    private User $hr;

    private User $director;

    private User $doctor;

    private int $morning;

    private int $afternoon;

    protected function setUp(): void
    {
        parent::setUp();
        // Next week's Monday in the branch time zone (never the UTC clock: BASELINE section 6 item 21).
        $this->week = CarbonImmutable::now($this->branch->timezone)->startOfDay()->addWeek()->startOfWeek(CarbonImmutable::MONDAY);
        $this->hr = $this->actor('hr_manager');
        $this->director = $this->actor('director');
        $this->doctor = $this->doctor();
        $this->morning = $this->room('Synthetic Morning Room');
        $this->afternoon = $this->room('Synthetic Afternoon Room');
        $this->approvedWeek(function (RosterVersion $version): void {
            $this->addShift($version, $this->doctor, '08:00', '12:00', $this->morning);
            $this->addShift($version, $this->doctor, '14:00', '18:00', $this->afternoon);
        });
    }

    protected function tearDown(): void
    {
        Date::setTestNow();
        parent::tearDown();
    }

    public function test_the_room_comes_from_the_shift_the_doctor_is_on_and_switches_when_the_next_shift_starts(): void
    {
        $this->at('09:30');
        $this->assertSame([$this->morning, 'roster'], $this->room_now());
        $this->at('14:00');
        $this->assertSame([$this->afternoon, 'roster'], $this->room_now());
        // The doctor's own screen shows the same room and says where it came from.
        $this->assertSame($this->afternoon, app(DoctorRoomService::class)->choice($this->doctor)['currentRoomId']);
        $this->assertSame('roster', app(DoctorRoomService::class)->choice($this->doctor)['currentRoomSource']);
    }

    public function test_a_hand_choice_at_or_after_the_shift_start_wins_and_is_recorded(): void
    {
        $other = $this->room('Synthetic Other Room');
        $this->at('09:30');
        app(DoctorRoomService::class)->select($this->doctor, $other, $this->branch->id);

        $this->assertSame([$other, 'chosen'], $this->room_now());
        $this->assertDatabaseHas('audit_logs', ['event' => 'queue.room.selected', 'actor_user_id' => $this->doctor->id]);
    }

    public function test_a_hand_choice_made_before_the_current_shift_started_is_overridden_and_the_doctor_can_pick_again(): void
    {
        $this->at('09:30');
        app(DoctorRoomService::class)->select($this->doctor, $this->morning, $this->branch->id);
        $this->assertSame([$this->morning, 'chosen'], $this->room_now());

        $this->at('14:05');
        $this->assertSame([$this->afternoon, 'roster'], $this->room_now(), 'the afternoon shift took over');

        // Choosing the very room that was chosen this morning is a fresh choice now, so it wins over the afternoon shift again.
        app(DoctorRoomService::class)->select($this->doctor, $this->morning, $this->branch->id);
        $this->assertSame([$this->morning, 'chosen'], $this->room_now());
        $this->assertSame(2, DB::table('audit_logs')->where('event', 'queue.room.selected')->count());
    }

    public function test_no_approved_shift_or_a_shift_without_a_room_keeps_the_hand_choice_as_before(): void
    {
        $other = $this->room('Synthetic Other Room');
        $this->at('09:30', day: 1); // Tuesday: no shift
        $this->assertSame([null, null], $this->room_now());
        app(DoctorRoomService::class)->select($this->doctor, $other, $this->branch->id);
        $this->assertSame([$other, 'chosen'], $this->room_now());

        $stranger = $this->actor('resident_doctor');
        $this->at('09:30');
        $this->assertNull(app(DoctorRoomService::class)->roomFor($stranger->id, $this->branch, $this->week->toDateString()));
    }

    public function test_call_in_uses_the_roster_room_when_the_doctor_chose_none_and_records_it_for_the_tv(): void
    {
        $this->at('09:30');
        $ca = $this->actor('ca');
        $visit = $this->consultationVisit($ca, $this->doctor);
        $entry = $this->send($ca, $visit);
        $this->selectBranch($this->doctor);

        app(QueueEntryService::class)->call($this->doctor, $visit->refresh(), [
            'expected_branch_id' => $this->branch->id, 'visit_lock_version' => $visit->lock_version, 'queue_lock_version' => $entry->refresh()->lock_version,
        ]);

        $this->assertSame($this->morning, QueueCall::query()->where('queue_entry_id', $entry->id)->sole()->branch_room_id);
    }

    /** @return array{0: int|null, 1: string|null} */
    private function room_now(): array
    {
        $resolved = app(DoctorRoomService::class)->resolve($this->doctor->id, $this->branch, now()->setTimezone($this->branch->timezone)->toDateString());

        return [$resolved['room']?->id, $resolved['source']];
    }

    private function at(string $time, int $day = 0): void
    {
        Date::setTestNow(CarbonImmutable::parse($this->week->addDays($day)->toDateString().' '.$time, $this->branch->timezone));
    }

    private function approvedWeek(callable $shifts): void
    {
        $this->selectBranch($this->hr);
        $this->post(route('hr.roster.drafts.store'), ['branch_id' => $this->branch->id, 'week' => $this->week->toDateString(), 'mode' => 'blank', 'idempotency_key' => (string) Str::uuid()])
            ->assertSessionHasNoErrors();
        $version = RosterVersion::query()->where('branch_id', $this->branch->id)->where('status', 'draft')->latest('id')->firstOrFail();
        $shifts($version);
        $this->post(route('hr.roster.submit', $version->public_id), ['expected_version_lock' => $version->refresh()->lock_version, 'idempotency_key' => (string) Str::uuid()])
            ->assertSessionHasNoErrors();
        $this->selectBranch($this->director);
        $this->post(route('hr.roster.approve', $version->public_id), ['expected_version_lock' => $version->refresh()->lock_version, 'idempotency_key' => (string) Str::uuid()])
            ->assertSessionHasNoErrors();
        $this->assertSame('approved', $version->refresh()->status);
    }

    private function addShift(RosterVersion $version, User $subject, string $start, string $end, int $room): void
    {
        $this->selectBranch($this->hr);
        $this->post(route('hr.roster.shifts.store', $version->public_id), [
            'staff_user_id' => $subject->id, 'date' => $this->week->toDateString(), 'start_time' => $start, 'end_time' => $end,
            'break_minutes' => 0, 'session_type' => 'morning', 'branch_room_id' => $room, 'branch_id' => $this->branch->id,
            'expected_version_lock' => $version->refresh()->lock_version, 'idempotency_key' => (string) Str::uuid(),
        ])->assertSessionHasNoErrors();
    }

    private function room(string $name): int
    {
        return (int) DB::table('branch_rooms')->insertGetId(['organisation_id' => $this->organisation->id, 'branch_id' => $this->branch->id, 'kind' => 'consultation',
            'name' => $name, 'sort_order' => 1, 'is_active' => true, 'lock_version' => 1, 'updated_by_user_id' => $this->hr->id,
            'created_at' => now(), 'updated_at' => now()]);
    }
}
