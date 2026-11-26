<?php

namespace Tests\Feature\HR;

use App\Domain\HR\Roster\Models\RosterVersion;
use App\Domain\HR\Roster\Services\RosterRoomLookup;
use App\Domain\Organisation\Models\Branch;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Visit\VisitTestCase;

/**
 * Room from the roster (owner, via B.E.R.N.I.S.S, 18 Nov 2026): the read Queue uses to fill a doctor's room for the day.
 * Approved weeks only, the shift covering the time (or today's nearest), consultation rooms only, nothing on approved
 * leave, and nothing written.
 */
class RosterRoomLookupTest extends VisitTestCase
{
    private CarbonImmutable $week;

    private User $hr;

    private User $director;

    private User $doctor;

    protected function setUp(): void
    {
        parent::setUp();
        // Next week's Monday in the branch time zone (never the UTC clock: BASELINE section 6 item 21).
        $this->week = CarbonImmutable::now($this->branch->timezone)->startOfDay()->addWeek()->startOfWeek(CarbonImmutable::MONDAY);
        $this->hr = $this->actor('hr_manager');
        $this->director = $this->actor('director');
        $this->doctor = $this->actor('resident_doctor');
    }

    public function test_the_covering_shift_wins_and_the_second_shift_takes_over_when_it_starts(): void
    {
        $morning = $this->room('consultation');
        $afternoon = $this->room('consultation');
        $this->approvedWeek(function (RosterVersion $v) use ($morning, $afternoon): void {
            $this->addShift($v, $this->doctor, 0, '08:00', '12:00', $morning);
            $this->addShift($v, $this->doctor, 0, '14:00', '18:00', $afternoon);
        });
        $otherDoctor = $this->actor('resident_doctor');
        $before = $this->writes();

        $this->assertSame([$morning, '08:00'], $this->lookup('0 09:30'));
        $this->assertSame([$afternoon, '14:00'], $this->lookup('0 14:00'));
        $this->assertSame([$afternoon, '14:00'], $this->lookup('0 17:59'));
        // Between shifts and before or after them: today's nearest shift.
        $this->assertSame([$morning, '08:00'], $this->lookup('0 12:30'));
        $this->assertSame([$afternoon, '14:00'], $this->lookup('0 13:45'));
        $this->assertSame([$morning, '08:00'], $this->lookup('0 06:00'));
        $this->assertSame([$afternoon, '14:00'], $this->lookup('0 22:00'));
        // Another day, another branch or another doctor: nothing.
        $this->assertNull($this->lookup('1 09:30'));
        $puchong = Branch::query()->where('organisation_id', $this->organisation->id)->where('code', 'PUCHONG')->firstOrFail();
        $this->assertNull(app(RosterRoomLookup::class)->roomFor($this->doctor->id, $puchong, $this->at('0 09:30')));
        $this->assertNull(app(RosterRoomLookup::class)->roomFor($otherDoctor->id, $this->branch, $this->at('0 09:30')));
        $this->assertSame($before, $this->writes());
    }

    public function test_drafts_waiting_weeks_and_replaced_versions_never_count(): void
    {
        $first = $this->room('consultation');
        $second = $this->room('consultation');
        $draft = $this->startDraft();
        $this->addShift($draft, $this->doctor, 0, '08:00', '12:00', $first);
        $this->assertNull($this->lookup('0 09:00'));
        $this->post(route('hr.roster.submit', $draft->public_id), ['expected_version_lock' => $draft->refresh()->lock_version, 'idempotency_key' => (string) Str::uuid()])
            ->assertSessionHasNoErrors();
        $this->assertNull($this->lookup('0 09:00'));
        $this->approve($draft);
        $this->assertSame([$first, '08:00'], $this->lookup('0 09:00'));

        // "Change this week": until the new version is approved the old room stands; afterwards the new one does.
        $this->selectBranch($this->hr);
        $this->post(route('hr.roster.drafts.store'), ['branch_id' => $this->branch->id, 'week' => $this->week->toDateString(), 'mode' => 'change', 'idempotency_key' => (string) Str::uuid()])
            ->assertSessionHasNoErrors();
        $change = RosterVersion::query()->where('branch_id', $this->branch->id)->where('status', 'draft')->latest('id')->firstOrFail();
        $shift = DB::table('hr_roster_shifts')->where('roster_version_id', $change->id)->sole();
        $this->put(route('hr.roster.shifts.update', [$change->public_id, $shift->public_id]), [
            'staff_user_id' => $this->doctor->id, 'date' => $this->week->toDateString(), 'start_time' => '08:00', 'end_time' => '12:00', 'break_minutes' => 0,
            'session_type' => 'morning', 'branch_room_id' => $second, 'expected_version_lock' => $change->refresh()->lock_version,
            'expected_lock_version' => $shift->lock_version, 'idempotency_key' => (string) Str::uuid(),
        ])->assertSessionHasNoErrors();
        $this->assertSame([$first, '08:00'], $this->lookup('0 09:00'));
        $this->post(route('hr.roster.submit', $change->public_id), ['expected_version_lock' => $change->refresh()->lock_version, 'idempotency_key' => (string) Str::uuid()])
            ->assertSessionHasNoErrors();
        $this->approve($change);
        $this->assertSame('superseded', $draft->refresh()->status);
        $this->assertSame([$second, '08:00'], $this->lookup('0 09:00'));
    }

