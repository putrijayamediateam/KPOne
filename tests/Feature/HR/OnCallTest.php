<?php

namespace Tests\Feature\HR;

use App\Domain\Access\PermissionCatalogue;
use App\Domain\HR\Attendance\Models\ClockSession;
use App\Domain\HR\Attendance\Services\ClockResult;
use App\Domain\HR\Attendance\Services\OvertimeService;
use App\Domain\HR\LocumPay\Services\LocumPayService;
use App\Domain\HR\LocumPay\Services\LocumPayStatement;
use App\Domain\HR\Records\Services\LocumSignIns;
use App\Domain\HR\Records\Services\StaffFileService;
use App\Domain\HR\Roster\Models\RosterShift;
use App\Domain\HR\Roster\Models\RosterVersion;
use App\Domain\HR\Roster\Services\RosterRoomLookup;
use App\Domain\HR\Roster\Services\RosterRules;
use App\Domain\HR\Roster\Services\RosterService;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\Feature\Visit\VisitTestCase;
use Tests\Support\ClockPhotoFixture;

/**
 * HR-20 "On call" (owner, 18 Nov 2026, with the eight defaults the owner approved): an on-call shift is availability, not
 * clinic hours. Dates are branch-local around Monday 8 Mar 2027, never the UTC clock. Synthetic data only.
 */
class OnCallTest extends VisitTestCase
{
    private const WEEK = '2027-03-08';

    private User $hr;

    private User $director;

    protected function setUp(): void
    {
        parent::setUp();
        $this->at('2027-03-01 09:00');
        $this->hr = $this->actor('hr_manager');
        $this->director = $this->actor('director');
    }

