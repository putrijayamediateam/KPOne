<?php

namespace Tests\Feature\HR;

use App\Domain\Access\StaffKind;
use App\Domain\HR\Roster\Models\RosterVersion;
use App\Domain\HR\Roster\OnDutyShift;
use App\Domain\HR\Roster\Services\RosterOnDuty;
use App\Domain\Organisation\Models\Branch;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Visit\VisitTestCase;

/**
 * HR-22a (HR-PLAN 29.2): who is on the approved roster at a branch, for AUTO-1 and the CA's doctor list. Approved weeks
 * only, on call and inactive accounts left out, approved leave removed (a moment: leave covering it; a day: a shift wholly
 * covered), ids and role names only, and nothing written.
 */
class RosterOnDutyTest extends VisitTestCase
{
    private CarbonImmutable $week;

    private User $hr;

    private User $director;

    protected function setUp(): void
    {
        parent::setUp();
        // Next week's Monday in the branch time zone (never the UTC clock: BASELINE section 6 item 21).
        $this->week = CarbonImmutable::now($this->branch->timezone)->startOfDay()->addWeek()->startOfWeek(CarbonImmutable::MONDAY);
        $this->hr = $this->actor('hr_manager');
        $this->director = $this->actor('director');
    }

    public function test_a_week_is_published_only_once_a_version_is_approved_even_on_a_day_with_no_shift(): void
    {
        $doctor = $this->actor('resident_doctor');
        $service = app(RosterOnDuty::class);
        $this->assertFalse($service->isPublished($this->branch, $this->at('0 09:00')));

        $draft = $this->startDraft();
        $this->addShift($draft, $doctor, 0, '08:00', '12:00');
        $this->assertFalse($service->isPublished($this->branch, $this->at('0 09:00')), 'a draft is not published');
        $this->submit($draft);
        $this->assertFalse($service->isPublished($this->branch, $this->at('0 09:00')), 'a week waiting for approval is not published');
        $this->approve($draft);

        foreach (['0 00:00', '0 09:00', '3 15:00', '6 23:59'] as $when) {
            $this->assertTrue($service->isPublished($this->branch, $this->at($when)), $when);
        }
        $this->assertSame([], $service->onDutyOn($this->branch, $this->at('3 00:00')), 'published, but nobody on that day');
        $this->assertFalse($service->isPublished($this->branch, $this->at('7 09:00')), 'the next week');
        $this->assertFalse($service->isPublished($this->branch, $this->at('-1 09:00')), 'the week before');
        $puchong = Branch::query()->where('organisation_id', $this->organisation->id)->where('code', 'PUCHONG')->firstOrFail();
        $this->assertFalse($service->isPublished($puchong, $this->at('0 09:00')), 'another branch');
    }