    public function test_no_room_another_kind_of_room_or_an_inactive_room_returns_null(): void
    {
        $treatment = $this->room('treatment');
        $closed = $this->room('consultation');
        $this->approvedWeek(function (RosterVersion $v) use ($treatment, $closed): void {
            $this->addShift($v, $this->doctor, 0, '08:00', '12:00', null);
            $this->addShift($v, $this->doctor, 1, '08:00', '12:00', $treatment);
            $this->addShift($v, $this->doctor, 2, '08:00', '12:00', $closed);
        });
        DB::table('branch_rooms')->where('id', $closed)->update(['is_active' => false]);

        $this->assertNull($this->lookup('0 09:00'));
        $this->assertNull($this->lookup('1 09:00'));
        $this->assertNull($this->lookup('2 09:00'));
    }

    public function test_a_shift_on_approved_leave_is_skipped(): void
    {
        $room = $this->room('consultation');
        $this->approvedWeek(fn (RosterVersion $v) => $this->addShift($v, $this->doctor, 0, '08:00', '12:00', $room));
        $this->assertSame([$room, '08:00'], $this->lookup('0 09:00'));

        // Leave approved after the week (the planner only warns about it): the shift no longer gives a room.
        $day = $this->week->toDateString();
        $id = $this->leave($day, 'full', '00:00', $this->week->addDay()->toDateString().' 00:00:00', approved: false);
        $this->assertSame([$room, '08:00'], $this->lookup('0 09:00')); // Pending leave does not count.
        DB::table('hr_leave_requests')->where('id', $id)->update(['status' => 'approved', 'decided_by_user_id' => $this->director->id, 'decided_at' => now()]);
        $this->assertNull($this->lookup('0 09:00'));
    }

    public function test_a_tie_goes_to_the_shift_still_to_come(): void
    {
        $earlier = $this->room('consultation');
        $later = $this->room('consultation');
        $this->approvedWeek(function (RosterVersion $v) use ($earlier, $later): void {
            $this->addShift($v, $this->doctor, 0, '08:00', '11:00', $earlier);
            $this->addShift($v, $this->doctor, 0, '13:00', '17:00', $later);
        });

        $this->assertSame([$later, '13:00'], $this->lookup('0 12:00'));
        $this->assertSame([$earlier, '08:00'], $this->lookup('0 11:59'));
    }

    public function test_an_overnight_shift_that_began_yesterday_counts_and_yesterdays_leave_skips_it(): void
    {
        $room = $this->room('consultation');
        $this->approvedWeek(fn (RosterVersion $v) => $this->addShift($v, $this->doctor, 1, '20:00', '02:00', $room));

        $this->assertSame([$room, '20:00'], $this->lookup('2 01:00'));
        $this->assertSame([$room, '20:00'], $this->lookup('1 23:00'));

        // Leave on the day the shift began (approved later, so the planner only warns) skips it on the next day too.
        $this->leave($this->week->addDay()->toDateString(), 'full', '00:00', $this->week->addDays(2)->toDateString().' 00:00:00');
        $this->assertNull($this->lookup('2 01:00'));
    }