    public function test_on_call_is_a_session_outside_hours_rest_days_in_a_row_cover_and_minimums_but_inside_rotation(): void
    {
        $doctor = $this->actor('resident_doctor');
        $this->selectBranch($this->hr);
        $this->put(route('hr.roster.compliance.coverage'), ['branch_id' => $this->branch->id, 'hours' => array_fill_keys(range(1, 7), ['open' => '08:00', 'close' => '22:00']),
            'min_resident_doctors' => 1, 'expected_version' => 0, 'idempotency_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
        $this->put(route('hr.roster.compliance.work-hours'), ['max_days_in_row' => 6, 'min_rest_hours' => 11, 'max_week_hours' => 48, 'expected_version' => 0,
            'idempotency_key' => (string) Str::uuid()])->assertSessionHasNoErrors();

        // Seven overnight on-call shifts of 12 h (84 h in a week, seven days in a row, 12 h apart) and one evening on call.
        $version = $this->draft();
        foreach (range(0, 6) as $day) {
            $this->addShift($version, $doctor, $day, '20:00', '08:00', 'on_call')->assertSessionHasNoErrors();
        }
        $this->assertSame(7, RosterShift::query()->where('session_type', 'on_call')->count()); // PostgreSQL accepts it

        $this->selectBranch($this->hr);
        $props = $this->get(route('hr.roster.index', ['tab' => 'planner', 'branch' => $this->branch->id, 'week' => self::WEEK]))->viewData('page')['props']['roster'];
        $this->assertContains(['value' => 'on_call', 'label' => 'On call'], $props['sessions']);
        $this->assertNotContains('on_call', array_column($props['staffingSessions'], 'value')); // the minimums grid
        $kinds = collect($props['planner']['compliance']['warnings'])->groupBy('kind');
        foreach (['hours', 'days_in_row', 'rest'] as $kind) {
            $this->assertArrayNotHasKey($kind, $kinds->all(), $kind);
        }
        // On call covers no clinic hours: each day still has no resident doctor.
        $this->assertCount(7, $kinds['coverage']);
        $this->assertSame([['name' => $doctor->name, 'weekends' => 2, 'holidays' => 0, 'nights' => 7]], $props['planner']['compliance']['rotation']['rows']);

        // The night rule: crossing midnight or from 20:00 counts; an afternoon on call does not.
        $at = fn (string $t) => CarbonImmutable::parse($t, $this->branch->timezone);
        $this->assertTrue(RosterRules::countsAsNight('on_call', $at('2027-03-08 20:00'), $at('2027-03-08 23:00')));
        $this->assertTrue(RosterRules::countsAsNight('on_call', $at('2027-03-08 18:00'), $at('2027-03-09 02:00')));
        $this->assertFalse(RosterRules::countsAsNight('on_call', $at('2027-03-08 14:00'), $at('2027-03-08 19:59')));
        $this->assertFalse(RosterRules::countsAsNight('on_call', $at('2027-03-08 16:00'), $at('2027-03-09 00:00')));
        $this->assertFalse(RosterRules::countsAsNight('evening', $at('2027-03-08 20:00'), $at('2027-03-08 23:00')));

        // Minimums cannot be set for on call, and a rollback is refused while on-call shifts exist.
        $this->assertArrayNotHasKey('on_call', RosterRules::clinicSessions());
        $migration = require database_path('migrations/2026_11_10_007800_allow_on_call_roster_session.php');
        $this->expectExceptionMessage('Refusing to roll back HR-20: on-call shifts exist.');
        $migration->down();
    }

    public function test_a_call_in_has_no_late_or_left_early_counts_as_worked_and_may_all_be_claimed(): void
    {
        $doctor = $this->actor('resident_doctor');
        $standby = $this->actor('resident_doctor');
        $version = $this->draft();
        $this->addShift($version, $doctor, 0, '14:00', '22:00', 'on_call', 60)->assertSessionHasNoErrors();
        $this->addShift($version, $standby, 0, '08:00', '12:00', 'on_call')->assertSessionHasNoErrors();
        $this->approveWeek($version);

        $this->at('2027-03-08 07:00');
        $this->settings();
        // Called in at 16:00, two hours after the on-call shift began, and home at 18:00, four hours before it ends.
        $this->at('2027-03-08 16:00');
        $this->clock($doctor, 'in')->assertSessionHasNoErrors();
        $session = ClockSession::query()->where('user_id', $doctor->id)->sole();
        $this->assertSame(['code' => 'on_call', 'label' => 'On call'], app(ClockResult::class)->for($session, 'in')['chip']);
        $this->at('2027-03-08 18:00');
        $this->clock($doctor, 'out')->assertSessionHasNoErrors();
        $this->at('2027-03-08 23:30');

        $this->selectBranch($this->hr);
        $rows = collect($this->get(route('hr.attendance.index', ['tab' => 'review', 'date' => self::WEEK]))->viewData('page')['props']['attendance']['review']['rows'])->keyBy('userId');
        $this->assertSame([], $rows[$doctor->id]['flags']);
        $this->assertSame([], $rows[$standby->id]['flags']); // never called in: no "No clock-in"
        $this->assertSame(120, $rows[$doctor->id]['workedMinutes']); // the shift's break is not taken off a call-in
        $this->assertSame(0, $rows[$doctor->id]['rosteredMinutes']);
        $this->assertSame(120, $rows[$doctor->id]['beyondMinutes']);
        $allowance = app(OvertimeService::class)->allowance($session->refresh(), $this->branch);
        $this->assertSame([120, 120, 0], [$allowance['max'], $allowance['worked'], $allowance['rostered']]);
    }

    public function test_on_call_gives_no_room_and_shows_on_its_own_line_on_the_dashboard(): void
    {
        $doctor = $this->actor('resident_doctor');
        $ca = $this->actor('ca');
        $room = (int) DB::table('branch_rooms')->insertGetId(['organisation_id' => $this->branch->organisation_id, 'branch_id' => $this->branch->id, 'kind' => 'consultation',
            'name' => 'Room 1', 'sort_order' => 1, 'is_active' => true, 'lock_version' => 1, 'updated_by_user_id' => $this->director->id, 'created_at' => now(), 'updated_at' => now()]);
        $version = $this->draft();
        $this->addShift($version, $doctor, 0, '08:00', '20:00', 'on_call', 0, $room)->assertSessionHasNoErrors();
        $this->addShift($version, $ca, 0, '08:00', '20:00', 'full_day')->assertSessionHasNoErrors();
        $this->approveWeek($version);

        $this->at('2027-03-08 10:00');
        $this->assertNull(app(RosterRoomLookup::class)->roomFor($doctor->id, $this->branch, now()));

        $this->selectBranch($this->director);
        $rostered = collect($this->get(route('hr.dashboard.index'))->viewData('page')['props']['dashboard']['branches'])->firstWhere('id', $this->branch->id)['today']['rostered'];
        $this->assertSame(1, $rostered['count']);
        $this->assertSame(0, $rostered['doctors']);
        $this->assertTrue($rostered['noDoctor']);
        $this->assertSame([$doctor->name], array_column($rostered['onCall'], 'name'));
        $this->assertSame([$ca->name], array_column($rostered['people'], 'name'));
    }

    public function test_locum_pay_leaves_on_call_shifts_out_and_they_cannot_be_marked_not_worked(): void
    {
        $locum = $this->actor(PermissionCatalogue::LOCUM_ROLE);
        $worker = app(StaffFileService::class)->addLocum($this->hr, ['display_name' => 'Dr Synthetic On Call', 'phone' => '012-345 6790',
            'idempotency_key' => (string) Str::uuid()]);
        app(LocumSignIns::class)->request($this->hr, $worker->public_id, ['email' => $locum->email, 'idempotency_key' => (string) Str::uuid()]);
        $request = DB::table('hr_locum_sign_ins')->where('worker_id', $worker->id)->first();
        app(LocumSignIns::class)->link($this->director, $request->public_id, ['user_id' => $locum->id, 'expected_lock_version' => 1,
            'idempotency_key' => (string) Str::uuid()]);
        $roster = app(RosterService::class);
        $version = $roster->startDraft($this->hr, ['branch_id' => $this->branch->id, 'week' => self::WEEK, 'mode' => 'blank', 'idempotency_key' => (string) Str::uuid()])['version'];
        foreach ([['2027-03-08', 'morning'], ['2027-03-09', 'on_call']] as [$date, $type]) {
            $roster->addShift($this->hr, $version->public_id, ['staff_user_id' => $locum->id, 'date' => $date, 'start_time' => '08:00', 'end_time' => '12:00',
                'break_minutes' => 0, 'session_type' => $type, 'expected_version_lock' => $version->refresh()->lock_version, 'idempotency_key' => (string) Str::uuid()]);
        }
        $roster->submit($this->hr, $version->public_id, ['expected_version_lock' => $version->refresh()->lock_version, 'idempotency_key' => (string) Str::uuid()]);
        $roster->approve($this->director, $version->public_id, ['expected_version_lock' => $version->refresh()->lock_version, 'idempotency_key' => (string) Str::uuid()]);

        $statement = LocumPayStatement::build((int) $worker->organisation_id, (int) $worker->id, '2027-03-01');
        $this->assertSame(['2027-03-08'], array_column($statement['lines'], 'date'));
        $onCall = RosterShift::query()->where('session_type', 'on_call')->sole();
        $this->at('2027-04-02 09:00');
        $this->expectException(NotFoundHttpException::class);
        app(LocumPayService::class)->markNotWorked($this->hr, $worker->public_id, ['shift_id' => $onCall->public_id, 'reason' => 'cancelled_by_clinic',
            'idempotency_key' => (string) Str::uuid()]);
    }

    // ---- fixture ----

    private function at(string $time): void
    {
        $this->travelTo(CarbonImmutable::parse($time, $this->branch->timezone));
    }

    private function draft(): RosterVersion
    {
        $this->selectBranch($this->hr);
        $this->post(route('hr.roster.drafts.store'), ['branch_id' => $this->branch->id, 'week' => self::WEEK, 'mode' => 'blank', 'idempotency_key' => (string) Str::uuid()])
            ->assertSessionHasNoErrors();

        return RosterVersion::query()->where('branch_id', $this->branch->id)->where('status', 'draft')->latest('id')->firstOrFail();
    }

    private function addShift(RosterVersion $version, User $subject, int $day, string $start, string $end, string $type, int $break = 0, ?int $room = null): TestResponse
    {
        $this->selectBranch($this->hr);

        return $this->post(route('hr.roster.shifts.store', $version->public_id), ['staff_user_id' => $subject->id,
            'date' => CarbonImmutable::parse(self::WEEK)->addDays($day)->toDateString(), 'start_time' => $start, 'end_time' => $end, 'break_minutes' => $break,
            'session_type' => $type, 'branch_room_id' => $room, 'branch_id' => $this->branch->id, 'expected_version_lock' => $version->refresh()->lock_version,
            'idempotency_key' => (string) Str::uuid()]);
    }

    private function approveWeek(RosterVersion $version): void
    {
        $this->selectBranch($this->hr);
        $this->post(route('hr.roster.submit', $version->public_id), ['expected_version_lock' => $version->refresh()->lock_version, 'idempotency_key' => (string) Str::uuid()])
            ->assertSessionHasNoErrors();
        $this->selectBranch($this->director);
        $this->post(route('hr.roster.approve', $version->public_id), ['expected_version_lock' => $version->refresh()->lock_version, 'idempotency_key' => (string) Str::uuid()])
            ->assertSessionHasNoErrors();
    }

    private function settings(): void
    {
        $this->selectBranch($this->hr);
        $this->put(route('hr.attendance.settings'), ['grace_minutes' => 5, 'forgotten_window_minutes' => 60, 'correction_days' => 7, 'effective_from' => self::WEEK,
            'idempotency_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
    }

    private function clock(User $actor, string $kind): TestResponse
    {
        $this->selectBranch($actor);

        return $this->post(route($kind === 'in' ? 'hr.attendance.clock-in' : 'hr.attendance.clock-out'), ['idempotency_key' => (string) Str::uuid(),
            ...ClockPhotoFixture::press($this, $kind)]);
    }
}