    public function test_who_is_on_duty_now_with_roles_room_and_times_and_nothing_written(): void
    {
        $doctor = $this->actor('resident_doctor');
        $ca = $this->actor('ca');
        $room = $this->room('consultation');
        $dispensary = $this->room('dispensary');
        $this->approvedWeek(function (RosterVersion $v) use ($doctor, $ca, $room, $dispensary): void {
            $this->addShift($v, $doctor, 0, '08:00', '12:00', $room);
            $this->addShift($v, $ca, 0, '09:00', '17:00', $dispensary);
        });
        $service = app(RosterOnDuty::class);
        $before = $this->writes();

        $now = $service->onDutyNow($this->branch, $this->at('0 10:00'));
        $this->assertSame([$doctor->id, $ca->id], array_map(fn (OnDutyShift $s): int => $s->userId, $now));
        $this->assertSame(['resident_doctor'], $now[0]->roles);
        $this->assertTrue($now[0]->isDoctor);
        $this->assertFalse($now[0]->isLocum);
        $this->assertSame($room, $now[0]->roomId);
        $this->assertSame($dispensary, $now[1]->roomId, 'any active room of the branch, not only consultation');
        $this->assertFalse($now[1]->isDoctor);
        $this->assertNull($now[0]->station, 'no station until HR-22c');
        $this->assertSame('08:00', $now[0]->startsAt->setTimezone($this->branch->timezone)->format('H:i'));
        $this->assertSame('12:00', $now[0]->endsAt->setTimezone($this->branch->timezone)->format('H:i'));
        $this->assertSame('UTC', $now[0]->startsAt->getTimezone()->getName());

        $this->assertSame([$ca->id], $this->ids($service->onDutyNow($this->branch, $this->at('0 13:00'))));
        $this->assertTrue($service->isOnDuty($doctor->id, $this->branch, $this->at('0 11:59')));
        $this->assertFalse($service->isOnDuty($doctor->id, $this->branch, $this->at('0 12:00')), 'a shift ends at its end time');
        $this->assertFalse($service->isOnDuty($doctor->id, $this->branch, $this->at('1 10:00')), 'another day');
        $puchong = Branch::query()->where('organisation_id', $this->organisation->id)->where('code', 'PUCHONG')->firstOrFail();
        $this->assertSame([], $service->onDutyNow($puchong, $this->at('0 10:00')), 'another branch');
        $this->assertFalse($service->isOnDuty($doctor->id, $puchong, $this->at('0 10:00')));
        $this->assertSame([$doctor->id, $ca->id], $this->ids($service->onDutyOn($this->branch, $this->at('0 00:00'))));
        $this->assertSame($before, $this->writes());
    }

    public function test_drafts_and_a_change_waiting_for_approval_never_count_until_approved(): void
    {
        $doctor = $this->actor('resident_doctor');
        $other = $this->actor('resident_doctor');
        $first = $this->approvedWeek(fn (RosterVersion $v) => $this->addShift($v, $doctor, 0, '08:00', '12:00'));
        $service = app(RosterOnDuty::class);

        // "Change this week": the new draft adds an afternoon doctor; until it is approved the old week stands.
        $this->selectBranch($this->hr);
        $this->post(route('hr.roster.drafts.store'), ['branch_id' => $this->branch->id, 'week' => $this->week->toDateString(), 'mode' => 'change', 'idempotency_key' => (string) Str::uuid()])
            ->assertSessionHasNoErrors();
        $change = RosterVersion::query()->where('branch_id', $this->branch->id)->where('status', 'draft')->latest('id')->firstOrFail();
        $this->addShift($change, $other, 0, '13:00', '17:00', null, 'afternoon');
        $this->assertSame([], $service->onDutyNow($this->branch, $this->at('0 14:00')), 'a draft never counts');
        $this->submit($change);
        $this->assertSame([], $service->onDutyNow($this->branch, $this->at('0 14:00')), 'nor a change waiting for approval');
        $this->assertSame([$doctor->id], $this->ids($service->onDutyNow($this->branch, $this->at('0 09:00'))));

        $this->approve($change);
        $this->assertSame('superseded', $first->refresh()->status);
        $this->assertSame([$other->id], $this->ids($service->onDutyNow($this->branch, $this->at('0 14:00'))));
        $this->assertSame([$doctor->id], $this->ids($service->onDutyNow($this->branch, $this->at('0 09:00'))), 'the copied shift, now from the new version');
        $this->assertTrue($service->isPublished($this->branch, $this->at('0 09:00')));
    }

    public function test_on_call_and_inactive_accounts_are_never_on_duty(): void
    {
        $doctor = $this->actor('resident_doctor');
        $onCall = $this->actor('resident_doctor');
        $leaver = $this->actor('ca');
        $this->approvedWeek(function (RosterVersion $v) use ($doctor, $onCall, $leaver): void {
            $this->addShift($v, $doctor, 0, '08:00', '17:00');
            $this->addShift($v, $onCall, 0, '08:00', '20:00', null, 'on_call');
            $this->addShift($v, $leaver, 0, '08:00', '17:00');
        });
        DB::table('users')->where('id', $leaver->id)->update(['is_active' => false]);
        $service = app(RosterOnDuty::class);

        $this->assertSame([$doctor->id], $this->ids($service->onDutyNow($this->branch, $this->at('0 10:00'))));
        $this->assertSame([$doctor->id], $this->ids($service->onDutyOn($this->branch, $this->at('0 00:00'))));
        $this->assertFalse($service->isOnDuty($onCall->id, $this->branch, $this->at('0 10:00')));
        $this->assertFalse($service->isOnDuty($leaver->id, $this->branch, $this->at('0 10:00')));
        $this->assertSame([$onCall->id], $this->ids($service->onCallNowForHrDashboard($this->branch, $this->at('0 10:00'))), 'on call has its own answer for HR');
    }

