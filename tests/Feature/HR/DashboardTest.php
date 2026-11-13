<?php

namespace Tests\Feature\HR;

use App\Domain\Access\ClinicManagementNavigation;
use App\Domain\HR\Roster\Models\RosterVersion;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Organisation\Models\Department;
use App\Domain\Organisation\Models\Organisation;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Visit\VisitTestCase;
use Tests\Support\ClockPhotoFixture;

/**
 * HR-9 HR dashboard: read-only aggregates. The clock is frozen in Kuala Lumpur on Monday 8 Mar 2027, so every date is
 * branch-local and never the UTC clock (BASELINE section 6 item 21). Names are synthetic and every address is `.test`.
 */
class DashboardTest extends VisitTestCase
{
    private const WEEK = '2027-03-08';

    protected function setUp(): void
    {
        parent::setUp();
        $this->at('2027-03-08 10:00');
    }

    /** @return list<array{string, int}> */
    public static function viewers(): array
    {
        return [['director', 200], ['hr_manager', 200], ['ca', 403], ['ca_supervisor', 403], ['finance_officer', 403], ['resident_doctor', 403], ['technical_admin', 403], ['queue_display', 403]];
    }

    #[DataProvider('viewers')]
    public function test_only_the_roster_attendance_or_leave_managers_open_the_page(string $role, int $status): void
    {
        $this->selectBranch($this->actor($role));
        $this->get(route('hr.dashboard.index'))->assertStatus($status);
    }

    public function test_each_section_follows_its_own_permission_and_the_hub_row_follows_any_of_them(): void
    {
        $full = $this->actor('director');
        $this->selectBranch($full);
        $this->get(route('hr.dashboard.index'))->assertInertia(fn (Assert $page) => $page->component('HR/Dashboard')
            ->where('dashboard.can', ['roster' => true, 'attendance' => true, 'leave' => true]));
        foreach (['director', 'hr_manager'] as $role) {
            $this->assertContains('/hr/dashboard', $this->hubLinks($this->actor($role)), $role);
        }
        $this->assertNotContains('/hr/dashboard', $this->hubLinks($this->actor('ca')));

        // A person holding only one of the three sees only that section; nothing else is computed for them.
        foreach (['hr.leave.manage.organisation' => 'leave', 'hr.attendance.review.organisation' => 'attendance', 'hr.roster.manage.organisation' => 'roster'] as $permission => $section) {
            $person = $this->actor('ca');
            $person->givePermissionTo($permission);
            $this->selectBranch($person);
            $this->get(route('hr.dashboard.index'))->assertOk()->assertInertia(function (Assert $page) use ($section): void {
                $page->where('dashboard.can', ['roster' => $section === 'roster', 'attendance' => $section === 'attendance', 'leave' => $section === 'leave'])
                    ->where('dashboard.branches.0.today.rostered', $section === 'roster' ? fn ($v) => $v !== null : null)
                    ->where('dashboard.branches.0.today.clockedIn', $section === 'attendance' ? fn ($v) => $v !== null : null)
                    ->where('dashboard.branches.0.today.onLeave', $section === 'leave' ? fn ($v) => $v !== null : null)
                    ->where('dashboard.branches.0.month.attendance', $section === 'attendance' ? fn ($v) => $v !== null : null)
                    ->where('dashboard.branches.0.month.leave', $section === 'leave' ? fn ($v) => $v !== null : null)
                    ->where('dashboard.branches.0.month.shortfallDays', null);
            });
            $person->revokePermissionTo($permission);
        }
    }

