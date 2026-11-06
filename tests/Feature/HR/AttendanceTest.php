<?php

namespace Tests\Feature\HR;

use App\Domain\Access\ClinicManagementNavigation;
use App\Domain\Access\PermissionCatalogue;
use App\Domain\HR\Attendance\ClockPermissions;
use App\Domain\HR\Attendance\Models\AttendanceCorrection;
use App\Domain\HR\Attendance\Models\ClockSession;
use App\Domain\HR\Roster\Models\RosterVersion;
use App\Domain\HR\Roster\RosterPermissions;
use App\Domain\Organisation\Models\Branch;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Role;
use Tests\Feature\Visit\VisitTestCase;

/**
 * HR-3a attendance (owner, 2026-11-06). The clock is frozen in Kuala Lumpur on Monday 8 Mar 2027, so every date is
 * branch-local and never the UTC clock (BASELINE section 6 item 21).
 */
class AttendanceTest extends VisitTestCase
{
    private const WEEK = '2027-03-08';

    protected function setUp(): void
    {
        parent::setUp();
        $this->at('2027-03-08 07:00');
    }

    public function test_grants_are_exact_and_the_tv_account_gets_none(): void
    {
        $roles = PermissionCatalogue::roles();
        foreach (ClockPermissions::grants() as $permission => $holders) {
            $actual = collect($roles)->filter(fn (array $p) => in_array($permission, $p, true))->keys()->sort()->values()->all();
            $this->assertSame(collect($holders)->sort()->values()->all(), $actual, $permission);
            $this->assertFalse(PermissionCatalogue::isAdministrativeAuthority($permission));
            $this->assertEqualsCanonicalizing($holders, Role::query()->whereHas('permissions', fn ($q) => $q->where('name', $permission))->pluck('name')->all(), "seeded {$permission}");
        }
        $this->assertEqualsCanonicalizing(RosterPermissions::HUMAN_ROLES, ClockPermissions::grants()[ClockPermissions::CLOCK_OWN]);
        $this->assertSame(['director', 'hr_manager'], ClockPermissions::grants()[ClockPermissions::REVIEW]);
        $links = collect(app(ClinicManagementNavigation::class)->groups($this->actor('ca')))->flatMap(fn (array $g) => $g['links'])->pluck('href');
        $this->assertContains('/hr/attendance', $links->all());
    }

    /** @return list<array{string, int, list<string>}> */
    public static function viewers(): array
    {
        return [['director', 200, ['my', 'review', 'settings']], ['hr_manager', 200, ['my', 'review', 'settings']], ['ca', 200, ['my']],
            ['resident_doctor', 200, ['my']], ['finance_officer', 200, ['my']], ['technical_admin', 200, ['my']], ['queue_display', 403, []]];
    }

    /** @param list<string> $tabs */
    #[DataProvider('viewers')]
    public function test_who_sees_which_tab(string $role, int $status, array $tabs): void
    {
        $this->selectBranch($this->actor($role));
        $response = $this->get(route('hr.attendance.index'))->assertStatus($status);
        if ($status === 200) {
            $response->assertInertia(fn (Assert $page) => $page->component('HR/Attendance')->where('attendance.tabs', $tabs));
            foreach (['review', 'settings'] as $tab) {
                if (! in_array($tab, $tabs, true)) {
                    $this->get(route('hr.attendance.index', ['tab' => $tab]))->assertInertia(fn (Assert $page) => $page->where('attendance.tab', 'my')
                        ->where('attendance.review', null)->where('attendance.settings', null));
                }
            }
        }
        foreach (['hr.attendance.settings' => 'put', 'hr.attendance.clock-in' => 'post'] as $route => $method) {
            if ($status === 403 || ($route === 'hr.attendance.settings' && ! in_array('settings', $tabs, true))) {
                $this->{$method}(route($route), ['idempotency_key' => (string) Str::uuid()])->assertForbidden();
            }
        }
    }