    public function test_a_half_day_of_leave_skips_only_the_shift_it_overlaps(): void
    {
        $morning = $this->room('consultation');
        $afternoon = $this->room('consultation');
        $this->approvedWeek(function (RosterVersion $v) use ($morning, $afternoon): void {
            $this->addShift($v, $this->doctor, 0, '08:00', '12:00', $morning);
            $this->addShift($v, $this->doctor, 0, '14:00', '18:00', $afternoon);
        });
        $day = $this->week->toDateString();
        $this->leave($day, 'am', '00:00', "{$day} 12:00:00");

        // The morning shift is on leave, so even at 09:00 the afternoon shift is the nearest one left.
        $this->assertSame([$afternoon, '14:00'], $this->lookup('0 09:00'));
        $this->assertSame([$afternoon, '14:00'], $this->lookup('0 15:00'));
    }

    // ---- fixture ----

    /** @return array{0: int, 1: string}|null Room id and the shift's branch-local start time. */
    private function lookup(string $when): ?array
    {
        $found = app(RosterRoomLookup::class)->roomFor($this->doctor->id, $this->branch, $this->at($when));

        return $found === null ? null : [$found['roomId'], $found['shiftStartedAt']->setTimezone($this->branch->timezone)->format('H:i')];
    }

    public function test_batch_roster_lookup_has_constant_queries_for_one_and_twenty_six_doctors(): void
    {
        $doctors = [$this->doctor];
        for ($i = 0; $i < 25; $i++) {
            $doctors[] = $this->actor('resident_doctor');
        }
        $this->approvedWeek(function (RosterVersion $version) use ($doctors): void {
            foreach ($doctors as $doctor) {
                $this->addShift($version, $doctor, 0, '08:00', '12:00', $this->room('consultation'));
            }
        });
        $lookup = app(RosterRoomLookup::class);
        $at = $this->at('0 09:00');
        DB::enableQueryLog();
        DB::flushQueryLog();
        $one = $lookup->roomsFor([$this->doctor->id], $this->branch, $at);
        $oneCount = count(DB::getQueryLog());
        DB::flushQueryLog();
        $all = $lookup->roomsFor(array_map(fn (User $doctor): int => $doctor->id, $doctors), $this->branch, $at);
        $allCount = count(DB::getQueryLog());
        DB::disableQueryLog();
        $this->assertCount(26, $all);
        $this->assertSame($oneCount, $allCount);
        $this->assertLessThanOrEqual(5, $allCount);
        $this->assertEquals($one[$this->doctor->id], $all[$this->doctor->id]);
        foreach ($doctors as $doctor) {
            $this->assertEquals($lookup->roomFor($doctor->id, $this->branch, $at), $all[$doctor->id]);
        }
    }

    public function test_batch_roster_lookup_preserves_overnight_and_leave_selection(): void
    {
        $this->approvedWeek(function (RosterVersion $version): void {
            $this->addShift($version, $this->doctor, 0, '08:00', '12:00', $this->room('consultation'));
            $this->addShift($version, $this->doctor, 0, '14:00', '18:00', $this->room('consultation'));
            $this->addShift($version, $this->doctor, 1, '22:00', '06:00', $this->room('consultation'));
        });
        $lookup = app(RosterRoomLookup::class);
        $check = function () use ($lookup): void {
            foreach (['0 09:00', '0 13:00', '0 14:00', '2 01:00', '2 06:00'] as $time) {
                $at = $this->at($time);
                $this->assertEquals($lookup->roomFor($this->doctor->id, $this->branch, $at), $lookup->roomsFor([$this->doctor->id], $this->branch, $at)[$this->doctor->id] ?? null);
            }
        };
        $check();
        $this->leave($this->week->toDateString(), 'am', '00:00', $this->week->toDateString().' 12:00:00');
        $this->leave($this->week->addDay()->toDateString(), 'full', '00:00', $this->week->addDays(2)->toDateString().' 00:00:00');
        $check();
    }

    /** "<day offset> <HH:MM>" in the branch time zone. */
    private function at(string $when): CarbonImmutable
    {
        [$day, $time] = explode(' ', $when);

        return CarbonImmutable::parse($this->week->addDays((int) $day)->toDateString().' '.$time, $this->branch->timezone);
    }

    private function approvedWeek(callable $shifts): RosterVersion
    {
        $version = $this->startDraft();
        $shifts($version);
        $this->post(route('hr.roster.submit', $version->public_id), ['expected_version_lock' => $version->refresh()->lock_version, 'idempotency_key' => (string) Str::uuid()])
            ->assertSessionHasNoErrors();
        $this->approve($version);

        return $version;
    }

