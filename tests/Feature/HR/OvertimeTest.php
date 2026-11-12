<?php

namespace Tests\Feature\HR;

use App\Domain\Access\PermissionCatalogue;
use App\Domain\HR\Attendance\ClockPermissions;
use App\Domain\HR\Attendance\Models\ClockSession;
use App\Domain\HR\Attendance\Models\OvertimeRequest;
use App\Domain\HR\Roster\Models\RosterVersion;
use App\Domain\Identity\Models\StaffProfile;
use App\Domain\Organisation\Models\Branch;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Tests\Feature\Visit\VisitTestCase;
use Tests\Support\StaffBranchAssignmentBootstrapper;

/**
 * HR-3b overtime, weekly hours and summaries (owner, 2026-11-06). The clock is frozen in Kuala Lumpur in the week of
 * Monday 8 Mar 2027, so every date is branch-local and never the UTC clock (BASELINE section 6 item 21).
 */
class OvertimeTest extends VisitTestCase
{
    private const WEEK = '2027-03-08';

    protected function setUp(): void
    {
        parent::setUp();
        $this->at('2027-03-08 07:00');
    }

    public function test_the_approve_permission_is_exact_and_managers_see_the_new_tabs(): void
    {
        $holders = collect(PermissionCatalogue::roles())->filter(fn (array $p) => in_array(ClockPermissions::OVERTIME_APPROVE, $p, true))->keys()->sort()->values()->all();
        $this->assertSame(['director', 'hr_manager'], $holders);
        $this->assertFalse(PermissionCatalogue::isAdministrativeAuthority(ClockPermissions::OVERTIME_APPROVE));
        $this->assertEqualsCanonicalizing(['director', 'hr_manager'], Role::query()->whereHas('permissions', fn ($q) => $q->where('name', ClockPermissions::OVERTIME_APPROVE))->pluck('name')->all());

        $this->selectBranch($this->actor('hr_manager'));
        $this->get(route('hr.attendance.index', ['tab' => 'overtime']))->assertInertia(fn (Assert $page) => $page
            ->where('attendance.tabs', ['my', 'review', 'overtime', 'summary', 'settings'])->where('attendance.tab', 'overtime')
            ->where('attendance.overtime.ready', false)->where('attendance.overtime.pending', []));
        $ca = $this->actor('ca');
        $this->selectBranch($ca);
        $this->get(route('hr.attendance.index', ['tab' => 'summary']))->assertInertia(fn (Assert $page) => $page->where('attendance.tabs', ['my'])
            ->where('attendance.tab', 'my')->where('attendance.summary', null)->where('attendance.overtime', null)->where('attendance.my.overtime.ready', false));
        $id = (string) Str::uuid();
        $this->post(route('hr.attendance.overtime.decide', [$id, 'approve']), ['expected_lock_version' => 1, 'idempotency_key' => (string) Str::uuid()])->assertForbidden();
        $this->post(route('hr.attendance.overtime.reverse', $id), ['reason' => 'mistake', 'expected_lock_version' => 1, 'idempotency_key' => (string) Str::uuid()])->assertForbidden();
        $this->put(route('hr.attendance.overtime-settings'), $this->settingsInput())->assertForbidden();
        $this->selectBranch($this->actor('queue_display'));
        $this->post(route('hr.attendance.overtime.store'), ['idempotency_key' => (string) Str::uuid()])->assertForbidden();
    }

    public function test_settings_are_clinic_entered_versions_that_name_the_roles(): void
    {
        $hr = $this->actor('hr_manager');
        $ca = $this->actor('ca');
        $this->attendanceSettings($hr);
        $session = $this->closedSession($ca, self::WEEK, '08:00', '16:00');

        $this->ask($ca, $session, 60)->assertSessionHasErrors(['overtime' => 'Overtime is not set up yet. Ask HR.']);
        $this->overtimeSettings($hr, ['nearing_hours' => 45, 'limit_hours' => 45])->assertSessionHasErrors(['nearing_hours' => 'The nearing level must be below the weekly limit.']);
        $this->overtimeSettings($hr, ['normal_multiplier' => '1.555'])->assertSessionHasErrors('normal_multiplier');
        $this->overtimeSettings($hr, ['roles' => ['queue_display']])->assertSessionHasErrors(['roles' => 'Choose the roles that may claim overtime.']);
        $this->overtimeSettings($hr, ['overtime_effective_from' => '2027-03-01'])->assertSessionHasErrors('overtime_effective_from');
        $this->overtimeSettings($hr, ['roles' => ['resident_doctor']])->assertSessionHasNoErrors();
        $this->ask($ca, $session, 60)->assertSessionHasErrors(['overtime' => 'Your role cannot claim overtime. Ask HR if this is wrong.']);
        $key = (string) Str::uuid();
        $this->overtimeSettings($hr, ['roles' => ['ca', 'ca_supervisor']], $key)->assertSessionHasNoErrors();
        $this->overtimeSettings($hr, ['roles' => ['ca', 'ca_supervisor']], $key)->assertSessionHasNoErrors(); // A retry adds nothing.
        $this->assertSame(2, DB::table('hr_overtime_settings')->count());
        $this->assertSame(['ca', 'ca_supervisor'], DB::table('hr_overtime_setting_roles')->where('overtime_setting_id', DB::table('hr_overtime_settings')->max('id'))->orderBy('role_name')->pluck('role_name')->all());
        $this->get(route('hr.attendance.index', ['tab' => 'settings']))->assertInertia(fn (Assert $page) => $page
            ->where('attendance.settings.overtime.current.roles', ['ca', 'ca_supervisor'])->where('attendance.settings.overtime.current.normalMultiplier', '1.50')
            ->where('attendance.settings.overtime.suggested.limitHours', 45)->has('attendance.settings.overtime.history', 2));
        $this->ask($ca, $session, 60)->assertSessionHasNoErrors();
        $this->assertRefused('P0001', fn () => DB::table('hr_overtime_settings')->update(['limit_hours' => 50]));
        $this->assertRefused('P0001', fn () => DB::table('hr_overtime_setting_roles')->delete());
        $this->assertRefused('23514', fn () => DB::table('hr_overtime_settings')->insert(['organisation_id' => $hr->organisation_id, 'effective_from' => self::WEEK,
            'normal_multiplier' => 0.5, 'rest_day_multiplier' => 2, 'public_holiday_multiplier' => 3, 'nearing_hours' => 40, 'limit_hours' => 45, 'created_by_user_id' => $hr->id, 'created_at' => now()]));
    }