    public function test_clock_in_and_out_record_server_time_only_and_refuse_double_taps(): void
    {
        $ca = $this->actor('ca');
        $key = (string) Str::uuid();
        $this->clock($ca, 'in', $key)->assertSessionHasNoErrors();
        $this->clock($ca, 'in', $key)->assertSessionHasNoErrors(); // A retried request returns the same press.
        $session = ClockSession::query()->sole();
        $this->assertSame('open', $session->status);
        $this->assertSame(self::WEEK, $session->local_date->toDateString());
        $this->assertSame('2027-03-07 23:00:00', $session->clock_in_at->utc()->format('Y-m-d H:i:s'));
        $this->assertSame(1, DB::table('hr_clock_events')->where('kind', 'in')->where('method', 'button')->count());
        $this->clock($ca, 'in')->assertSessionHasErrors(['clock' => 'You are already clocked in since 7:00 AM.']);

        $this->at('2027-03-08 07:00:30');
        $this->clock($ca, 'out')->assertSessionHasErrors(['clock' => 'You clocked in less than a minute ago.']);
        $this->at('2027-03-08 15:04');
        $this->selectBranch($ca);
        $this->get(route('hr.attendance.index'))->assertInertia(fn (Assert $page) => $page->where('attendance.my.state', 'in')
            ->where('attendance.my.clockedInLabel', 'Clocked in at 7:00 AM'));
        $this->clock($ca, 'out')->assertSessionHasNoErrors();
        $this->assertSame('closed', $session->refresh()->status);
        $this->get(route('hr.attendance.index'))->assertInertia(fn (Assert $page) => $page->where('attendance.my.state', 'done')
            ->where('attendance.my.doneLabel', 'Done for today: 7:00 AM – 3:04 PM, 8 h 4 min'));
        $this->at('2027-03-08 15:04:40');
        $this->clock($ca, 'in')->assertSessionHasErrors(['clock' => 'You clocked out less than a minute ago.']);

        // No location, IP, photo or device data anywhere in the stored rows.
        $columns = collect(['hr_clock_sessions', 'hr_clock_events'])->flatMap(fn (string $t) => DB::getSchemaBuilder()->getColumnListing($t));
        $this->assertEmpty($columns->filter(fn (string $c) => preg_match('/ip|lat|lng|location|photo|device|agent/i', $c) === 1)->all());
        $this->assertSame(['local_date'], array_keys(json_decode((string) DB::table('audit_logs')->where('event', 'hr.attendance.clocked_in')->value('metadata'), true)));
    }

    public function test_clock_in_needs_a_branch_the_person_works_at(): void
    {
        $director = $this->actor('director');
        $puchong = Branch::query()->where('organisation_id', $this->organisation->id)->where('code', 'PUCHONG')->firstOrFail();
        $this->selectBranch($director, $puchong);
        $this->post(route('hr.attendance.clock-in'), ['idempotency_key' => (string) Str::uuid()])->assertSessionHasErrors(['clock' => 'Choose a branch you work at in the top bar.']);
        $this->get(route('hr.attendance.index'))->assertInertia(fn (Assert $page) => $page->where('attendance.my.canPress', false));
        $this->assertSame(0, ClockSession::query()->count());
    }

    public function test_a_night_shift_stays_on_its_start_day_and_a_stale_clock_in_needs_a_correction(): void
    {
        $ca = $this->actor('ca');
        $this->at('2027-03-08 22:00');
        $this->clock($ca, 'in')->assertSessionHasNoErrors();
        $this->at('2027-03-09 06:00');
        $this->clock($ca, 'out')->assertSessionHasNoErrors();
        $this->assertSame(self::WEEK, ClockSession::query()->sole()->local_date->toDateString());

        $this->at('2027-03-09 21:00');
        $this->clock($ca, 'in')->assertSessionHasNoErrors();
        $this->at('2027-03-10 14:00');
        $this->clock($ca, 'out')->assertSessionHasErrors(['clock' => 'Your clock-in on 9 Mar 2027 is over 16 hours old. Add your clock-out time as a correction.']);
        $this->selectBranch($ca);
        $this->get(route('hr.attendance.index'))->assertInertia(fn (Assert $page) => $page->where('attendance.my.state', 'out')
            ->where('attendance.my.staleLabel', 'Your clock-in on 9 Mar 2027 has no clock-out. Add your clock-out time below.'));
        $this->clock($ca, 'in')->assertSessionHasNoErrors(); // The stale session stops blocking: it is marked as missing its clock-out.
        $this->assertSame(['closed', 'missing_out', 'open'], ClockSession::query()->orderBy('id')->pluck('status')->all());
        $this->assertSame(1, DB::table('audit_logs')->where('event', 'hr.attendance.missing_clock_out')->count());
    }

