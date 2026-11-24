<?php

namespace Tests\Feature\HR;

use App\Domain\HR\Roster\Models\RosterVersion;
use App\Domain\HR\Roster\Services\RosterOnDuty;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Visit\VisitTestCase;

/**
 * HR-22d (HR-PLAN 29.5; owner, via B.E.R.N.I.S.S, 24 Nov 2026): a person's own roster station today, on their own home.
 * Everyone still opens on their own home; the station is shown only when the branch plans stations. One person's own shift
 * only, approved weeks only, on call and fully covering leave left out. Synthetic data only.
 */
class RosterStationHomeTest extends VisitTestCase
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

    public function test_for_user_gives_the_shift_now_then_the_next_one_today_then_nothing(): void
    {
        $ca = $this->actor('ca');
        $other = $this->actor('ca');
        $version = $this->startDraft();
        $this->addShift($version, $ca, 0, '08:00', '12:00', 'registration');
        $this->addShift($version, $ca, 0, '14:00', '18:00', 'dispensary');
        $this->addShift($version, $other, 0, '07:00', '20:00', 'treatment');
        $this->addShift($version, $ca, 1, '08:00', '12:00', 'ma');
        $this->submit($version);
        $this->approve($version);
        $service = app(RosterOnDuty::class);

        $this->assertSame('registration', $service->forUser($ca->id, $this->branch, $this->at('0 07:00'))?->station, 'before the first shift: the first one');
        $this->assertSame('registration', $service->forUser($ca->id, $this->branch, $this->at('0 09:00'))?->station, 'during it');
        $this->assertSame('dispensary', $service->forUser($ca->id, $this->branch, $this->at('0 12:00'))?->station, 'a shift ending now is over');
        $this->assertSame('dispensary', $service->forUser($ca->id, $this->branch, $this->at('0 15:00'))?->station);
        $this->assertNull($service->forUser($ca->id, $this->branch, $this->at('0 18:30')), 'tomorrow\'s shift is not today\'s');
        $this->assertSame($ca->id, $service->forUser($ca->id, $this->branch, $this->at('0 09:00'))?->userId, 'only the person asked about');
    }

    public function test_drafts_on_call_and_full_leave_give_nothing_and_a_half_day_keeps_the_shift_worked(): void
    {
        $ca = $this->actor('ca');
        $draft = $this->startDraft();
        $this->addShift($draft, $ca, 0, '08:00', '12:00', 'registration');
        $service = app(RosterOnDuty::class);
        $this->assertNull($service->forUser($ca->id, $this->branch, $this->at('0 09:00')), 'a draft is not the roster');
        $this->addShift($draft, $ca, 1, '20:00', '08:00', null, 'on_call');
        $this->addShift($draft, $ca, 2, '08:00', '12:00', 'registration');
        $this->addShift($draft, $ca, 2, '14:00', '18:00', 'dispensary');
        $this->submit($draft);
        $this->approve($draft);

        $this->assertNull($service->forUser($ca->id, $this->branch, $this->at('1 21:00')), 'on call is never on duty');
        $day = $this->week->addDays(2)->toDateString();
        $this->leave($ca, $day, 'am', '00:00', "{$day} 13:00:00");
        $this->assertSame('dispensary', $service->forUser($ca->id, $this->branch, $this->at('2 07:00'))?->station, 'the morning is on leave; the afternoon is worked');
        $this->leave($ca, $this->week->toDateString(), 'full', '00:00', $this->week->addDay()->toDateString().' 00:00:00');
        $this->assertNull($service->forUser($ca->id, $this->branch, $this->at('0 09:00')), 'a day on leave');
    }

    public function test_my_day_shows_my_station_only_when_the_branch_plans_stations(): void
    {
        $ca = $this->actor('ca');
        $doctor = $this->actor('resident_doctor');
        $room = $this->room('dispensary');
        $version = $this->startDraft();
        $this->addShift($version, $ca, 0, '08:00', '16:00', 'dispensary', room: $room);
        $this->addShift($version, $doctor, 0, '08:00', '16:00');
        $this->submit($version);
        $this->approve($version);
        $roomName = (string) DB::table('branch_rooms')->where('id', $room)->value('name');

        $this->travelTo($this->at('0 07:30'));
        $this->selectBranch($ca);
        $this->get(route('my-day'))->assertOk()->assertInertia(fn (Assert $page) => $page->where('today.station', null));

        $this->planStations();
        $this->selectBranch($ca);
        $this->get(route('my-day'))->assertOk()->assertInertia(fn (Assert $page) => $page->where('today.station', [
            'station' => 'dispensary', 'stationLabel' => 'Dispensary', 'room' => $roomName, 'startLabel' => '8:00 AM', 'endLabel' => '4:00 PM', 'now' => false,
        ]));
        $this->travelTo($this->at('0 09:00'));
        $this->get(route('my-day'))->assertOk()->assertInertia(fn (Assert $page) => $page->where('today.station.now', true));

        $this->selectBranch($doctor);
        $this->get(route('my-day'))->assertOk()->assertInertia(fn (Assert $page) => $page->where('today.station', null)); // a doctor has a room, no station
        $this->travelTo($this->at('0 17:00'));
        $this->selectBranch($ca);
        $this->get(route('my-day'))->assertOk()->assertInertia(fn (Assert $page) => $page->where('today.station', null)); // the shift is over
    }

    // ---- fixture ----

    private function at(string $when): CarbonImmutable
    {
        [$day, $time] = explode(' ', $when);

        return CarbonImmutable::parse($this->week->addDays((int) $day)->toDateString().' '.$time, $this->branch->timezone);
    }

    private function startDraft(): RosterVersion
    {
        $this->selectBranch($this->hr);
        $this->post(route('hr.roster.drafts.store'), ['branch_id' => $this->branch->id, 'week' => $this->week->toDateString(), 'mode' => 'blank', 'idempotency_key' => (string) Str::uuid()])
            ->assertSessionHasNoErrors();

        return RosterVersion::query()->where('branch_id', $this->branch->id)->where('status', 'draft')->latest('id')->firstOrFail();
    }

    private function addShift(RosterVersion $version, User $subject, int $day, string $start, string $end, ?string $station = null, string $session = 'morning', ?int $room = null): void
    {
        $this->selectBranch($this->hr);
        $this->post(route('hr.roster.shifts.store', $version->public_id), ['staff_user_id' => $subject->id, 'date' => $this->week->addDays($day)->toDateString(),
            'start_time' => $start, 'end_time' => $end, 'break_minutes' => 0, 'session_type' => $session, 'branch_room_id' => $room, 'station' => $station,
            'branch_id' => $this->branch->id, 'idempotency_key' => (string) Str::uuid(), 'expected_version_lock' => $version->refresh()->lock_version])
            ->assertSessionHasNoErrors();
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
    }

    private function planStations(): void
    {
        $this->selectBranch($this->hr);
        $this->put(route('hr.roster.minimums'), ['branch_id' => $this->branch->id, 'minimums' => [], 'plan_stations' => true, 'expected_lock_version' => 0])
            ->assertSessionHasNoErrors();
    }

    private function room(string $kind): int
    {
        return (int) DB::table('branch_rooms')->insertGetId(['organisation_id' => $this->organisation->id, 'branch_id' => $this->branch->id, 'kind' => $kind,
            'name' => 'Synthetic Room '.Str::random(4), 'sort_order' => 1, 'is_active' => true, 'lock_version' => 1, 'updated_by_user_id' => $this->hr->id,
            'created_at' => now(), 'updated_at' => now()]);
    }

    /** Synthetic leave at this branch: `$part` full or am, slots in branch-local wall time. */
    private function leave(User $person, string $day, string $part, string $from, string $slotEnd): void
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
        DB::table('hr_leave_requests')->where('id', $id)->update(['status' => 'approved', 'decided_by_user_id' => $this->director->id, 'decided_at' => now()]);
    }
}