    public function test_today_shows_who_is_rostered_clocked_in_and_on_leave_and_whether_staffing_is_enough(): void
    {
        $hr = $this->actor('hr_manager');
        $director = $this->actor('director');
        $doctor = $this->actor('resident_doctor');
        $ca = $this->actor('ca');
        $away = $this->actor('ca');
        $this->minimums($hr, ['morning' => ['resident_doctor' => 1, 'ca' => 2]]);
        $this->roster($hr, $director, [[$doctor, '08:00', '17:00'], [$ca, '08:00', '17:00']]);
        $this->approvedLeave($away, $hr, $director);
        $this->clock($ca, 'in');

        $today = $this->dashboard($director)['branches'][0]['today'];

        $this->assertSame('Mon 8 Mar', $today['date']);
        $this->assertSame(2, $today['rostered']['count']);
        $this->assertSame(1, $today['rostered']['doctors']);
        $this->assertFalse($today['rostered']['noDoctor']);
        $this->assertEqualsCanonicalizing([$doctor->name, $ca->name], array_column($today['rostered']['people'], 'name'));
        $this->assertSame([$ca->name], array_column($today['clockedIn']['people'], 'name'));
        $this->assertSame(1, $today['onLeave']['count']);
        $this->assertSame([[$away->name, false]], array_map(fn (array $p): array => [$p['name'], $p['half']], $today['onLeave']['people']));
        // One CA against a minimum of two: short, by the Planner's own wording.
        $this->assertSame('short', $today['staffing']['state']);
        $this->assertCount(1, $today['staffing']['messages']);
        $this->assertStringContainsString('1 CA (minimum 2)', $today['staffing']['messages'][0]);
        $this->assertStringNotContainsString('Resident Doctor', $today['staffing']['messages'][0]);

        $this->minimums($hr, ['morning' => ['resident_doctor' => 1, 'ca' => 1]], 1);
        $this->assertSame('enough', $this->dashboard($director)['branches'][0]['today']['staffing']['state']);
    }

    public function test_enough_or_short_follows_the_planner_for_today_and_a_closed_day_shows_nothing(): void
    {
        $hr = $this->actor('hr_manager');
        $director = $this->actor('director');
        $this->workingDays($hr, [1, 2, 3, 4, 5]);
        $this->minimums($hr, ['morning' => ['ca' => 2]]);
        $this->roster($hr, $director, [[$this->actor('ca'), '08:00', '19:00']]);
        $state = fn (): string => $this->dashboard($director)['branches'][0]['today']['staffing']['state'];

        $this->assertSame('short', $state(), 'A full-day shift counts towards the morning minimum, as in the Planner.');
        $this->at('2027-03-08 21:00');
        $this->assertSame('short', $state(), 'Judged for today, not for the hour: still short after the shift ended.');
        $this->at('2027-03-14 10:00'); // Sunday: no shifts and not a working day.
        $this->assertSame('closed', $state());
    }

    public function test_a_branch_with_someone_on_shift_but_no_doctor_is_flagged_and_a_quiet_hour_is_not(): void
    {
        $hr = $this->actor('hr_manager');
        $director = $this->actor('director');
        $this->roster($hr, $director, [[$this->actor('ca'), '08:00', '17:00']]);

        $this->assertTrue($this->dashboard($director)['branches'][0]['today']['rostered']['noDoctor']);

        $this->at('2027-03-08 20:00'); // After every shift: a closed branch is not "without a doctor".
        $today = $this->dashboard($director)['branches'][0]['today'];
        $this->assertSame(0, $today['rostered']['count']);
        $this->assertFalse($today['rostered']['noDoctor']);
    }

    public function test_staffing_says_when_the_roster_or_the_minimum_is_missing_rather_than_guessing(): void
    {
        $hr = $this->actor('hr_manager');
        $director = $this->actor('director');
        $state = fn (): string => $this->dashboard($director)['branches'][0]['today']['staffing']['state'];

        $this->assertSame('no_roster', $state(), 'No shifts today at a branch whose working days are not set.');
        $this->roster($hr, $director, [[$this->actor('ca'), '08:00', '17:00']]);
        $this->assertSame('not_set', $state(), 'A roster but no minimum staffing: nothing to judge against.');
        $this->minimums($hr, ['morning' => ['ca' => 1]]);
        $this->assertSame('enough', $state());
        $this->assertSame(0, $this->dashboard($director)['branches'][0]['month']['shortfallDays'], 'A minimum met: no short days to count.');
    }

    public function test_a_day_with_a_shift_and_nobody_clocked_in_yet_opens_on_review_and_on_the_dashboard(): void
    {
        // Regression (found building HR-9): merging an empty list of sessions with the day's shifts failed in AttendanceDays.
        $hr = $this->actor('hr_manager');
        $director = $this->actor('director');
        $this->settings($hr);
        $this->roster($hr, $director, [[$this->actor('ca'), '08:00', '17:00']]);
        $this->selectBranch($hr);
        $this->get(route('hr.attendance.index', ['tab' => 'review']))->assertOk()->assertInertia(fn (Assert $page) => $page->where('attendance.tab', 'review'));
        $this->assertSame(0, $this->dashboard($director)['branches'][0]['month']['attendance']['daysClocked']);
    }