    public function test_flags_compare_the_approved_roster_with_the_clock_once_settings_are_saved(): void
    {
        $hr = $this->actor('hr_manager');
        $director = $this->actor('director');
        $ca = $this->actor('ca');
        $absent = $this->actor('ca');
        $extra = $this->actor('ca');
        $this->roster($hr, $director, [[$ca, '08:00', '14:00', 30], [$absent, '08:00', '14:00', 0]]);

        $this->at('2027-03-08 08:12');
        $this->clock($ca, 'in')->assertSessionHasNoErrors();
        $this->clock($extra, 'in')->assertSessionHasNoErrors();
        $this->at('2027-03-08 13:40');
        $this->clock($ca, 'out')->assertSessionHasNoErrors();
        $this->at('2027-03-08 16:00');

        // Before settings: times only, no flags.
        $this->selectBranch($hr);
        $this->get(route('hr.attendance.index', ['tab' => 'review', 'date' => self::WEEK]))->assertInertia(fn (Assert $page) => $page
            ->where('attendance.review.settingsReady', false)
            ->where('attendance.review.rows', fn ($rows) => collect($rows)->every(fn ($r) => $r['flags'] === [])));

        $this->settings($hr, ['effective_from' => self::WEEK])->assertSessionHasNoErrors();
        $rows = collect($this->get(route('hr.attendance.index', ['tab' => 'review', 'date' => self::WEEK]))->viewData('page')['props']['attendance']['review']['rows'])->keyBy('userId');
        $this->assertSame(['Late 12 min', 'Left early 20 min'], collect($rows[$ca->id]['flags'])->pluck('label')->all());
        $this->assertSame('5 h 28 min', $rows[$ca->id]['worked']);
        $this->assertSame(['No clock-in'], collect($rows[$absent->id]['flags'])->pluck('label')->all());
        $this->assertSame(['Not on the roster'], collect($rows[$extra->id]['flags'])->pluck('label')->all());
        $this->get(route('hr.attendance.index', ['tab' => 'review', 'date' => self::WEEK]))->assertInertia(fn (Assert $page) => $page
            ->where('attendance.review.summary', '3 flags · 0 corrections waiting'));

        // An open session with no shift shows "No clock-out" 16 hours after its clock-in; it is never closed automatically.
        $this->at('2027-03-09 00:30');
        $this->selectBranch($hr);
        $rows = collect($this->get(route('hr.attendance.index', ['tab' => 'review', 'date' => self::WEEK]))->viewData('page')['props']['attendance']['review']['rows'])->keyBy('userId');
        $this->assertContains('No clock-out', collect($rows[$extra->id]['flags'])->pluck('label')->all());
        $this->assertSame('open', ClockSession::query()->where('user_id', $extra->id)->value('status'));

        // Grace minutes: a later version from a future date never rewrites today's flags.
        $this->settings($hr, ['grace_minutes' => 30, 'effective_from' => '2027-03-10'])->assertSessionHasNoErrors();
        $rows = collect($this->get(route('hr.attendance.index', ['tab' => 'review', 'date' => self::WEEK]))->viewData('page')['props']['attendance']['review']['rows'])->keyBy('userId');
        $this->assertSame(['Late 12 min', 'Left early 20 min'], collect($rows[$ca->id]['flags'])->pluck('label')->all());
    }