    private function startDraft(): RosterVersion
    {
        $this->selectBranch($this->hr);
        $this->post(route('hr.roster.drafts.store'), ['branch_id' => $this->branch->id, 'week' => $this->week->toDateString(), 'mode' => 'blank', 'idempotency_key' => (string) Str::uuid()])
            ->assertSessionHasNoErrors();

        return RosterVersion::query()->where('branch_id', $this->branch->id)->where('status', 'draft')->latest('id')->firstOrFail();
    }

    private function addShift(RosterVersion $version, User $subject, int $day, string $start, string $end, ?int $room): void
    {
        $this->selectBranch($this->hr);
        $this->post(route('hr.roster.shifts.store', $version->public_id), [
            'staff_user_id' => $subject->id, 'date' => $this->week->addDays($day)->toDateString(), 'start_time' => $start, 'end_time' => $end,
            'break_minutes' => 0, 'session_type' => 'morning', 'branch_room_id' => $room, 'branch_id' => $this->branch->id,
            'expected_version_lock' => $version->refresh()->lock_version, 'idempotency_key' => (string) Str::uuid(),
        ])->assertSessionHasNoErrors();
    }

    private function approve(RosterVersion $version): void
    {
        $this->selectBranch($this->director);
        $this->post(route('hr.roster.approve', $version->public_id), ['expected_version_lock' => $version->refresh()->lock_version, 'idempotency_key' => (string) Str::uuid()])
            ->assertSessionHasNoErrors();
        $this->assertSame('approved', $version->refresh()->status);
    }

    private function room(string $kind): int
    {
        return (int) DB::table('branch_rooms')->insertGetId(['organisation_id' => $this->organisation->id, 'branch_id' => $this->branch->id, 'kind' => $kind,
            'name' => 'Synthetic Room '.Str::random(4), 'sort_order' => 1, 'is_active' => true, 'lock_version' => 1, 'updated_by_user_id' => $this->hr->id,
            'created_at' => now(), 'updated_at' => now()]);
    }

    /** Synthetic leave at this branch: `$part` full or am, slots in branch-local wall time. */
    private function leave(string $day, string $part, string $from, string $slotEnd, bool $approved = true): int
    {
        $type = DB::table('hr_leave_types')->where('organisation_id', $this->organisation->id)->where('code', 'AL')->value('id')
            ?? DB::table('hr_leave_types')->insertGetId(['organisation_id' => $this->organisation->id, 'code' => 'AL', 'name' => 'Synthetic annual', 'is_paid' => true,
                'uses_balance' => false, 'allow_half_days' => true, 'evidence_needed' => false, 'updated_by_user_id' => $this->hr->id, 'created_at' => now(), 'updated_at' => now()]);
        $end = substr($slotEnd, 0, 10);
        $id = DB::table('hr_leave_requests')->insertGetId(['public_id' => (string) Str::uuid(), 'organisation_id' => $this->organisation->id, 'user_id' => $this->doctor->id,
            'leave_type_id' => $type, 'branch_id' => $this->branch->id, 'start_date' => $day, 'end_date' => $part === 'am' ? $day : max($day, CarbonImmutable::parse($end)->subDay()->toDateString()),
            'start_part' => $part === 'am' ? 'full' : 'full', 'end_part' => $part === 'am' ? 'am' : 'full',
            'slot_start' => "{$day} {$from}:00", 'slot_end' => $slotEnd, 'year' => (int) substr($day, 0, 4), 'halves' => $part === 'am' ? 1 : 2,
            'status' => 'pending', 'created_at' => now(), 'updated_at' => now()]);
        if ($approved) {
            DB::table('hr_leave_requests')->where('id', $id)->update(['status' => 'approved', 'decided_by_user_id' => $this->director->id, 'decided_at' => now()]);
        }

        return (int) $id;
    }

    /** @return array<string, int> The lookup writes nothing: no audit, no room assignment, no roster change. */
    private function writes(): array
    {
        return ['audit' => DB::table('audit_logs')->count(), 'rooms' => DB::table('doctor_room_assignments')->count(),
            'shifts' => DB::table('hr_roster_shifts')->count(), 'versions' => DB::table('hr_roster_versions')->count()];
    }
}