    public function test_leave_covering_the_moment_removes_a_person_and_only_a_wholly_covered_shift_leaves_the_day(): void
    {
        $morningOff = $this->actor('resident_doctor');
        $dayOff = $this->actor('resident_doctor');
        $this->approvedWeek(function (RosterVersion $v) use ($morningOff, $dayOff): void {
            $this->addShift($v, $morningOff, 0, '08:00', '17:00');
            $this->addShift($v, $dayOff, 0, '08:00', '17:00');
        });
        $day = $this->week->toDateString();
        $this->leave($morningOff, $day, 'am', '00:00', "{$day} 13:00:00");
        $this->leave($dayOff, $day, 'full', '00:00', $this->week->addDay()->toDateString().' 00:00:00');
        $this->leave($dayOff, $this->week->addDay()->toDateString(), 'full', '00:00', $this->week->addDays(2)->toDateString().' 00:00:00', approved: false);
        $service = app(RosterOnDuty::class);

        $this->assertSame([], $service->onDutyNow($this->branch, $this->at('0 09:00')), 'both are on leave at 09:00');
        $this->assertSame([$morningOff->id], $this->ids($service->onDutyNow($this->branch, $this->at('0 14:00'))), 'back for the afternoon');
        $this->assertFalse($service->isOnDuty($morningOff->id, $this->branch, $this->at('0 12:59')));
        $this->assertTrue($service->isOnDuty($morningOff->id, $this->branch, $this->at('0 13:00')));
        // The day: the half day still shows (they work the afternoon); the full day does not.
        $this->assertSame([$morningOff->id], $this->ids($service->onDutyOn($this->branch, $this->at('0 00:00'))));
    }

    public function test_two_half_days_that_together_cover_a_shift_take_it_off_the_day(): void
    {
        $doctor = $this->actor('resident_doctor');
        $this->approvedWeek(fn (RosterVersion $v) => $this->addShift($v, $doctor, 0, '08:00', '17:00'));
        $day = $this->week->toDateString();
        $this->leave($doctor, $day, 'am', '00:00', "{$day} 13:00:00");
        $this->leave($doctor, $day, 'am', '13:00', $this->week->addDay()->toDateString().' 00:00:00');

        $this->assertSame([], app(RosterOnDuty::class)->onDutyOn($this->branch, $this->at('0 00:00')));
    }

    public function test_only_the_hr_dashboard_asks_for_on_call(): void
    {
        $callers = array_values(array_filter(array_map(fn (string $f): string => str_replace(base_path().'/', '', $f), array_merge(
            glob(app_path('Domain/*/*.php')) ?: [], glob(app_path('Domain/*/*/*.php')) ?: [], glob(app_path('Domain/*/*/*/*.php')) ?: [],
            glob(app_path('Domain/*/*/*/*/*.php')) ?: [], glob(app_path('Http/Controllers/*.php')) ?: [], glob(app_path('Http/Controllers/*/*.php')) ?: [],
        )), fn (string $f): bool => str_contains((string) file_get_contents(base_path($f)), 'onCallNowForHrDashboard(')));
        sort($callers);

        $this->assertSame(['app/Domain/HR/Dashboard/HrDashboardService.php', 'app/Domain/HR/Roster/Services/RosterOnDuty.php'], $callers);
    }