    public function test_corrections_are_decided_by_someone_other_than_the_subject_and_the_proposer(): void
    {
        $hr = $this->actor('hr_manager');
        $director = $this->actor('director');
        $ca = $this->actor('ca');
        $this->settings($hr)->assertSessionHasNoErrors();
        $this->at('2027-03-08 08:05');
        $this->clock($ca, 'in')->assertSessionHasNoErrors();
        $session = ClockSession::query()->sole();
        $this->at('2027-03-09 10:00');

        // The staff member proposes their own clock-out; they cannot decide it (no review permission).
        $this->propose($ca, ['kind' => 'set_out', 'session_id' => $session->public_id, 'out_time' => '2027-03-08T17:00', 'reason_code' => 'forgot',
            'note' => 'Left in a hurry'])->assertSessionHasNoErrors();
        $correction = AttendanceCorrection::query()->sole();
        $this->selectBranch($ca);
        $this->post(route('hr.attendance.corrections.decide', [$correction->public_id, 'approve']), ['expected_lock_version' => 1, 'idempotency_key' => (string) Str::uuid()])->assertForbidden();
        $this->propose($ca, ['kind' => 'set_out', 'session_id' => $session->public_id, 'out_time' => '2027-03-08T17:30', 'reason_code' => 'forgot'])
            ->assertSessionHasErrors(['attendance' => 'This day already has a correction waiting.']);
        $this->selectBranch($ca);
        $this->get(route('hr.attendance.index'))->assertInertia(fn (Assert $page) => $page
            ->where('attendance.my.corrections.0.compare', 'Recorded: none · New: 5:00 PM')
            ->where('attendance.my.corrections.0.statusLabel', 'Correction waiting'));

        $this->decide($hr, $correction, 'approve')->assertSessionHasNoErrors();
        $session->refresh();
        $this->assertSame('closed', $session->status);
        $this->assertSame('2027-03-08 09:00:00', $session->clock_out_at?->utc()->format('Y-m-d H:i:s'));
        $this->assertSame(['button', 'correction'], DB::table('hr_clock_events')->where('clock_session_id', $session->id)->orderBy('id')->pluck('method')->all());
        $this->assertStringNotContainsString('hurry', (string) DB::table('audit_logs')->where('event', 'like', 'hr.attendance.%')->pluck('metadata')->implode(' '));
        $this->assertNotSame('Left in a hurry', DB::table('hr_attendance_corrections')->value('note_encrypted'));

        // HR proposes on the CA's behalf: HR cannot decide what they proposed; the Director does.
        $this->propose($hr, ['user_id' => $ca->id, 'kind' => 'change_in', 'session_id' => $session->public_id, 'in_time' => '2027-03-08T08:00', 'reason_code' => 'system_problem'])->assertSessionHasNoErrors();
        $behalf = AttendanceCorrection::query()->latest('id')->firstOrFail();
        $this->decide($hr, $behalf, 'approve')->assertForbidden();
        $this->decide($director, $behalf, 'reject')->assertSessionHasErrors(['reason' => 'Choose a reason.']);
        $this->decide($director, $behalf, 'reject', ['reason' => 'not_confirmed'])->assertSessionHasNoErrors();
        $this->assertSame('rejected', $behalf->refresh()->status);
        $this->assertSame('2027-03-08 00:05:00', ClockSession::query()->sole()->clock_in_at->utc()->format('Y-m-d H:i:s')); // A rejection changes nothing.

        // An HR Manager's own correction goes to the Director.
        $this->at('2027-03-09 10:05');
        $this->clock($hr, 'in')->assertSessionHasNoErrors();
        $own = ClockSession::query()->where('user_id', $hr->id)->sole();
        $this->at('2027-03-09 12:00');
        $this->propose($hr, ['kind' => 'change_in', 'session_id' => $own->public_id, 'in_time' => '2027-03-09T09:00', 'reason_code' => 'wrong_time'])->assertSessionHasNoErrors();
        $mine = AttendanceCorrection::query()->latest('id')->firstOrFail();
        $this->decide($hr, $mine, 'approve')->assertForbidden();
        $this->decide($director, $mine, 'approve')->assertSessionHasNoErrors();
        $this->assertSame('2027-03-09 01:00:00', $own->refresh()->clock_in_at->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('open', $own->status);
    }

    public function test_a_correction_keeps_times_in_order_inside_the_window_and_can_be_withdrawn(): void
    {
        $hr = $this->actor('hr_manager');
        $ca = $this->actor('ca');
        $this->at('2027-03-08 08:00');
        $this->clock($ca, 'in')->assertSessionHasNoErrors();
        $session = ClockSession::query()->sole();
        $this->at('2027-03-08 09:00');
        $this->propose($ca, ['kind' => 'set_out', 'session_id' => $session->public_id, 'out_time' => '2027-03-08T08:30', 'reason_code' => 'forgot'])
            ->assertSessionHasErrors(['attendance' => 'Attendance is not set up yet. Ask HR.']);
        $this->settings($hr)->assertSessionHasNoErrors();

        $base = ['kind' => 'set_out', 'session_id' => $session->public_id, 'reason_code' => 'forgot'];
        $this->propose($ca, [...$base, 'out_time' => '2027-03-08T07:30'])->assertSessionHasErrors(['out_time' => 'The clock-out time must be after the clock-in time.']);
        $this->propose($ca, [...$base, 'out_time' => '2027-03-08T10:00'])->assertSessionHasErrors(['out_time' => 'Choose a time that has passed.']);
        $this->propose($ca, [...$base, 'out_time' => 'yesterday'])->assertSessionHasErrors(['out_time' => 'Enter the clock-out date and time.']);
        $this->propose($ca, [...$base, 'out_time' => '2027-03-08T08:45', 'reason_code' => 'because'])->assertSessionHasErrors(['reason_code' => 'Choose a reason.']);
        $this->propose($ca, ['kind' => 'add_day', 'branch_id' => $this->branch->id, 'in_time' => '2027-02-27T08:00', 'out_time' => '2027-02-27T12:00', 'reason_code' => 'forgot'])
            ->assertSessionHasErrors(['in_time' => 'Corrections can go back to 1 Mar 2027. Ask HR about older days.']);
        $this->propose($ca, ['kind' => 'add_day', 'branch_id' => $this->branch->id, 'in_time' => '2027-03-08T07:00', 'out_time' => '2027-03-08T08:30', 'reason_code' => 'forgot'])
            ->assertSessionHasErrors(['in_time' => 'Those times overlap another clock-in.']);
        $this->propose($ca, ['kind' => 'add_day', 'branch_id' => $this->branch->id, 'in_time' => '2027-03-07T08:00', 'out_time' => '2027-03-07T12:00', 'reason_code' => 'forgot'])
            ->assertSessionHasNoErrors();
        $this->propose($ca, [...$base, 'out_time' => '2027-03-08T08:45'])->assertSessionHasNoErrors();
        $this->assertSame(0, ClockSession::query()->where('status', 'closed')->count());

        $waiting = AttendanceCorrection::query()->where('kind', 'set_out')->sole();
        $this->selectBranch($ca);
        $this->post(route('hr.attendance.corrections.cancel', $waiting->public_id), ['expected_lock_version' => 1, 'idempotency_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
        $this->assertSame('cancelled', $waiting->refresh()->status);
        $this->decide($hr, $waiting, 'approve')->assertSessionHasErrors(['attendance' => 'This correction was already decided. Reload the page.']);

        // The forgotten day becomes a closed session of two correction events once someone else approves it.
        $day = AttendanceCorrection::query()->where('kind', 'add_day')->sole();
        $this->decide($hr, $day, 'approve')->assertSessionHasNoErrors();
        $made = ClockSession::query()->whereKey($day->refresh()->created_session_id)->sole();
        $this->assertSame(['closed', '2027-03-07'], [$made->status, $made->local_date->toDateString()]);
        $this->assertSame(['correction', 'correction'], DB::table('hr_clock_events')->where('clock_session_id', $made->id)->pluck('method')->all());
    }

    public function test_settings_are_versioned_from_today_and_validated(): void
    {
        $hr = $this->actor('hr_manager');
        $this->selectBranch($hr);
        $this->get(route('hr.attendance.index', ['tab' => 'settings']))->assertInertia(fn (Assert $page) => $page->where('attendance.settings.current', null)
            ->where('attendance.settings.suggested', ['graceMinutes' => 5, 'forgottenWindowMinutes' => 60, 'correctionDays' => 7]));
        $this->settings($hr, ['effective_from' => '2027-03-07'])->assertSessionHasErrors(['effective_from' => 'Choose today or a later date. Past days keep the settings they had.']);
        $this->settings($hr, ['grace_minutes' => 121])->assertSessionHasErrors(['grace_minutes' => 'Enter a whole number from 0 to 120.']);
        $this->settings($hr, ['forgotten_window_minutes' => 5])->assertSessionHasErrors(['forgotten_window_minutes']);
        $this->settings($hr, ['correction_days' => 0])->assertSessionHasErrors(['correction_days']);
        $key = (string) Str::uuid();
        $this->settings($hr, [], $key)->assertSessionHasNoErrors();
        $this->settings($hr, [], $key)->assertSessionHasNoErrors();
        $this->assertSame(1, DB::table('hr_attendance_settings')->count());
        $this->get(route('hr.attendance.index', ['tab' => 'settings']))->assertInertia(fn (Assert $page) => $page
            ->where('attendance.settings.current.graceMinutes', 5)->where('attendance.settings.current.effectiveFrom', '8 Mar 2027')
            ->where('attendance.settings.history.0.summary', 'Grace 5 min · forgotten clock-out after 60 min · corrections up to 7 days back'));
    }

    public function test_postgresql_keeps_the_rules_whatever_the_code_does(): void
    {
        $hr = $this->actor('hr_manager');
        $ca = $this->actor('ca');
        $this->settings($hr)->assertSessionHasNoErrors();
        $this->clock($ca, 'in')->assertSessionHasNoErrors();
        $session = ClockSession::query()->sole();
        $row = ['organisation_id' => $ca->organisation_id, 'user_id' => $ca->id, 'branch_id' => $this->branch->id, 'local_date' => self::WEEK,
            'timezone' => $this->branch->timezone, 'status' => 'open', 'created_at' => now(), 'updated_at' => now()];

        $this->assertRefused('23505', fn () => DB::table('hr_clock_sessions')->insert([...$row, 'public_id' => (string) Str::uuid(), 'clock_in_at' => '2027-03-08 03:00:00+00']));
        $this->assertRefused('23P01', fn () => DB::table('hr_clock_sessions')->insert([...$row, 'public_id' => (string) Str::uuid(), 'status' => 'closed',
            'clock_in_at' => '2027-03-07 22:00:00+00', 'clock_out_at' => '2027-03-08 01:00:00+00']));
        $outsider = User::factory()->create(['organisation_id' => $ca->organisation_id, 'email' => 'outsider@kpone.test']);
        $this->assertRefused('23514', fn () => DB::table('hr_clock_sessions')->insert([...$row, 'user_id' => $outsider->id, 'public_id' => (string) Str::uuid(), 'clock_in_at' => '2027-03-08 01:00:00+00']));
        $this->assertRefused('P0001', fn () => DB::table('hr_clock_sessions')->where('id', $session->id)->delete());
        $this->assertRefused('P0001', fn () => DB::table('hr_clock_sessions')->where('id', $session->id)->update(['user_id' => $hr->id]));
        $this->assertRefused('P0001', fn () => DB::table('hr_clock_events')->where('clock_session_id', $session->id)->update(['kind' => 'out']));
        $this->assertRefused('P0001', fn () => DB::table('hr_clock_events')->delete());
        $this->assertRefused('P0001', fn () => DB::table('hr_attendance_settings')->update(['grace_minutes' => 9]));

        DB::table('hr_clock_sessions')->where('id', $session->id)->update(['status' => 'closed', 'clock_out_at' => '2027-03-08 02:00:00+00']);
        $this->assertRefused('P0001', fn () => DB::table('hr_clock_sessions')->where('id', $session->id)->update(['status' => 'open', 'clock_out_at' => null]));

        $correction = ['public_id' => (string) Str::uuid(), 'organisation_id' => $ca->organisation_id, 'user_id' => $ca->id, 'branch_id' => $this->branch->id,
            'clock_session_id' => $session->id, 'kind' => 'set_out', 'proposed_out_at' => '2027-03-08 03:00:00+00', 'reason_code' => 'forgot',
            'status' => 'pending', 'proposed_by_user_id' => $ca->id, 'created_at' => now(), 'updated_at' => now()];
        $id = DB::table('hr_attendance_corrections')->insertGetId($correction);
        $this->assertRefused('23514', fn () => DB::table('hr_attendance_corrections')->where('id', $id)->update(['status' => 'approved', 'decided_by_user_id' => $ca->id, 'decided_at' => now()]));
        $this->assertRefused('P0001', fn () => DB::table('hr_attendance_corrections')->where('id', $id)->update(['proposed_out_at' => '2027-03-08 04:00:00+00']));
        DB::table('hr_attendance_corrections')->where('id', $id)->update(['status' => 'approved', 'decided_by_user_id' => $hr->id, 'decided_at' => now()]);
        $this->assertRefused('P0001', fn () => DB::table('hr_attendance_corrections')->where('id', $id)->update(['status' => 'rejected', 'decision_reason' => 'other']));
        $this->assertRefused('P0001', fn () => DB::table('hr_attendance_corrections')->where('id', $id)->delete());
    }

    public function test_other_organisations_ids_are_never_reached_and_nothing_else_is_written(): void
    {
        $hr = $this->actor('hr_manager');
        $ca = $this->actor('ca');
        $this->settings($hr)->assertSessionHasNoErrors();
        $before = $this->untouched();
        $this->clock($ca, 'in')->assertSessionHasNoErrors();
        $this->decide($hr, null, 'approve')->assertNotFound();
        $this->propose($ca, ['kind' => 'set_out', 'session_id' => (string) Str::uuid(), 'out_time' => '2027-03-08T06:00', 'reason_code' => 'forgot'])
            ->assertSessionHasErrors(['session_id' => 'Choose the day to correct.']);
        $this->propose($hr, ['user_id' => 999999, 'kind' => 'add_day', 'branch_id' => $this->branch->id, 'in_time' => '2027-03-07T08:00', 'out_time' => '2027-03-07T09:00', 'reason_code' => 'forgot'])
            ->assertSessionHasErrors(['user_id' => 'Choose a staff member.']);
        // Someone else's session is never correctable as one's own.
        $session = ClockSession::query()->sole();
        $this->propose($hr, ['kind' => 'set_out', 'session_id' => $session->public_id, 'out_time' => '2027-03-08T06:30', 'reason_code' => 'forgot'])
            ->assertSessionHasErrors(['session_id' => 'Choose the day to correct.']);
        $this->assertSame($before, $this->untouched());
    }

    public function test_review_lists_the_day_and_my_roster_offers_a_clock_in_link(): void
    {
        $hr = $this->actor('hr_manager');
        $director = $this->actor('director');
        $ca = $this->actor('ca');
        $this->settings($hr)->assertSessionHasNoErrors();
        $this->roster($hr, $director, [[$ca, '08:00', '14:00', 0]]);
        $this->at('2027-03-08 07:58');
        $this->clock($ca, 'in')->assertSessionHasNoErrors();
        $this->selectBranch($ca);
        $this->get(route('hr.attendance.index'))->assertInertia(fn (Assert $page) => $page
            ->where('attendance.my.todayShift', "Full day, 8:00 AM to 2:00 PM, {$this->branch->name}")
            ->where('attendance.my.week.0.session.in', '7:58 AM')->where('attendance.my.week.0.flags', []));
        $this->get(route('hr.roster.index', ['week' => self::WEEK]))->assertInertia(fn (Assert $page) => $page->where('roster.my.canClock', true));

        $this->selectBranch($hr);
        $this->get(route('hr.attendance.index', ['tab' => 'review', 'branch' => $this->branch->id, 'date' => self::WEEK]))->assertInertia(fn (Assert $page) => $page
            ->where('attendance.review.rows.0.person', $ca->name)->where('attendance.review.rows.0.shift.label', 'Full day, 8:00 AM to 2:00 PM')
            ->where('attendance.review.dateLabel', 'Monday, 8 Mar 2027')->where('attendance.review.summary', '0 flags · 0 corrections waiting'));
    }

    private function at(string $time): void
    {
        $this->travelTo(CarbonImmutable::parse($time, $this->branch->timezone));
    }

    private function clock(User $actor, string $kind, ?string $key = null): TestResponse
    {
        $this->selectBranch($actor);

        return $this->post(route($kind === 'in' ? 'hr.attendance.clock-in' : 'hr.attendance.clock-out'), ['idempotency_key' => $key ?? (string) Str::uuid()]);
    }

    /** @param array<string, mixed> $input */
    private function settings(User $actor, array $input = [], ?string $key = null): TestResponse
    {
        $this->selectBranch($actor);

        return $this->put(route('hr.attendance.settings'), ['grace_minutes' => 5, 'forgotten_window_minutes' => 60, 'correction_days' => 7,
            'idempotency_key' => $key ?? (string) Str::uuid(), ...$input]);
    }

    /** @param array<string, mixed> $input */
    private function propose(User $actor, array $input): TestResponse
    {
        $this->selectBranch($actor);

        return $this->post(route('hr.attendance.corrections.store'), ['idempotency_key' => (string) Str::uuid(), ...$input]);
    }

    /** @param array<string, mixed> $input */
    private function decide(User $actor, ?AttendanceCorrection $correction, string $decision, array $input = []): TestResponse
    {
        $this->selectBranch($actor);

        return $this->post(route('hr.attendance.corrections.decide', [$correction->public_id ?? (string) Str::uuid(), $decision]),
            ['expected_lock_version' => $correction?->refresh()->lock_version ?? 1, 'idempotency_key' => (string) Str::uuid(), ...$input]);
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
            'shifts' => DB::table('hr_roster_shifts')->count(),
            'leave' => DB::table('hr_leave_requests')->count(),
            'visits' => DB::table('visits')->count(),
            'queue' => DB::table('queue_entries')->count(),
        ]), true);
    }
}