    public function test_the_month_counts_attendance_flags_leave_days_and_short_days(): void
    {
        $hr = $this->actor('hr_manager');
        $director = $this->actor('director');
        $ca = $this->actor('ca');
        $away = $this->actor('ca');
        $this->settings($hr);
        $this->workingDays($hr, [1, 2, 3, 4, 5]);
        $this->minimums($hr, ['morning' => ['ca' => 2]]);
        $this->roster($hr, $director, [[$ca, '08:00', '17:00']]);
        $this->approvedLeave($away, $hr, $director);
        $this->at('2027-03-08 08:30');
        $this->clock($ca, 'in'); // 30 minutes late against a 5 minute grace.
        $this->at('2027-03-08 17:00');
        $this->clock($ca, 'out');

        $dashboard = $this->dashboard($director);
        $month = $dashboard['branches'][0]['month'];

        $this->assertTrue($dashboard['attendanceSettingsSaved']);
        $this->assertSame('2027-03', $dashboard['month']);
        $this->assertSame(1, $month['attendance']['daysClocked']);
        $this->assertSame(1, $month['attendance']['late']);
        $this->assertSame(0, $month['attendance']['noClockIn']);
        $this->assertEquals(['days' => 1, 'people' => 1], $month['leave']);
        $this->assertSame(0, $month['overtime']['approvedMinutes']);
        $this->assertSame(1, $month['shortfallDays'], 'Only the one day with a shift and a CA below the minimum of two.');

        // Last month has none of it.
        $feb = $this->dashboard($director, ['month' => '2027-02'])['branches'][0]['month'];
        $this->assertSame(['daysClocked' => 0, 'late' => 0, 'leftEarly' => 0, 'noClockIn' => 0, 'noClockOut' => 0], $feb['attendance']);
        $this->assertEquals(['days' => 0, 'people' => 0], $feb['leave']);
        $this->assertSame(0, $feb['shortfallDays']);
    }

    public function test_the_month_picker_stays_inside_twelve_months_and_follows_the_kuala_lumpur_date(): void
    {
        $director = $this->actor('director');
        $page = $this->dashboard($director);
        $this->assertSame('2027-03', $page['month']);
        $this->assertCount(12, $page['months']);
        $this->assertSame(['2027-03', '2027-02', '2027-01'], array_slice(array_column($page['months'], 'value'), 0, 3));
        $this->assertSame('2026-04', end($page['months'])['value']);
        foreach (['2025-01', '2027-04', '2027-13', 'nonsense'] as $bad) {
            $this->assertSame('2027-03', $this->dashboard($director, ['month' => $bad])['month'], $bad);
        }
        $this->assertSame('2027-03', $this->dashboard($director, ['month' => ['x']])['month']);

        // 23:30 UTC on 31 March is already 1 April in Kuala Lumpur.
        $this->travelTo(CarbonImmutable::parse('2027-03-31 23:30:00', 'UTC'));
        $this->assertSame('2027-04', $this->dashboard($director)['month']);
    }

    public function test_opening_the_page_writes_one_values_free_audit_row_and_nothing_else(): void
    {
        $hr = $this->actor('hr_manager');
        $director = $this->actor('director');
        $doctor = $this->actor('resident_doctor');
        $this->roster($hr, $director, [[$doctor, '08:00', '17:00']]);
        $this->approvedLeave($this->actor('ca'), $hr, $director);
        $before = $this->tables();

        $payload = json_encode($this->dashboard($director));

        $after = $this->tables();
        $this->assertSame(1, $after['audit_logs'] - $before['audit_logs']);
        unset($before['audit_logs'], $after['audit_logs']);
        $this->assertSame($before, $after, 'The dashboard writes nothing but its audit row.');
        $audit = DB::table('audit_logs')->where('event', 'hr.dashboard.viewed')->sole();
        $metadata = (string) json_encode($audit->metadata ?? $audit->context ?? '');
        $this->assertStringNotContainsString($doctor->name, $metadata);
        $this->assertStringContainsString('2027-03', $metadata);
        foreach (['salary', 'pay', 'reason', 'leave_type', 'leaveType', 'password', 'bank'] as $forbidden) {
            $this->assertStringNotContainsStringIgnoringCase('"'.$forbidden, (string) $payload, $forbidden);
        }
    }