    public function test_pending_leave_changes_nothing(): void
    {
        $doctor = $this->actor('resident_doctor');
        $this->approvedWeek(fn (RosterVersion $v) => $this->addShift($v, $doctor, 0, '08:00', '17:00'));
        $day = $this->week->toDateString();
        $this->leave($doctor, $day, 'full', '00:00', $this->week->addDay()->toDateString().' 00:00:00', approved: false);
        $service = app(RosterOnDuty::class);

        $this->assertTrue($service->isOnDuty($doctor->id, $this->branch, $this->at('0 10:00')));
        $this->assertSame([$doctor->id], $this->ids($service->onDutyOn($this->branch, $this->at('0 00:00'))));
    }

    public function test_an_overnight_shift_counts_on_both_days_and_touching_shifts_hand_over_at_the_boundary(): void
    {
        $night = $this->actor('resident_doctor');
        $early = $this->actor('ca');
        $this->approvedWeek(function (RosterVersion $v) use ($night, $early): void {
            $this->addShift($v, $night, 0, '22:00', '06:00', null, 'night');
            $this->addShift($v, $early, 1, '08:00', '12:00');
            $this->addShift($v, $early, 1, '12:00', '16:00', null, 'afternoon');
        });
        $service = app(RosterOnDuty::class);

        $this->assertSame([$night->id], $this->ids($service->onDutyOn($this->branch, $this->at('0 00:00'))));
        $this->assertSame([$night->id, $early->id, $early->id], $this->ids($service->onDutyOn($this->branch, $this->at('1 00:00'))));
        $this->assertTrue($service->isOnDuty($night->id, $this->branch, $this->at('1 05:59')));
        $this->assertFalse($service->isOnDuty($night->id, $this->branch, $this->at('1 06:00')));
        $atNoon = $service->onDutyNow($this->branch, $this->at('1 12:00'));
        $this->assertCount(1, $atNoon, 'at the boundary only the shift that starts counts');
        $this->assertSame('12:00', $atNoon[0]->startsAt->setTimezone($this->branch->timezone)->format('H:i'));
    }

    public function test_a_locum_doctor_is_marked_and_rooms_of_another_branch_or_inactive_rooms_are_ignored(): void
    {
        $locumDoctor = $this->actor('resident_doctor');
        $locumDoctor->assignRole('locum');
        $locumCa = $this->actor('ca');
        $locumCa->assignRole('locum');
        $resident = $this->actor('resident_doctor');
        $closed = $this->room('consultation');
        $this->approvedWeek(function (RosterVersion $v) use ($locumDoctor, $locumCa, $resident, $closed): void {
            $this->addShift($v, $locumDoctor, 0, '08:00', '12:00');
            $this->addShift($v, $locumCa, 0, '08:00', '12:00');
            $this->addShift($v, $resident, 0, '08:00', '12:00', $closed);
        });
        DB::table('branch_rooms')->where('id', $closed)->update(['is_active' => false]);
        $service = app(RosterOnDuty::class);

        $now = collect($service->onDutyNow($this->branch, $this->at('0 09:00')))->keyBy('userId');
        $this->assertTrue($now[$locumDoctor->id]->isLocum);
        $this->assertTrue($now[$locumDoctor->id]->isDoctor);
        $this->assertSame(['locum', 'resident_doctor'], $now[$locumDoctor->id]->roles);
        $this->assertTrue($now[$locumCa->id]->isLocum);
        $this->assertFalse($now[$locumCa->id]->isDoctor);
        $this->assertFalse($now[$resident->id]->isLocum);
        $this->assertNull($now[$resident->id]->roomId, 'an inactive room is not returned');
        $this->assertTrue(StaffKind::isLocum($locumDoctor->refresh()));
        $this->assertTrue(StaffKind::isDoctor($locumDoctor));
        $this->assertFalse(StaffKind::isLocum($resident->refresh()));
    }