    public function test_a_normal_day_allows_the_time_beyond_the_roster_and_is_decided_by_someone_else(): void
    {
        [$hr, $director] = $this->setUpClinic();
        $ca = $this->actor('ca');
        $this->roster($hr, $director, [[$ca, '08:00', '14:00', 30]]); // 5 h 30 min rostered.
        $this->at('2027-03-08 07:58');
        $this->clock($ca, 'in');
        $this->at('2027-03-08 16:00');
        $this->clock($ca, 'out'); // 8 h 2 min less the 30-minute break: 7 h 32 min worked, so 122 minutes beyond the roster.
        $session = ClockSession::query()->where('user_id', $ca->id)->firstOrFail();

        $this->selectBranch($ca);
        $this->get(route('hr.attendance.index'))->assertInertia(fn (Assert $page) => $page->where('attendance.my.overtime.eligible', true)
            ->where('attendance.my.overtime.choices.0.sessionId', $session->public_id)->where('attendance.my.overtime.choices.0.max', 122)
            ->where('attendance.my.overtime.choices.0.dayLabel', 'Normal day')->where('attendance.my.overtime.choices.0.worked', '7 h 32 min')
            ->where('attendance.my.overtime.choices.0.rostered', '5 h 30 min'));
        $this->ask($ca, $session, 123)->assertSessionHasErrors(['minutes' => 'Enter whole minutes from 1 to 122.']);
        $this->ask($ca, $session, 60, ['reason_code' => 'bogus'])->assertSessionHasErrors(['reason_code' => 'Choose a reason.']);
        $this->ask($ca, $session, 9999, ['reason_code' => ''])->assertSessionHasErrors(['reason_code' => 'Choose a reason.', 'minutes' => 'Enter whole minutes from 1 to 122.']); // Both in one submit (Daniel).
        $key = (string) Str::uuid();
        $this->ask($ca, $session, 120, ['note' => 'Synthetic note: stayed for the last patient.'], $key)->assertSessionHasNoErrors()->assertSessionHas('inertia.flash_data.toast.message', 'Overtime request sent.');
        $this->ask($ca, $session, 120, ['note' => 'Synthetic note: stayed for the last patient.'], $key)->assertSessionHasNoErrors();
        $this->assertSame(1, OvertimeRequest::query()->count());
        $this->ask($ca, $session, 30)->assertSessionHasErrors(['session_id' => 'Overtime was already asked for on this day.']);
        $request = OvertimeRequest::query()->firstOrFail();
        $this->assertSame(['pending', 'normal', 120], [$request->status, $request->day_type, $request->minutes_requested]);
        $this->assertStringNotContainsString('Synthetic note', (string) json_encode(DB::table('audit_logs')->where('event', 'like', 'hr.overtime.%')->get()));

        $this->selectBranch($ca);
        $this->decide($ca, $request, 'approve')->assertForbidden(); // Not an approver, and never one's own.
        $this->decide($hr, $request, 'approve', ['minutes' => 90])->assertSessionHasErrors(['reason' => 'Choose why fewer minutes are approved.']);
        $this->decide($hr, $request, 'approve', ['minutes' => 121])->assertSessionHasErrors('minutes');
        $this->decide($hr, $request, 'approve', ['minutes' => 90, 'reason' => 'not_needed'])->assertSessionHasNoErrors();
        $request->refresh();
        $this->assertSame(['approved', 90, '1.50', 'not_needed', $hr->id], [$request->status, $request->minutes_approved, $request->multiplier, $request->decision_reason, $request->decided_by_user_id]);
        $this->decide($director, $request, 'approve')->assertSessionHasErrors(['overtime' => 'This request was already decided. Reload the page.']);
        $this->selectBranch($ca);
        $this->get(route('hr.attendance.index'))->assertInertia(fn (Assert $page) => $page->where('attendance.my.overtime.choices', [])
            ->where('attendance.my.overtime.requests.0.statusLabel', 'Approved 1 h 30 min of 2 h')->where('attendance.my.overtime.requests.0.approvedLabel', '1 h 30 min')
            ->where('attendance.my.overtime.month.figures.overtime', '1 h 30 min (normal day)'));

        $this->reverse($hr, $request, ['reason' => 'nope'])->assertSessionHasErrors(['reason' => 'Choose a reason.']);
        $this->reverse($director, $request)->assertSessionHasNoErrors();
        $this->assertSame(['reversed', $director->id, 'mistake'], [$request->refresh()->status, $request->reversed_by_user_id, $request->reverse_reason]);
        $this->reverse($director, $request)->assertSessionHasErrors(['overtime' => 'Only approved overtime can be reversed. Reload the page.']);
        $this->ask($ca, $session, 60)->assertSessionHasNoErrors(); // A reversed day may be asked for again.
        $this->assertSame(['hr.overtime.approved', 'hr.overtime.requested', 'hr.overtime.requested', 'hr.overtime.reversed'],
            DB::table('audit_logs')->where('event', 'like', 'hr.overtime.%')->where('event', '<>', 'hr.overtime.settings_saved')->orderBy('event')->pluck('event')->all());
    }