    public function test_inactive_branches_never_appear(): void
    {
        $director = $this->actor('director');
        DB::table('branches')->where('id', '!=', $this->branch->id)->where('organisation_id', $this->organisation->id)->update(['is_active' => false]);
        $names = array_column($this->dashboard($director)['branches'], 'name');
        $this->assertSame([$this->branch->name], $names);
    }

    public function test_a_doctor_on_leave_is_not_rostered_now_and_the_other_half_of_a_half_day_still_is(): void
    {
        $hr = $this->actor('hr_manager');
        $director = $this->actor('director');
        $doctor = $this->actor('resident_doctor');
        $ca = $this->actor('ca');
        $this->roster($hr, $director, [[$doctor, '08:00', '17:00'], [$ca, '08:00', '17:00']]);
        // A morning half day (slot 00:00 to 12:00) for the doctor.
        $this->leave($doctor, $hr, $director, self::WEEK, self::WEEK, '2027-03-08 00:00:00', '2027-03-08 12:00:00', 1, 'full', 'full');

        $today = $this->dashboard($director)['branches'][0]['today']; // 10:00
        $this->assertSame([$ca->name], array_column($today['rostered']['people'], 'name'));
        $this->assertSame(0, $today['rostered']['doctors']);
        $this->assertTrue($today['rostered']['noDoctor'], 'A doctor on leave with a surviving shift does not hide the alert.');
        $this->assertSame([[$doctor->name, true]], array_map(fn (array $p): array => [$p['name'], $p['half']], $today['onLeave']['people']));

        $this->at('2027-03-08 14:00'); // The afternoon is theirs again.
        $today = $this->dashboard($director)['branches'][0]['today'];
        $this->assertEqualsCanonicalizing([$doctor->name, $ca->name], array_column($today['rostered']['people'], 'name'));
        $this->assertFalse($today['rostered']['noDoctor']);
        $this->assertSame(0, $today['onLeave']['count']);
    }

    public function test_midnight_in_kuala_lumpur_is_the_new_day_and_a_leave_slot_ends_exactly_at_local_midnight(): void
    {
        $hr = $this->actor('hr_manager');
        $director = $this->actor('director');
        $away = $this->actor('ca');
        $this->leave($away, $hr, $director, '2027-03-07', '2027-03-07', '2027-03-07 00:00:00', '2027-03-08 00:00:00', 2);
        $this->leave($this->actor('ca'), $hr, $director, '2027-03-08', '2027-03-08', '2027-03-08 00:00:00', '2027-03-09 00:00:00', 2);

        $this->travelTo(CarbonImmutable::parse('2027-03-07 16:30:00', 'UTC')); // 00:30 on Monday 8 March in Kuala Lumpur.
        $page = $this->dashboard($director);

        $this->assertSame('2027-03', $page['month']);
        $this->assertSame('Mon 8 Mar', $page['branches'][0]['today']['date']);
        $this->assertSame(1, $page['branches'][0]['today']['onLeave']['count'], 'Sunday\'s leave ended at local midnight; only Monday\'s is on now.');
    }

    public function test_attendance_flags_in_the_month(): void
    {
        $hr = $this->actor('hr_manager');
        $director = $this->actor('director');
        [$early, $missing, $noOut] = [$this->actor('ca'), $this->actor('ca'), $this->actor('ca')];
        $this->settings($hr);
        $this->at('2027-03-08 07:00');
        $this->roster($hr, $director, [[$early, '08:00', '17:00'], [$missing, '08:00', '17:00'], [$noOut, '08:00', '17:00']]);
        $this->at('2027-03-08 08:00');
        $this->clock($early, 'in');
        $this->clock($noOut, 'in');
        $this->at('2027-03-08 15:00');
        $this->clock($early, 'out');
        $this->at('2027-03-08 18:30');

        $month = $this->dashboard($director)['branches'][0]['month'];

        $this->assertSame(['daysClocked' => 2, 'late' => 0, 'leftEarly' => 1, 'noClockIn' => 1, 'noClockOut' => 1], $month['attendance']);
    }

