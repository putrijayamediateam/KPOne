<?php

namespace Tests\Feature\HR;

use App\Domain\Access\ClinicManagementNavigation;
use App\Domain\HR\Roster\Models\RosterVersion;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Visit\VisitTestCase;

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

    public function test_staffing_says_when_the_minimum_or_the_roster_is_missing_rather_than_guessing(): void
    {
        $hr = $this->actor('hr_manager');
        $director = $this->actor('director');
        $this->assertSame('not_set', $this->dashboard($director)['branches'][0]['today']['staffing']['state']);
        $this->minimums($hr, ['morning' => ['ca' => 1]]);
        $this->assertSame('no_roster', $this->dashboard($director)['branches'][0]['today']['staffing']['state']);
        $this->assertSame(0, $this->dashboard($director)['branches'][0]['month']['shortfallDays'], 'A minimum but no shifts: no short days to count.');
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

    public function test_other_organisations_and_inactive_branches_never_appear(): void
    {
        $director = $this->actor('director');
        DB::table('branches')->where('id', '!=', $this->branch->id)->where('organisation_id', $this->organisation->id)->update(['is_active' => false]);
        $names = array_column($this->dashboard($director)['branches'], 'name');
        $this->assertSame([$this->branch->name], $names);
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

        return $this->post(route($kind === 'in' ? 'hr.attendance.clock-in' : 'hr.attendance.clock-out'), ['idempotency_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
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
        $type = DB::table('hr_leave_types')->insertGetId(['organisation_id' => $person->organisation_id, 'code' => 'AL', 'name' => 'Synthetic annual', 'is_paid' => true,
            'uses_balance' => false, 'allow_half_days' => false, 'evidence_needed' => false, 'updated_by_user_id' => $hr->id, 'created_at' => now(), 'updated_at' => now()]);
        $id = DB::table('hr_leave_requests')->insertGetId(['public_id' => (string) Str::uuid(), 'organisation_id' => $person->organisation_id, 'user_id' => $person->id,
            'leave_type_id' => $type, 'branch_id' => $this->branch->id, 'start_date' => self::WEEK, 'end_date' => self::WEEK, 'start_part' => 'full', 'end_part' => 'full',
            'slot_start' => '2027-03-08 00:00:00', 'slot_end' => '2027-03-09 00:00:00', 'year' => 2027, 'halves' => 2, 'status' => 'pending', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('hr_leave_requests')->where('id', $id)->update(['status' => 'approved', 'decided_by_user_id' => $director->id, 'decided_at' => now()]);
    }
}