    public function test_the_two_person_rule_for_on_behalf_hr_manager_and_director_requests(): void
    {
        [$hr, $director] = $this->setUpClinic(['ca', 'hr_manager', 'director']);
        $ca = $this->actor('ca');
        $secondDirector = $this->actor('director');
        $caDay = $this->closedSession($ca, self::WEEK, '08:00', '17:00');
        $hrDay = $this->closedSession($hr, self::WEEK, '08:00', '17:00');
        $directorDay = $this->closedSession($director, self::WEEK, '08:00', '17:00');
        $this->at('2027-03-08 18:00');

        // On behalf from Review: a staff member must be named, and the requester cannot decide it.
        $this->ask($hr, $caDay, 60, ['tab' => 'review'])->assertSessionHasErrors(['user_id' => 'Choose a staff member.']);
        $this->selectBranch($hr);
        $this->get(route('hr.attendance.index', ['tab' => 'review', 'date' => self::WEEK]))->assertInertia(fn (Assert $page) => $page
            ->where('attendance.review.rows', fn ($rows) => collect($rows)->firstWhere('userId', $ca->id)['overtimeChoice']['max'] === 540
                && collect($rows)->firstWhere('userId', $hr->id)['overtimeChoice'] === null));
        $this->ask($hr, $caDay, 60, ['tab' => 'review', 'user_id' => $ca->id])->assertSessionHasNoErrors();
        $onBehalf = OvertimeRequest::query()->where('user_id', $ca->id)->firstOrFail();
        $this->assertSame($hr->id, $onBehalf->requested_by_user_id);
        $this->decide($hr, $onBehalf, 'approve')->assertForbidden();
        $this->withdraw($ca, $onBehalf)->assertForbidden(); // Only the requester withdraws.
        $this->withdraw($hr, $onBehalf, ['tab' => 'overtime'])->assertSessionHasNoErrors();
        $this->assertSame('withdrawn', $onBehalf->refresh()->status);

        // An HR Manager's own overtime goes to the Director; a Director's to the HR Manager or another Director.
        $this->ask($hr, $hrDay, 60)->assertSessionHasNoErrors();
        $own = OvertimeRequest::query()->where('user_id', $hr->id)->firstOrFail();
        $this->decide($hr, $own, 'approve')->assertForbidden();
        $this->decide($director, $own, 'reject', ['reason' => 'x'])->assertSessionHasErrors(['reason' => 'Choose a reason.']);
        $this->decide($director, $own, 'reject', ['reason' => 'not_agreed'])->assertSessionHasNoErrors();
        $this->assertSame(['rejected', 'not_agreed'], [$own->refresh()->status, $own->decision_reason]);
        $this->ask($director, $directorDay, 30)->assertSessionHasNoErrors();
        $directors = OvertimeRequest::query()->where('user_id', $director->id)->firstOrFail();
        $this->decide($director, $directors, 'approve')->assertForbidden();
        $this->decide($secondDirector, $directors, 'approve')->assertSessionHasNoErrors();
        $this->reverse($director, $directors)->assertForbidden(); // Never one's own.
        $this->assertSame('approved', $directors->refresh()->status);

        $this->assertRefused('23514', fn () => DB::table('hr_overtime_requests')->where('id', $directors->id)->update(['status' => 'reversed', 'reversed_by_user_id' => $director->id,
            'reversed_at' => now(), 'reverse_reason' => 'mistake']));
        $this->assertRefused('P0001', fn () => DB::table('hr_overtime_requests')->where('id', $own->id)->update(['status' => 'approved']));
        $this->assertRefused('P0001', fn () => DB::table('hr_overtime_requests')->where('id', $directors->id)->update(['minutes_approved' => 20]));
        $this->assertRefused('P0001', fn () => DB::table('hr_overtime_requests')->where('id', $own->id)->delete());
        $this->assertRefused('P0001', fn () => DB::statement('TRUNCATE hr_overtime_requests CASCADE'));
        $this->assertRefused('23505', fn () => DB::table('hr_overtime_requests')->insert($this->row($director, $directorDay, $director)));
        $this->assertRefused('23514', fn () => DB::table('hr_overtime_requests')->insert([...$this->row($ca, $caDay, $ca), 'status' => 'approved']));
        $this->assertRefused('23514', fn () => DB::table('hr_overtime_requests')->insert([...$this->row($ca, $hrDay, $ca)])); // Someone else's session.
    }