    public function test_approved_overtime_counts_only_approved_requests_dated_in_the_month(): void
    {
        $hr = $this->actor('hr_manager');
        $director = $this->actor('director');
        $person = $this->actor('ca');
        $setting = DB::table('hr_overtime_settings')->insertGetId(['organisation_id' => $hr->organisation_id, 'effective_from' => '2027-01-01', 'normal_multiplier' => 1.5, 'rest_day_multiplier' => 2,
            'public_holiday_multiplier' => 3, 'nearing_hours' => 40, 'limit_hours' => 45, 'created_by_user_id' => $hr->id, 'created_at' => now()]);
        // Each request belongs to a closed clock session of the same person on the same day.
        foreach ([['2027-03-08', 'approved', 45], ['2027-03-20', 'approved', 30], ['2027-03-22', 'reversed', 90], ['2027-03-23', 'pending', null], ['2027-04-03', 'approved', 60]] as [$date, $status, $minutes]) {
            $this->at("{$date} 09:00");
            $this->clock($person, 'in');
            $this->at("{$date} 12:00");
            $this->clock($person, 'out');
            $session = DB::table('hr_clock_sessions')->where('user_id', $person->id)->whereDate('local_date', $date)->first();
            $id = DB::table('hr_overtime_requests')->insertGetId(['public_id' => (string) Str::uuid(), 'organisation_id' => $hr->organisation_id, 'user_id' => $person->id, 'requested_by_user_id' => $hr->id,
                'clock_session_id' => $session->id, 'branch_id' => $this->branch->id, 'local_date' => $date, 'minutes_requested' => $minutes ?? 30, 'reason_code' => 'other', 'day_type' => 'normal',
                'status' => 'pending', 'created_at' => now(), 'updated_at' => now()]);
            if ($status !== 'pending') { // A request starts pending; decide it, and reverse it once, as the services do.
                DB::table('hr_overtime_requests')->where('id', $id)->update(['status' => 'approved', 'minutes_approved' => $minutes, 'overtime_setting_id' => $setting, 'multiplier' => 1.5,
                    'worked_minutes_at_decision' => 180, 'decided_by_user_id' => $director->id, 'decided_at' => now()]);
            }
            if ($status === 'reversed') {
                DB::table('hr_overtime_requests')->where('id', $id)->update(['status' => 'reversed', 'reversed_by_user_id' => $director->id, 'reversed_at' => now(), 'reverse_reason' => 'other']);
            }
        }
        $this->at('2027-04-04 10:00');

        $march = $this->dashboard($director, ['month' => '2027-03'])['branches'][0]['month'];
        $april = $this->dashboard($director, ['month' => '2027-04'])['branches'][0]['month'];

        $this->assertSame(75, $march['overtime']['approvedMinutes'], 'March: 45 + 30. A reversed and a pending request do not count, nor does one dated April.');
        $this->assertSame(60, $april['overtime']['approvedMinutes']);
    }

    public function test_leave_is_clamped_to_the_month_and_reads_not_set_up_without_working_days(): void
    {
        $hr = $this->actor('hr_manager');
        $director = $this->actor('director');
        $person = $this->actor('ca');
        // Friday 26 Feb to Tuesday 2 March, Monday to Friday working: February has 1 day, March has Monday and Tuesday.
        $this->leave($person, $hr, $director, '2027-02-26', '2027-03-02', '2027-02-26 00:00:00', '2027-03-03 00:00:00', 6);

        $this->assertNull($this->dashboard($director)['branches'][0]['month']['leave'], 'Working days are not set up: not a guessed number.');

        $this->workingDays($hr, [1, 2, 3, 4, 5]);
        $this->assertEquals(['days' => 2, 'people' => 1], $this->dashboard($director)['branches'][0]['month']['leave']);
        $this->assertEquals(['days' => 1, 'people' => 1], $this->dashboard($director, ['month' => '2027-02'])['branches'][0]['month']['leave']);
    }

    public function test_the_permissions_are_checked_fresh_on_every_request_and_an_inactive_user_is_refused(): void
    {
        $person = $this->actor('ca');
        $person->givePermissionTo('hr.leave.manage.organisation');
        $this->selectBranch($person);
        $this->get(route('hr.dashboard.index'))->assertOk();

        $person->revokePermissionTo('hr.leave.manage.organisation');
        $this->get(route('hr.dashboard.index'))->assertForbidden();

        $director = $this->actor('director');
        $this->selectBranch($director);
        $this->get(route('hr.dashboard.index'))->assertOk();
        DB::table('users')->where('id', $director->id)->update(['is_active' => false]);
        $this->get(route('hr.dashboard.index'))->assertStatus(403);
    }