    public function test_the_answers_are_batched_not_one_query_per_person(): void
    {
        $people = [];
        for ($i = 0; $i < 6; $i++) {
            $people[] = $this->actor($i % 2 === 0 ? 'resident_doctor' : 'ca');
        }
        $this->approvedWeek(function (RosterVersion $v) use ($people): void {
            foreach ($people as $person) {
                $this->addShift($v, $person, 0, '08:00', '17:00', $this->room('consultation'));
            }
        });
        $service = app(RosterOnDuty::class);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->assertCount(6, $service->onDutyNow($this->branch, $this->at('0 10:00')));
        $now = count(DB::getQueryLog());
        DB::flushQueryLog();
        $this->assertCount(6, $service->onDutyOn($this->branch, $this->at('0 00:00')));
        $day = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThanOrEqual(5, $now);
        $this->assertLessThanOrEqual(6, $day);
    }

    // ---- fixture ----

    /**
     * @param  list<OnDutyShift>  $shifts
     * @return list<int>
     */
    private function ids(array $shifts): array
    {
        return array_map(fn (OnDutyShift $s): int => $s->userId, $shifts);
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
        $this->submit($version);
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

    private function addShift(RosterVersion $version, User $subject, int $day, string $start, string $end, ?int $room = null, string $session = 'morning'): void
    {
        $this->selectBranch($this->hr);
        $this->post(route('hr.roster.shifts.store', $version->public_id), [
            'staff_user_id' => $subject->id, 'date' => $this->week->addDays($day)->toDateString(), 'start_time' => $start, 'end_time' => $end,
            'break_minutes' => 0, 'session_type' => $session, 'branch_room_id' => $room, 'branch_id' => $this->branch->id,
            'expected_version_lock' => $version->refresh()->lock_version, 'idempotency_key' => (string) Str::uuid(),
        ])->assertSessionHasNoErrors();
    }

    private function submit(RosterVersion $version): void
    {
        $this->selectBranch($this->hr);
        $this->post(route('hr.roster.submit', $version->public_id), ['expected_version_lock' => $version->refresh()->lock_version, 'idempotency_key' => (string) Str::uuid()])
            ->assertSessionHasNoErrors();
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
    private function leave(User $person, string $day, string $part, string $from, string $slotEnd, bool $approved = true): void
    {
        $type = DB::table('hr_leave_types')->where('organisation_id', $this->organisation->id)->where('code', 'AL')->value('id')
            ?? DB::table('hr_leave_types')->insertGetId(['organisation_id' => $this->organisation->id, 'code' => 'AL', 'name' => 'Synthetic annual', 'is_paid' => true,
                'uses_balance' => false, 'allow_half_days' => true, 'evidence_needed' => false, 'updated_by_user_id' => $this->hr->id, 'created_at' => now(), 'updated_at' => now()]);
        $end = substr($slotEnd, 0, 10);
        $id = DB::table('hr_leave_requests')->insertGetId(['public_id' => (string) Str::uuid(), 'organisation_id' => $this->organisation->id, 'user_id' => $person->id,
            'leave_type_id' => $type, 'branch_id' => $this->branch->id, 'start_date' => $day, 'end_date' => $part === 'am' ? $day : max($day, CarbonImmutable::parse($end)->subDay()->toDateString()),
            'start_part' => 'full', 'end_part' => $part === 'am' ? 'am' : 'full',
            'slot_start' => "{$day} {$from}:00", 'slot_end' => $slotEnd, 'year' => (int) substr($day, 0, 4), 'halves' => $part === 'am' ? 1 : 2,
            'status' => 'pending', 'created_at' => now(), 'updated_at' => now()]);
        if ($approved) {
            DB::table('hr_leave_requests')->where('id', $id)->update(['status' => 'approved', 'decided_by_user_id' => $this->director->id, 'decided_at' => now()]);
        }
    }

    /** @return array<string, int> The lookup writes nothing. */
    private function writes(): array
    {
        return ['audit' => DB::table('audit_logs')->count(), 'shifts' => DB::table('hr_roster_shifts')->count(), 'versions' => DB::table('hr_roster_versions')->count()];
    }
}