    public function test_rest_days_public_holidays_and_days_off_the_roster_allow_all_the_worked_time(): void
    {
        [$hr, $director] = $this->setUpClinic();
        $ca = $this->actor('ca');
        $this->roster($hr, $director, [[$ca, '08:00', '14:00', 30]]);
        DB::table('hr_branch_working_days')->insert(['organisation_id' => $hr->organisation_id, 'branch_id' => $this->branch->id, 'weekdays' => json_encode([1, 2, 3, 4, 5]),
            'updated_by_user_id' => $hr->id, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('hr_public_holidays')->insert(['organisation_id' => $hr->organisation_id, 'branch_id' => $this->branch->id, 'holiday_date' => '2027-03-10',
            'name' => 'Synthetic Holiday', 'created_by_user_id' => $hr->id, 'created_at' => now(), 'updated_at' => now()]);
        $monday = $this->closedSession($ca, self::WEEK, '08:00', '14:00'); // Exactly the roster (5 h 30 min after the break): nothing beyond it.
        $tuesday = $this->closedSession($ca, '2027-03-09', '09:00', '12:00'); // Not on the roster on a normal day: all of it.
        $holiday = $this->closedSession($ca, '2027-03-10', '09:00', '13:00');
        $saturday = $this->closedSession($ca, '2027-03-13', '10:00', '12:30');
        $this->at('2027-03-14 09:00');

        $this->selectBranch($ca);
        $this->get(route('hr.attendance.index'))->assertInertia(fn (Assert $page) => $page->where('attendance.my.overtime.choices', fn ($choices) => collect($choices)
            ->mapWithKeys(fn ($c) => [$c['sessionId'] => [$c['max'], $c['dayLabel']]])->all() === [
                $tuesday->public_id => [180, 'Normal day'], $holiday->public_id => [240, 'Public holiday: Synthetic Holiday'], $saturday->public_id => [150, 'Rest day']]));
        $this->ask($ca, $monday, 1)->assertSessionHasErrors(['session_id' => 'There is no time beyond the roster on this day.']);
        $this->ask($ca, $holiday, 240)->assertSessionHasNoErrors();
        $this->ask($ca, $saturday, 150)->assertSessionHasNoErrors();
        $holidayRequest = OvertimeRequest::query()->where('clock_session_id', $holiday->id)->firstOrFail();
        $this->decide($hr, $holidayRequest, 'approve')->assertSessionHasNoErrors();
        $this->decide($hr, OvertimeRequest::query()->where('clock_session_id', $saturday->id)->firstOrFail(), 'approve')->assertSessionHasNoErrors();
        $this->assertSame(['public_holiday', '3.00', 240], [$holidayRequest->refresh()->day_type, $holidayRequest->multiplier, $holidayRequest->minutes_approved]);
        $this->assertSame(['rest_day', '2.00'], OvertimeRequest::query()->where('clock_session_id', $saturday->id)->get(['day_type', 'multiplier'])->map(fn ($r) => [$r->day_type, $r->multiplier])->first());

        $this->selectBranch($hr);
        $this->get(route('hr.attendance.index', ['tab' => 'summary', 'period' => 'week', 'date' => '2027-03-10']))->assertInertia(fn (Assert $page) => $page
            ->where('attendance.summary.periodLabel', '8 Mar 2027 to 14 Mar 2027')
            ->where('attendance.summary.rows', fn ($rows) => ($row = collect($rows)->firstWhere('userId', $ca->id)) !== null
                && $row['rostered'] === '5 h 30 min' && $row['worked'] === '15 h' && $row['overtime'] === '2 h 30 min (rest day) · 4 h (public holiday)'
                && $row['late'] === '0' && $row['otherBranch'] === false)
            ->where('attendance.summary.total.overtime', '2 h 30 min (rest day) · 4 h (public holiday)'));
    }

    public function test_a_waiting_request_says_when_it_was_sent(): void
    {
        // For the HR space's "oldest waiting" line (Daniel, Kavitha): when the request was sent, ISO and in UTC.
        [$hr, $director] = $this->setUpClinic();
        $ca = $this->actor('ca');
        $this->roster($hr, $director, [[$ca, '08:00', '14:00', 30]]);
        $tuesday = $this->closedSession($ca, '2027-03-09', '09:00', '12:00'); // Not on the roster: all of it may be asked for.
        $this->at('2027-03-14 09:00'); // 01:00 UTC
        $this->ask($ca, $tuesday, 60)->assertSessionHasNoErrors();
        $this->selectBranch($hr);
        $this->get(route('hr.attendance.index', ['tab' => 'overtime']))->assertInertia(fn (Assert $page) => $page
            ->where('attendance.overtime.pending.0.sentAt', '2027-03-14T01:00:00+00:00'));
    }

    public function test_only_time_outside_the_shift_window_counts_so_a_late_arrival_never_gains_the_break(): void
    {
        [$hr, $director] = $this->setUpClinic();
        $late = $this->actor('ca');
        $early = $this->actor('ca');
        $this->roster($hr, $director, [[$late, '08:00', '14:00', 30], [$early, '08:00', '14:00', 30]]);
        $lateDay = $this->closedSession($late, self::WEEK, '08:10', '14:00'); // Late, left on time: 5 h 50 min worked (break not taken off), nothing beyond.
        $earlyDay = $this->closedSession($early, self::WEEK, '07:30', '13:45'); // 30 min early, 15 min early out: 30 min beyond.
        $this->at('2027-03-08 18:00');

        $this->ask($late, $lateDay, 1)->assertSessionHasErrors(['session_id' => 'There is no time beyond the roster on this day.']);
        $this->selectBranch($late);
        $this->get(route('hr.attendance.index'))->assertInertia(fn (Assert $page) => $page->where('attendance.my.overtime.choices', []));
        $this->ask($early, $earlyDay, 31)->assertSessionHasErrors(['minutes' => 'Enter whole minutes from 1 to 30.']);
        $this->ask($early, $earlyDay, 30)->assertSessionHasNoErrors();

        // From Review a manager never asks for their own day.
        $hrDay = $this->closedSession($hr, self::WEEK, '08:00', '17:00');
        $this->ask($hr, $hrDay, 30, ['tab' => 'review', 'user_id' => $hr->id])->assertSessionHasErrors(['user_id' => 'Choose a staff member.']);
    }

    public function test_the_window_open_sessions_and_waiting_corrections_block_a_request(): void
    {
        [$hr] = $this->setUpClinic();
        $ca = $this->actor('ca');
        $old = $this->closedSession($ca, self::WEEK, '08:00', '16:00');
        $this->at('2027-03-16 09:00'); // Eight days later; the window is seven.
        $this->ask($ca, $old, 60)->assertSessionHasErrors(['session_id' => 'Overtime can be asked for back to 9 Mar 2027. Ask HR about older days.']);

        $this->at('2027-03-09 08:00');
        $this->clock($ca, 'in');
        $open = ClockSession::query()->where('status', 'open')->firstOrFail();
        $this->ask($ca, $open, 60)->assertSessionHasErrors(['session_id' => 'Clock out, or add the clock-out time, before asking for overtime.']);
        $this->at('2027-03-09 18:00');
        $this->clock($ca, 'out');
        $this->selectBranch($ca);
        $this->post(route('hr.attendance.corrections.store'), ['kind' => 'set_out', 'session_id' => $open->public_id, 'out_time' => '2027-03-09T17:00', 'reason_code' => 'wrong_time',
            'idempotency_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
        $this->ask($ca, $open, 60)->assertSessionHasErrors(['session_id' => 'This day has a correction waiting. Ask for overtime once it is decided.']);
        $this->ask($ca, $open, 60, ['session_id' => 'not-a-uuid'])->assertSessionHasErrors(['session_id' => 'Choose the day.']);
    }

    public function test_a_correction_after_approval_shows_hours_changed_and_a_lower_cap_at_the_decision(): void
    {
        [$hr, $director] = $this->setUpClinic();
        $ca = $this->actor('ca');
        $day = $this->closedSession($ca, self::WEEK, '08:00', '17:00'); // Not on the roster: 540 minutes.
        $other = $this->closedSession($ca, '2027-03-09', '08:00', '12:00');
        $this->at('2027-03-09 20:00');
        $this->ask($ca, $day, 540)->assertSessionHasNoErrors();
        $this->ask($ca, $other, 240)->assertSessionHasNoErrors();
        $request = OvertimeRequest::query()->where('clock_session_id', $day->id)->firstOrFail();
        $this->decide($hr, $request, 'approve')->assertSessionHasNoErrors();

        // HR corrects the clock-out to 12:00; the approved minutes stay and the Overtime tab says the hours changed.
        $this->at('2027-03-09 20:05');
        $this->selectBranch($director);
        $this->post(route('hr.attendance.corrections.store'), ['user_id' => $ca->id, 'kind' => 'set_out', 'session_id' => $day->public_id, 'out_time' => '2027-03-08T12:00',
            'reason_code' => 'wrong_time', 'tab' => 'review', 'idempotency_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
        $correction = DB::table('hr_attendance_corrections')->latest('id')->first();
        $this->selectBranch($hr);
        $this->post(route('hr.attendance.corrections.decide', [$correction->public_id, 'approve']), ['expected_lock_version' => 1, 'idempotency_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
        $this->assertSame(540, $request->refresh()->minutes_approved);
        $this->get(route('hr.attendance.index', ['tab' => 'overtime', 'month' => '2027-03']))->assertInertia(fn (Assert $page) => $page
            ->where('attendance.overtime.summary', '1 waiting · 1 approved day changed since')
            ->where('attendance.overtime.decided.0.hoursChanged', true)->where('attendance.overtime.decided.0.changedLabel', 'Recorded 9 h → now 4 h')->where('attendance.overtime.pending.0.week.label', '8 h')); // 4 h on Monday after the correction, 4 h on Tuesday.

        // The other day is corrected while waiting: the decision checks the new hours.
        $this->selectBranch($director);
        $this->post(route('hr.attendance.corrections.store'), ['user_id' => $ca->id, 'kind' => 'set_out', 'session_id' => $other->public_id, 'out_time' => '2027-03-09T10:00',
            'reason_code' => 'wrong_time', 'tab' => 'review', 'idempotency_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
        $correction = DB::table('hr_attendance_corrections')->latest('id')->first();
        $this->selectBranch($hr);
        $this->post(route('hr.attendance.corrections.decide', [$correction->public_id, 'approve']), ['expected_lock_version' => 1, 'idempotency_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
        $waiting = OvertimeRequest::query()->where('clock_session_id', $other->id)->firstOrFail();
        $this->decide($hr, $waiting, 'approve')->assertSessionHasErrors(['minutes' => 'The hours on this day changed. Approve at most 120 minutes.']);
        $this->decide($hr, $waiting, 'approve', ['minutes' => 120, 'reason' => 'more_than_agreed'])->assertSessionHasNoErrors();
        $this->assertSame(120, $waiting->refresh()->minutes_approved);
    }

    public function test_weekly_hours_warn_near_and_past_the_limit_and_never_block(): void
    {
        [$hr] = $this->setUpClinic();
        $ca = $this->actor('ca');
        $forgetful = $this->actor('ca');
        foreach (['2027-03-08', '2027-03-09', '2027-03-10', '2027-03-11'] as $date) {
            $this->closedSession($ca, $date, '08:00', '18:00'); // 10 h a day, no roster.
        }
        $this->at('2027-03-12 20:00');
        $this->selectBranch($ca);
        $this->get(route('hr.attendance.index'))->assertInertia(fn (Assert $page) => $page->where('attendance.my.overtime.weekHours.label', '40 h')
            ->where('attendance.my.overtime.weekHours.level', 'nearing')->where('attendance.my.overtime.weekHours.chip', 'Nearing 45 h this week'));
        $this->closedSession($ca, '2027-03-12', '08:00', '13:30');
        $this->get(route('hr.attendance.index'))->assertInertia(fn (Assert $page) => $page->where('attendance.my.overtime.weekHours.level', 'over')
            ->where('attendance.my.overtime.weekHours.label', '45 h 30 min')->where('attendance.my.overtime.weekHours.chip', 'Over 45 h this week'));

        // An open session counts up to now ("so far"); a clock-in is never refused for the hours.
        $this->at('2027-03-13 08:00');
        $this->clock($ca, 'in')->assertSessionHasNoErrors();
        $this->at('2027-03-13 09:15');
        $this->get(route('hr.attendance.index'))->assertInertia(fn (Assert $page) => $page->where('attendance.my.overtime.weekHours.label', '46 h 45 min so far'));
        // A forgotten clock-out counts nothing (Suresh): Monday's clock-in that was never closed adds no hours on Thursday.
        $zone = $this->branch->timezone;
        DB::table('hr_clock_sessions')->insert(['public_id' => (string) Str::uuid(), 'organisation_id' => $forgetful->organisation_id, 'user_id' => $forgetful->id,
            'branch_id' => $this->branch->id, 'local_date' => self::WEEK, 'timezone' => $zone, 'clock_in_at' => CarbonImmutable::parse('2027-03-08 08:00', $zone)->utc(),
            'status' => 'open', 'created_at' => now(), 'updated_at' => now()]);
        $this->selectBranch($forgetful);
        $this->get(route('hr.attendance.index'))->assertInertia(fn (Assert $page) => $page->where('attendance.my.overtime.weekHours.label', '0 min'));
        $this->selectBranch($ca);
        // Next week starts again from zero.
        $this->at('2027-03-15 07:00');
        $this->get(route('hr.attendance.index'))->assertInertia(fn (Assert $page) => $page->where('attendance.my.overtime.weekHours.label', '0 min')
            ->where('attendance.my.overtime.weekHours.chip', null));
    }

    public function test_another_organisations_requests_are_never_reached_and_nothing_else_is_written(): void
    {
        [$hr, $director] = $this->setUpClinic();
        $ca = $this->actor('ca');
        $day = $this->closedSession($ca, self::WEEK, '08:00', '17:00');
        $this->at('2027-03-08 18:00');
        $before = $this->untouched();
        $this->ask($ca, $day, 60)->assertSessionHasNoErrors();
        $this->decide($director, OvertimeRequest::query()->firstOrFail(), 'approve')->assertSessionHasNoErrors();
        $this->assertSame($before, $this->untouched());

        $org = DB::table('organisations')->insertGetId(['code' => 'OTHER_'.Str::upper(Str::random(6)), 'name' => 'Synthetic Other Organisation', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $branch = new Branch;
        $branch->forceFill(['organisation_id' => $org, 'code' => 'OTH'.Str::upper(Str::random(4)), 'name' => 'Synthetic Other Branch', 'timezone' => 'Asia/Kuala_Lumpur', 'is_active' => true])->save();
        $stranger = User::factory()->create(['organisation_id' => $org, 'email' => 'overtime.other.'.Str::lower(Str::random(8)).'@kpone.test']);
        $profile = new StaffProfile;
        $profile->forceFill(['user_id' => $stranger->id, 'department_id' => null])->save();
        StaffBranchAssignmentBootstrapper::create($profile, $branch, ['is_primary' => true, 'assignment_type' => 'permanent', 'valid_from' => '2027-03-01', 'valid_until' => null]);
        $sessionId = (string) Str::uuid();
        $sid = DB::table('hr_clock_sessions')->insertGetId(['public_id' => $sessionId, 'organisation_id' => $org, 'user_id' => $stranger->id, 'branch_id' => $branch->id,
            'local_date' => self::WEEK, 'timezone' => 'Asia/Kuala_Lumpur', 'clock_in_at' => '2027-03-08 00:00:00+00', 'clock_out_at' => '2027-03-08 10:00:00+00', 'status' => 'closed',
            'created_at' => now(), 'updated_at' => now()]);
        $requestId = (string) Str::uuid();
        DB::table('hr_overtime_requests')->insert(['public_id' => $requestId, 'organisation_id' => $org, 'user_id' => $stranger->id, 'requested_by_user_id' => $stranger->id,
            'clock_session_id' => $sid, 'branch_id' => $branch->id, 'local_date' => self::WEEK, 'minutes_requested' => 60, 'reason_code' => 'other', 'day_type' => 'normal',
            'status' => 'pending', 'created_at' => now(), 'updated_at' => now()]);

        $this->decide($hr, null, 'approve', [], $requestId)->assertNotFound();
        $this->reverse($hr, null, [], $requestId)->assertNotFound();
        $this->selectBranch($hr);
        $this->post(route('hr.attendance.overtime.withdraw', $requestId), ['expected_lock_version' => 1, 'idempotency_key' => (string) Str::uuid()])->assertNotFound();
        $this->post(route('hr.attendance.overtime.store'), ['user_id' => $stranger->id, 'session_id' => $sessionId, 'minutes' => 30, 'reason_code' => 'other', 'tab' => 'review',
            'idempotency_key' => (string) Str::uuid()])->assertSessionHasErrors(['user_id' => 'Choose a staff member.']);
        $this->get(route('hr.attendance.index', ['tab' => 'overtime']))->assertInertia(fn (Assert $page) => $page->where('attendance.overtime.pending', []));
        $this->assertSame('pending', DB::table('hr_overtime_requests')->where('public_id', $requestId)->value('status'));
    }

    /**
     * Attendance and overtime settings saved, with the given roles allowed to claim.
     *
     * @param  list<string>  $roles
     * @return array{User, User}
     */
    private function setUpClinic(array $roles = ['ca']): array
    {
        $hr = $this->actor('hr_manager');
        $director = $this->actor('director');
        $this->attendanceSettings($hr);
        $this->overtimeSettings($hr, ['roles' => $roles])->assertSessionHasNoErrors();

        return [$hr, $director];
    }

    private function attendanceSettings(User $hr): void
    {
        $this->selectBranch($hr);
        $this->put(route('hr.attendance.settings'), ['grace_minutes' => 5, 'forgotten_window_minutes' => 60, 'correction_days' => 7, 'effective_from' => self::WEEK,
            'idempotency_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
    }

    /** @return array<string, mixed> */
    private function settingsInput(): array
    {
        return ['normal_multiplier' => '1.5', 'rest_day_multiplier' => '2', 'public_holiday_multiplier' => '3', 'nearing_hours' => 40, 'limit_hours' => 45,
            'roles' => ['ca'], 'overtime_effective_from' => self::WEEK, 'idempotency_key' => (string) Str::uuid()];
    }

    /** @param array<string, mixed> $input */
    private function overtimeSettings(User $actor, array $input = [], ?string $key = null): TestResponse
    {
        $this->selectBranch($actor);

        return $this->put(route('hr.attendance.overtime-settings'), [...$this->settingsInput(), ...$input, 'idempotency_key' => $key ?? (string) Str::uuid()]);
    }

    /** A closed session written directly (the trigger still checks the branch assignment and overlaps). */
    private function closedSession(User $person, string $date, string $in, string $out): ClockSession
    {
        $zone = $this->branch->timezone;
        $session = new ClockSession;
        $session->forceFill(['public_id' => (string) Str::uuid(), 'organisation_id' => $person->organisation_id, 'user_id' => $person->id, 'branch_id' => $this->branch->id,
            'local_date' => $date, 'timezone' => $zone, 'clock_in_at' => CarbonImmutable::parse("{$date} {$in}", $zone)->utc(),
            'clock_out_at' => CarbonImmutable::parse("{$date} {$out}", $zone)->utc(), 'status' => 'closed'])->save();

        return $session->refresh();
    }

    /** @return array<string, mixed> */
    private function row(User $subject, ClockSession $session, User $requester): array
    {
        return ['public_id' => (string) Str::uuid(), 'organisation_id' => $subject->organisation_id, 'user_id' => $subject->id, 'requested_by_user_id' => $requester->id,
            'clock_session_id' => $session->id, 'branch_id' => $session->branch_id, 'local_date' => $session->local_date->toDateString(), 'minutes_requested' => 30,
            'reason_code' => 'other', 'day_type' => 'normal', 'status' => 'pending', 'created_at' => now(), 'updated_at' => now()];
    }

    /** @param array<string, mixed> $input */
    private function ask(User $actor, ClockSession $session, int $minutes, array $input = [], ?string $key = null): TestResponse
    {
        $this->selectBranch($actor);

        return $this->post(route('hr.attendance.overtime.store'), ['session_id' => $session->public_id, 'minutes' => $minutes, 'reason_code' => 'patient_load',
            'idempotency_key' => $key ?? (string) Str::uuid(), ...$input]);
    }

    /** @param array<string, mixed> $input */
    private function decide(User $actor, ?OvertimeRequest $request, string $decision, array $input = [], ?string $id = null): TestResponse
    {
        $this->selectBranch($actor);

        return $this->post(route('hr.attendance.overtime.decide', [$request->public_id ?? $id ?? (string) Str::uuid(), $decision]),
            ['expected_lock_version' => $request?->refresh()->lock_version ?? 1, 'idempotency_key' => (string) Str::uuid(), ...$input]);
    }

    /** @param array<string, mixed> $input */
    private function reverse(User $actor, ?OvertimeRequest $request, array $input = [], ?string $id = null): TestResponse
    {
        $this->selectBranch($actor);

        return $this->post(route('hr.attendance.overtime.reverse', $request->public_id ?? $id ?? (string) Str::uuid()),
            ['reason' => 'mistake', 'expected_lock_version' => $request?->refresh()->lock_version ?? 1, 'idempotency_key' => (string) Str::uuid(), ...$input]);
    }

    /** @param array<string, mixed> $input */
    private function withdraw(User $actor, OvertimeRequest $request, array $input = []): TestResponse
    {
        $this->selectBranch($actor);

        return $this->post(route('hr.attendance.overtime.withdraw', $request->public_id), ['expected_lock_version' => $request->refresh()->lock_version,
            'idempotency_key' => (string) Str::uuid(), ...$input]);
    }

    private function at(string $time): void
    {
        $this->travelTo(CarbonImmutable::parse($time, $this->branch->timezone));
    }

    private function clock(User $actor, string $kind): TestResponse
    {
        $this->selectBranch($actor);

        return $this->post(route($kind === 'in' ? 'hr.attendance.clock-in' : 'hr.attendance.clock-out'), ['idempotency_key' => (string) Str::uuid()]);
    }

    /** @param list<array{User, string, string, int}> $shifts */
    private function roster(User $hr, User $director, array $shifts): void
    {
        $this->selectBranch($hr);
        $this->post(route('hr.roster.drafts.store'), ['branch_id' => $this->branch->id, 'week' => self::WEEK, 'mode' => 'blank', 'idempotency_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
        $version = RosterVersion::query()->latest('id')->firstOrFail();
        foreach ($shifts as [$person, $start, $end, $break]) {
            $this->post(route('hr.roster.shifts.store', $version->public_id), ['staff_user_id' => $person->id, 'date' => self::WEEK, 'start_time' => $start, 'end_time' => $end,
                'break_minutes' => $break, 'session_type' => 'full_day', 'branch_room_id' => null, 'branch_id' => $this->branch->id, 'week' => self::WEEK,
                'expected_version_lock' => $version->refresh()->lock_version, 'idempotency_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
        }
        $this->post(route('hr.roster.submit', $version->public_id), ['expected_version_lock' => $version->refresh()->lock_version, 'idempotency_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
        $this->selectBranch($director);
        $this->post(route('hr.roster.approve', $version->public_id), ['expected_version_lock' => $version->refresh()->lock_version, 'idempotency_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
    }

    private function assertRefused(string $state, callable $write): void
    {
        try {
            DB::transaction(fn () => $write());
            $this->fail("Expected PostgreSQL to refuse with {$state}.");
        } catch (QueryException $e) {
            $this->assertSame($state, $e->errorInfo[0] ?? null, $e->getMessage());
        }
    }

    /** @return array<string, mixed> */
    private function untouched(): array
    {
        return json_decode((string) json_encode([
            'users' => DB::table('users')->orderBy('id')->get(['id', 'is_active', 'organisation_id'])->toArray(),
            'roles' => DB::table('model_has_roles')->orderBy('model_id')->orderBy('role_id')->get()->toArray(),
            'assignments' => DB::table('staff_branch_assignments')->orderBy('id')->get()->toArray(),
            'sessions' => DB::table('hr_clock_sessions')->orderBy('id')->get()->toArray(),
            'events' => DB::table('hr_clock_events')->count(),
            'shifts' => DB::table('hr_roster_shifts')->count(),
            'leave' => DB::table('hr_leave_requests')->count(),
            'visits' => DB::table('visits')->count(),
            'queue' => DB::table('queue_entries')->count(),
        ]), true);
    }
}