    public function test_a_viewer_with_only_the_leave_permission_never_receives_the_roster_or_clock_names(): void
    {
        $hr = $this->actor('hr_manager');
        $director = $this->actor('director');
        $doctor = $this->actor('resident_doctor');
        $this->roster($hr, $director, [[$doctor, '08:00', '17:00']]);
        $this->clock($doctor, 'in');
        $viewer = $this->actor('ca');
        $viewer->givePermissionTo('hr.leave.manage.organisation');

        $this->selectBranch($viewer);
        $json = (string) $this->get(route('hr.dashboard.index'))->assertOk()->getContent();

        $this->assertStringNotContainsString($doctor->name, $json, 'Roster and clock names are withheld from the whole payload, not only hidden in the page.');
    }

    public function test_another_organisations_staff_clock_times_and_leave_never_appear(): void
    {
        $hr = $this->actor('hr_manager');
        $director = $this->actor('director');
        $organisation = new Organisation;
        $organisation->forceFill(['code' => 'SYN-HR9', 'name' => 'Synthetic other organisation', 'is_active' => true])->save();
        $other = new Branch;
        $other->forceFill(['organisation_id' => $organisation->id, 'code' => 'SYN-HR9', 'name' => 'Synthetic other branch', 'timezone' => 'Asia/Kuala_Lumpur', 'is_active' => true])->save();
        $department = new Department;
        $department->forceFill(['organisation_id' => $organisation->id, 'code' => 'SYN-DEPT', 'name' => 'Synthetic Department', 'is_active' => true])->save();
        $outsider = $this->actor('ca', $other);
        $this->clock($outsider, 'in');
        $theirBoss = $this->actor('hr_manager', $other);
        $this->leave($this->actor('ca', $other), $theirBoss, $theirBoss, self::WEEK, self::WEEK, '2027-03-08 00:00:00', '2027-03-09 00:00:00', 2, 'full', 'full', $other->id, $organisation->id);

        $json = (string) $this->get(route('hr.dashboard.index'))->getContent();
        $page = $this->dashboard($director);

        $this->assertStringNotContainsString($outsider->name, $json);
        $this->assertSame([$this->branch->name], array_column(array_filter($page['branches'], fn (array $b): bool => $b['id'] === $this->branch->id), 'name'));
        foreach ($page['branches'] as $branch) {
            $this->assertNotSame($other->id, $branch['id']);
            $this->assertSame(0, $branch['today']['clockedIn']['count']);
            $this->assertSame(0, $branch['today']['onLeave']['count']);
            $this->assertSame(0, $branch['month']['attendance']['daysClocked']);
        }
    }

    // --- helpers -------------------------------------------------------------------------------------------------

    /** @param array<string, mixed> $query */
    private function dashboard(User $actor, array $query = []): array
    {
        $this->selectBranch($actor);
        $props = $this->get(route('hr.dashboard.index', $query))->assertOk()->viewData('page')['props'];

        return $props['dashboard'];
    }

    /** @return list<string> */
    private function hubLinks(User $actor): array
    {
        return collect(app(ClinicManagementNavigation::class)->groups($actor))->flatMap(fn (array $g) => $g['links'])->pluck('href')->all();
    }

    private function at(string $time): void
    {
        $this->travelTo(CarbonImmutable::parse($time, $this->branch->timezone));
    }

    /** @return array<string, int> */
    private function tables(): array
    {
        $out = [];
        foreach (['audit_logs', 'hr_roster_shifts', 'hr_roster_versions', 'hr_clock_sessions', 'hr_clock_events', 'hr_leave_requests', 'hr_overtime_requests', 'hr_minimum_staffing', 'users', 'staff_branch_assignments', 'visits', 'queue_entries'] as $table) {
            $out[$table] = DB::table($table)->count();
        }

        return $out;
    }

    /** @param array<string, array<string, int>> $values */
    private function minimums(User $hr, array $values, int $lock = 0): void
    {
        $this->selectBranch($hr);
        $this->put(route('hr.roster.minimums'), ['branch_id' => $this->branch->id, 'minimums' => $values, 'expected_lock_version' => $lock])->assertSessionHasNoErrors();
    }

    private function clock(User $actor, string $kind): TestResponse
    {
        $this->selectBranch($actor);

        return $this->post(route($kind === 'in' ? 'hr.attendance.clock-in' : 'hr.attendance.clock-out'), ['idempotency_key' => (string) Str::uuid(), ...ClockPhotoFixture::press($this, $kind)])->assertSessionHasNoErrors();
    }

    private function settings(User $actor): void
    {
        $this->selectBranch($actor);
        $this->put(route('hr.attendance.settings'), ['grace_minutes' => 5, 'forgotten_window_minutes' => 60, 'correction_days' => 7, 'idempotency_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
    }

    /** @param list<int> $days */
    private function workingDays(User $manager, array $days): void
    {
        $this->selectBranch($manager);
        $lock = DB::table('hr_branch_working_days')->where('branch_id', $this->branch->id)->value('lock_version');
        $this->put(route('hr.leave.working-days'), ['branch_id' => $this->branch->id, 'weekdays' => $days, 'expected_lock_version' => $lock])->assertSessionHasNoErrors();
    }

    /** @param list<array{User, string, string}> $shifts */
    private function roster(User $hr, User $director, array $shifts): void
    {
        $this->selectBranch($hr);
        $this->post(route('hr.roster.drafts.store'), ['branch_id' => $this->branch->id, 'week' => self::WEEK, 'mode' => 'blank', 'idempotency_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
        $version = RosterVersion::query()->latest('id')->firstOrFail();
        foreach ($shifts as [$person, $start, $end]) {
            $this->post(route('hr.roster.shifts.store', $version->public_id), ['staff_user_id' => $person->id, 'date' => self::WEEK, 'start_time' => $start, 'end_time' => $end,
                'break_minutes' => 0, 'session_type' => 'full_day', 'branch_room_id' => null, 'branch_id' => $this->branch->id, 'week' => self::WEEK,
                'expected_version_lock' => $version->refresh()->lock_version, 'idempotency_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
        }
        $this->post(route('hr.roster.submit', $version->public_id), ['expected_version_lock' => $version->refresh()->lock_version, 'idempotency_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
        $this->selectBranch($director);
        $this->post(route('hr.roster.approve', $version->public_id), ['expected_version_lock' => $version->refresh()->lock_version, 'idempotency_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
    }

    private function approvedLeave(User $person, User $hr, User $director): void
    {
        $this->leave($person, $hr, $director, self::WEEK, self::WEEK, '2027-03-08 00:00:00', '2027-03-09 00:00:00', 2);
    }

    /** An approved leave request written directly, as the AttendanceTest fixtures do; slots are branch-local wall-clock times. */
    private function leave(User $person, User $hr, User $director, string $start, string $end, string $slotStart, string $slotEnd, int $halves, string $startPart = 'full', string $endPart = 'full', ?int $branchId = null, ?int $organisationId = null): void
    {
        $organisationId ??= $person->organisation_id;
        $type = DB::table('hr_leave_types')->where('organisation_id', $organisationId)->value('id') ?? DB::table('hr_leave_types')->insertGetId(['organisation_id' => $organisationId, 'code' => 'AL', 'name' => 'Synthetic annual', 'is_paid' => true,
            'uses_balance' => false, 'allow_half_days' => true, 'evidence_needed' => false, 'updated_by_user_id' => $person->id, 'created_at' => now(), 'updated_at' => now()]);
        $id = DB::table('hr_leave_requests')->insertGetId(['public_id' => (string) Str::uuid(), 'organisation_id' => $organisationId, 'user_id' => $person->id,
            'leave_type_id' => $type, 'branch_id' => $branchId ?? $this->branch->id, 'start_date' => $start, 'end_date' => $end, 'start_part' => $startPart, 'end_part' => $endPart,
            'slot_start' => $slotStart, 'slot_end' => $slotEnd, 'year' => 2027, 'halves' => $halves, 'status' => 'pending', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('hr_leave_requests')->where('id', $id)->update(['status' => 'approved', 'decided_by_user_id' => $director->id, 'decided_at' => now()]);
    }
}
