<?php

namespace Tests\Feature\HR;

use App\Domain\Access\ClinicManagementNavigation;
use App\Domain\Access\PermissionCatalogue;
use App\Domain\HR\Attendance\ClockPermissions;
use App\Domain\HR\Attendance\Models\AttendanceCorrection;
use App\Domain\HR\Attendance\Models\ClockSession;
use App\Domain\HR\Roster\Models\RosterVersion;
use App\Domain\HR\Roster\RosterPermissions;
use App\Domain\Identity\Models\StaffProfile;
use App\Domain\Organisation\Models\Branch;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Role;
use Tests\Feature\Visit\VisitTestCase;
use Tests\Support\ClockPhotoFixture;
use Tests\Support\StaffBranchAssignmentBootstrapper;

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
        return [['director', 200, ['my', 'review', 'overtime', 'summary', 'settings']], ['hr_manager', 200, ['my', 'review', 'overtime', 'summary', 'settings']], ['ca', 200, ['my']],
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

    public function test_review_opens_on_a_day_with_an_approved_shift_and_nobody_clocked_in_yet(): void
    {
        // Regression: AttendanceDays merged an empty sessions list with the day's shifts and returned a 500 (HR-9 found it).
        $hr = $this->actor('hr_manager');
        $director = $this->actor('director');
        $ca = $this->actor('ca');
        $this->settings($hr);
        $this->roster($hr, $director, [[$ca, '08:00', '17:00', 0]]);
        $this->selectBranch($hr);

        $this->get(route('hr.attendance.index', ['tab' => 'review', 'branch' => $this->branch->id, 'date' => self::WEEK]))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('attendance.tab', 'review')->has('attendance.review.rows', 1));
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

    public function test_a_split_shift_morning_never_clocked_out_does_not_block_the_evening_but_staying_late_does_not_count(): void
    {
        $hr = $this->actor('hr_manager');
        $director = $this->actor('director');
        $ca = $this->actor('ca');
        $late = $this->actor('ca');
        $this->settings($hr)->assertSessionHasNoErrors();
        $this->roster($hr, $director, [[$ca, '08:00', '12:00', 0], [$ca, '17:00', '21:00', 0], [$late, '08:00', '14:00', 0]]);
        $this->at('2027-03-08 08:00');
        $this->clock($ca, 'in')->assertSessionHasNoErrors();
        $this->clock($late, 'in')->assertSessionHasNoErrors();

        // Past the morning shift's end plus the window, with the evening shift about to start: the morning is forgotten.
        $this->at('2027-03-08 16:30');
        $this->selectBranch($ca);
        $this->get(route('hr.attendance.index'))->assertInertia(fn (Assert $page) => $page->where('attendance.my.state', 'out')
            ->where('attendance.my.staleLabel', 'Your clock-in on 8 Mar 2027 has no clock-out. Add your clock-out time below.'));
        $this->clock($ca, 'in')->assertSessionHasNoErrors();
        $this->assertSame(['missing_out', 'open'], ClockSession::query()->where('user_id', $ca->id)->orderBy('id')->pluck('status')->all());

        // The morning clock-out is then added as a correction someone else approves.
        $morning = ClockSession::query()->where('user_id', $ca->id)->where('status', 'missing_out')->sole();
        $this->propose($ca, ['kind' => 'set_out', 'session_id' => $morning->public_id, 'out_time' => '2027-03-08T12:05', 'reason_code' => 'forgot'])->assertSessionHasNoErrors();
        $this->decide($hr, AttendanceCorrection::query()->sole(), 'approve')->assertSessionHasNoErrors();
        $this->assertSame('closed', $morning->refresh()->status);

        // With no later shift, a person still on after their shift simply clocks out (it may be overtime, HR-3b).
        $this->selectBranch($late);
        $this->get(route('hr.attendance.index'))->assertInertia(fn (Assert $page) => $page->where('attendance.my.state', 'in'));
        $this->clock($late, 'out')->assertSessionHasNoErrors();
        $this->assertSame('closed', ClockSession::query()->where('user_id', $late->id)->value('status'));
    }

    public function test_leave_other_branches_breaks_and_open_or_forgotten_sessions_are_read_correctly(): void
    {
        $hr = $this->actor('hr_manager');
        $director = $this->actor('director');
        $ca = $this->actor('ca');
        $away = $this->actor('ca');
        $this->settings($hr, ['effective_from' => self::WEEK])->assertSessionHasNoErrors();
        $this->roster($hr, $director, [[$ca, '08:00', '14:00', 30], [$away, '08:00', '14:00', 0]]);
        $this->approvedLeave($away, $hr, $director);
        $puchong = Branch::query()->where('organisation_id', $this->organisation->id)->where('code', 'PUCHONG')->firstOrFail();
        StaffBranchAssignmentBootstrapper::create(StaffProfile::query()->where('user_id', $ca->id)->firstOrFail(), $puchong,
            ['is_primary' => false, 'assignment_type' => 'temporary', 'valid_from' => '2027-03-07', 'valid_until' => null]);

        $this->at('2027-03-08 07:55');
        $this->selectBranch($ca, $puchong);
        $this->post(route('hr.attendance.clock-in'), ['idempotency_key' => (string) Str::uuid(), ...ClockPhotoFixture::press($this, 'in')])->assertSessionHasNoErrors();
        $this->at('2027-03-08 14:10');
        $this->clock($ca, 'out')->assertSessionHasNoErrors();
        $this->at('2027-03-08 16:00');
        $this->selectBranch($hr);
        $rows = collect($this->get(route('hr.attendance.index', ['tab' => 'review', 'date' => self::WEEK]))->viewData('page')['props']['attendance']['review']['rows'])->keyBy('userId');
        $this->assertSame(['Different branch from the roster'], collect($rows[$ca->id]['flags'])->pluck('label')->all());
        $this->assertSame('5 h 45 min', $rows[$ca->id]['worked']); // The session covers the shift, so its 30-minute break comes off.
        $this->assertSame(['On approved leave'], collect($rows[$away->id]['flags'])->pluck('label')->all()); // Never "No clock-in".

        // An open session runs until now: a forgotten day inside it is refused, and so is one after a clock-in missing its clock-out.
        $this->at('2027-03-09 08:00');
        $this->clock($away, 'in')->assertSessionHasNoErrors();
        $this->at('2027-03-09 11:00');
        $this->propose($hr, ['user_id' => $away->id, 'kind' => 'add_day', 'branch_id' => $this->branch->id, 'in_time' => '2027-03-09T09:00', 'out_time' => '2027-03-09T10:00',
            'reason_code' => 'other', 'tab' => 'review'])->assertSessionHasErrors(['in_time' => 'Those times overlap another clock-in.']);
        DB::table('hr_clock_sessions')->where('user_id', $away->id)->update(['status' => 'missing_out']);
        $this->propose($away, ['kind' => 'add_day', 'branch_id' => $this->branch->id, 'in_time' => '2027-03-09T08:05', 'out_time' => '2027-03-09T10:30', 'reason_code' => 'forgot'])
            ->assertSessionHasErrors(['in_time' => 'There is a clock-in on 9 Mar 2027 with no clock-out. Add its clock-out time instead.']);
    }

    public function test_attendance_older_than_seven_years_is_purged_with_what_hangs_on_it_and_only_by_the_purge(): void
    {
        // Retention (owner, 2026-11-11; the accountant confirms the 7 years before real use). PostgreSQL checks the 7 years
        // against its own clock, so this test works in real time.
        $real = CarbonImmutable::createFromTimestamp(time(), 'UTC');
        $this->travelTo($real->subYears(7)->subDays(30));
        $ca = $this->actor('ca');
        $director = $this->actor('director');
        $this->clock($ca, 'in')->assertSessionHasNoErrors();
        $this->travelTo($real->subYears(7)->subDays(30)->addHours(6));
        $this->clock($ca, 'out')->assertSessionHasNoErrors();
        $this->travelTo($real->subYears(7)->subDays(20));
        $this->clock($ca, 'in')->assertSessionHasNoErrors();
        $this->travelTo($real->subYears(7)->subDays(20)->addHours(6));
        $this->clock($ca, 'out')->assertSessionHasNoErrors();
        [$old, $held] = ClockSession::query()->orderBy('id')->get()->all();
        // A recent event on the second session keeps it until that event is 7 years old too.
        DB::table('hr_clock_events')->insert(['organisation_id' => $this->organisation->id, 'clock_session_id' => $held->id, 'kind' => 'out',
            'occurred_at' => $held->clock_out_at, 'method' => 'button', 'recorded_by_user_id' => $ca->id, 'created_at' => $real->subYears(6)]);
        // An old consent goes on its own time.
        $setting = DB::table('hr_location_check_settings')->insertGetId(['organisation_id' => $this->organisation->id, 'branch_id' => $this->branch->id,
            'enabled' => true, 'geofence_centre_lat' => 3.1, 'geofence_centre_lng' => 101.7, 'radius_metres' => 150, 'consent_en' => 'x', 'consent_bm' => 'x',
            'created_by_user_id' => $director->id, 'created_at' => $real->subYears(8)]);
        DB::table('hr_location_consents')->insert(['organisation_id' => $this->organisation->id, 'user_id' => $ca->id, 'setting_id' => $setting,
            'acknowledged_at' => $real->subYears(7)->subDays(30)]);
        $this->travelBack();
        $this->clock($ca, 'in')->assertSessionHasNoErrors(); // today's session
        $recent = ClockSession::query()->where('status', 'open')->sole();

        $this->artisan('hr-attendance:purge')->expectsOutputToContain('Deleted 1 clock sessions, 0 corrections and 1 location consents.')->assertSuccessful();
        $this->assertSame([$held->id, $recent->id], ClockSession::query()->orderBy('id')->pluck('id')->all());
        $this->assertSame(0, DB::table('hr_clock_events')->where('clock_session_id', $old->id)->count());
        $this->assertSame(0, DB::table('hr_location_consents')->count());
        $this->assertSame(1, DB::table('hr_location_check_settings')->count()); // settings versions are kept
        $this->assertSame([['sessions' => 1, 'corrections' => 0, 'consents' => 1, 'retention_years' => 7]], DB::table('audit_logs')
            ->where('event', 'hr.attendance.retention_purged')->pluck('metadata')->map(fn ($m): mixed => json_decode((string) $m, true))->all());
        $this->artisan('hr-attendance:purge')->expectsOutputToContain('Deleted 0 clock sessions, 0 corrections and 0 location consents.')->assertSuccessful();
        $this->assertSame(['0 0 * * *'], collect(app(Schedule::class)->events())
            ->filter(fn ($e): bool => str_contains((string) $e->command, 'hr-attendance:purge'))->pluck('expression')->values()->all());

        // Only the purge deletes, and only rows past 7 years by their own time; TRUNCATE stays refused.
        $this->assertRefused('P0001', fn () => DB::table('hr_clock_sessions')->where('id', $held->id)->delete());
        $this->assertRefused('P0001', function () use ($held): void {
            DB::statement("SELECT set_config('kpone.hr_attendance_purge', 'on', true)");
            DB::table('hr_clock_events')->where('clock_session_id', $held->id)->where('created_at', '>', now()->subYears(7))->delete(); // 6 years old
        });
        $this->assertRefused('P0001', function () use ($recent): void {
            DB::statement("SELECT set_config('kpone.hr_attendance_purge', 'on', true)");
            DB::table('hr_clock_events')->where('clock_session_id', $recent->id)->delete();
        });
        $this->assertRefused('P0001', fn () => DB::statement('TRUNCATE hr_clock_sessions CASCADE'));
        $this->assertSame(2, ClockSession::query()->count());
    }

    public function test_a_waiting_correction_says_when_it_was_sent(): void
    {
        // For the HR space's "oldest waiting" line (Daniel, Kavitha): when the correction was sent, ISO and in UTC.
        $hr = $this->actor('hr_manager');
        $ca = $this->actor('ca');
        $this->settings($hr)->assertSessionHasNoErrors();
        $this->at('2027-03-08 08:00');
        $this->clock($ca, 'in')->assertSessionHasNoErrors();
        $session = ClockSession::query()->sole();
        $this->at('2027-03-08 18:00'); // 10:00 UTC
        $this->propose($ca, ['kind' => 'set_out', 'session_id' => $session->public_id, 'out_time' => '2027-03-08T17:00', 'reason_code' => 'forgot'])->assertSessionHasNoErrors();
        $this->selectBranch($hr);
        $this->get(route('hr.attendance.index', ['tab' => 'review']))->assertInertia(fn (Assert $page) => $page
            ->where('attendance.review.pending.0.sentAt', '2027-03-08T10:00:00+00:00'));
    }

    public function test_review_pairs_each_half_of_a_split_shift_across_two_branches_with_its_own_shift(): void
    {
        // HR-3a known limit: Review loaded shifts for one branch, so the other branch's half of a split shift showed as
        // "Not on the roster". Each half is now matched with its own shift, and each branch's Review shows its own half.
        $hr = $this->actor('hr_manager');
        $director = $this->actor('director');
        $ca = $this->actor('ca');
        $puchong = Branch::query()->where('organisation_id', $this->organisation->id)->where('code', 'PUCHONG')->firstOrFail();
        StaffBranchAssignmentBootstrapper::create(StaffProfile::query()->where('user_id', $ca->id)->firstOrFail(), $puchong,
            ['is_primary' => false, 'assignment_type' => 'temporary', 'valid_from' => '2027-03-07', 'valid_until' => null]);
        $this->settings($hr, ['effective_from' => self::WEEK])->assertSessionHasNoErrors();
        $this->roster($hr, $director, [[$ca, '08:00', '12:00', 0]]);
        $this->roster($hr, $director, [[$ca, '17:00', '21:00', 0]], $puchong);

        $this->at('2027-03-08 07:58');
        $this->clock($ca, 'in')->assertSessionHasNoErrors();
        $this->at('2027-03-08 12:01');
        $this->clock($ca, 'out')->assertSessionHasNoErrors();
        $this->at('2027-03-08 16:58');
        $this->selectBranch($ca, $puchong);
        $this->post(route('hr.attendance.clock-in'), ['idempotency_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
        $this->at('2027-03-08 21:02');
        $this->selectBranch($ca, $puchong);
        $this->post(route('hr.attendance.clock-out'), ['idempotency_key' => (string) Str::uuid()])->assertSessionHasNoErrors();

        $this->at('2027-03-08 22:00');
        $this->selectBranch($hr);
        $review = fn (Branch $branch): array => $this->get(route('hr.attendance.index', ['tab' => 'review', 'branch' => $branch->id, 'date' => self::WEEK]))
            ->viewData('page')['props']['attendance']['review']['rows'];
        foreach ([[$this->branch, '8:00 AM to 12:00 PM', '7:58 AM'], [$puchong, '5:00 PM to 9:00 PM', '4:58 PM']] as [$branch, $shift, $in]) {
            $rows = $review($branch);
            $this->assertCount(1, $rows, $branch->name);
            $this->assertSame(["Full day, {$shift}", $branch->id, $in, []], [$rows[0]['shift']['label'], $rows[0]['session']['branchId'],
                $rows[0]['session']['in'], $rows[0]['flags']], $branch->name);
        }
    }

    public function test_review_corrections_name_a_person_and_only_the_proposer_withdraws_with_the_current_version(): void
    {
        $hr = $this->actor('hr_manager');
        $director = $this->actor('director');
        $ca = $this->actor('ca');
        $this->settings($hr)->assertSessionHasNoErrors();
        $this->at('2027-03-08 08:00');
        $this->clock($ca, 'in')->assertSessionHasNoErrors();
        $session = ClockSession::query()->sole();
        $this->at('2027-03-08 18:00');

        // From Review, a forgotten day with no staff member chosen is refused, never filed for the reviewer.
        $this->selectBranch($hr);
        $this->post(route('hr.attendance.corrections.store'), ['tab' => 'review', 'kind' => 'add_day', 'branch_id' => $this->branch->id, 'in_time' => '2027-03-08T08:00',
            'out_time' => '2027-03-08T09:00', 'reason_code' => 'other', 'idempotency_key' => (string) Str::uuid()])->assertSessionHasErrors(['user_id' => 'Choose a staff member.']);
        $this->assertSame(0, AttendanceCorrection::query()->count());

        $this->propose($hr, ['user_id' => $ca->id, 'kind' => 'set_out', 'session_id' => $session->public_id, 'out_time' => '2027-03-08T17:00', 'reason_code' => 'forgot', 'tab' => 'review'])
            ->assertSessionHasNoErrors();
        $correction = AttendanceCorrection::query()->sole();
        $this->selectBranch($ca);
        $this->post(route('hr.attendance.corrections.cancel', $correction->public_id), ['expected_lock_version' => 1, 'idempotency_key' => (string) Str::uuid()])->assertForbidden();
        $this->selectBranch($hr);
        $this->post(route('hr.attendance.corrections.cancel', $correction->public_id), ['expected_lock_version' => 9, 'idempotency_key' => (string) Str::uuid()])
            ->assertSessionHasErrors(['attendance' => 'This was changed by someone else. Reload to see the latest version.']);
        $this->selectBranch($director);
        $this->post(route('hr.attendance.corrections.decide', [$correction->public_id, 'approve']), ['expected_lock_version' => 9, 'idempotency_key' => (string) Str::uuid()])
            ->assertSessionHasErrors(['attendance' => 'This was changed by someone else. Reload to see the latest version.']);
        $key = (string) Str::uuid();
        foreach ([1, 2] as $attempt) {
            $this->post(route('hr.attendance.corrections.decide', [$correction->public_id, 'approve']), ['expected_lock_version' => 1, 'idempotency_key' => $key])->assertSessionHasNoErrors();
        }
        $this->assertSame(1, DB::table('hr_clock_events')->where('correction_id', $correction->id)->count());
        $this->get(route('hr.attendance.index', ['tab' => 'my']))->assertInertia(fn (Assert $page) => $page->where('attendance.pendingCount', 0));
    }

    public function test_another_organisations_sessions_and_corrections_are_never_reached(): void
    {
        $hr = $this->actor('hr_manager');
        $this->settings($hr)->assertSessionHasNoErrors();
        $org = DB::table('organisations')->insertGetId(['code' => 'OTHER_'.Str::upper(Str::random(6)), 'name' => 'Synthetic Other Organisation', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $branch = new Branch;
        $branch->forceFill(['organisation_id' => $org, 'code' => 'OTH'.Str::upper(Str::random(4)), 'name' => 'Synthetic Other Branch', 'timezone' => 'Asia/Kuala_Lumpur', 'is_active' => true])->save();
        $stranger = User::factory()->create(['organisation_id' => $org, 'email' => 'attendance.other.'.Str::lower(Str::random(8)).'@kpone.test']);
        $profile = new StaffProfile;
        $profile->forceFill(['user_id' => $stranger->id, 'department_id' => null])->save();
        StaffBranchAssignmentBootstrapper::create($profile, $branch, ['is_primary' => true, 'assignment_type' => 'permanent', 'valid_from' => '2027-03-01', 'valid_until' => null]);
        $sessionId = (string) Str::uuid();
        $sid = DB::table('hr_clock_sessions')->insertGetId(['public_id' => $sessionId, 'organisation_id' => $org, 'user_id' => $stranger->id, 'branch_id' => $branch->id,
            'local_date' => self::WEEK, 'timezone' => 'Asia/Kuala_Lumpur', 'clock_in_at' => '2027-03-07 23:30:00+00', 'status' => 'open', 'created_at' => now(), 'updated_at' => now()]);
        $correctionId = (string) Str::uuid();
        DB::table('hr_attendance_corrections')->insert(['public_id' => $correctionId, 'organisation_id' => $org, 'user_id' => $stranger->id, 'branch_id' => $branch->id,
            'clock_session_id' => $sid, 'kind' => 'set_out', 'proposed_out_at' => '2027-03-08 00:30:00+00', 'reason_code' => 'forgot', 'status' => 'pending',
            'proposed_by_user_id' => $stranger->id, 'created_at' => now(), 'updated_at' => now()]);

        $this->selectBranch($hr);
        $this->post(route('hr.attendance.corrections.decide', [$correctionId, 'approve']), ['expected_lock_version' => 1, 'idempotency_key' => (string) Str::uuid()])->assertNotFound();
        $this->post(route('hr.attendance.corrections.cancel', $correctionId), ['expected_lock_version' => 1, 'idempotency_key' => (string) Str::uuid()])->assertNotFound();
        $this->propose($hr, ['user_id' => $stranger->id, 'kind' => 'set_out', 'session_id' => $sessionId, 'out_time' => '2027-03-08T06:00', 'reason_code' => 'forgot', 'tab' => 'review'])
            ->assertSessionHasErrors(['user_id' => 'Choose a staff member.']);
        $this->get(route('hr.attendance.index', ['tab' => 'review']))->assertInertia(fn (Assert $page) => $page->where('attendance.review.pending', []));
        $this->assertSame('pending', DB::table('hr_attendance_corrections')->where('public_id', $correctionId)->value('status'));
    }

    public function test_a_withdrawal_records_who_withdrew_it_and_when_and_postgresql_holds_that_shape(): void
    {
        // TEAM.md section 7 hardening (HR-3a known limit): the row now says who withdrew a correction, not only the audit.
        $hr = $this->actor('hr_manager');
        $ca = $this->actor('ca');
        $this->settings($hr)->assertSessionHasNoErrors();
        $this->at('2027-03-08 08:00');
        $this->clock($ca, 'in')->assertSessionHasNoErrors();
        $session = ClockSession::query()->sole();
        $this->at('2027-03-08 09:00');
        $this->propose($ca, ['kind' => 'set_out', 'session_id' => $session->public_id, 'out_time' => '2027-03-08T08:45', 'reason_code' => 'forgot'])->assertSessionHasNoErrors();
        $waiting = AttendanceCorrection::query()->sole();
        $this->assertNull($waiting->withdrawn_by_user_id);
        $this->assertNull($waiting->withdrawn_at);

        // PostgreSQL refuses a withdrawal that does not name the proposer and the time, and a withdrawal on any other step.
        $this->assertRefused('23514', fn () => DB::table('hr_attendance_corrections')->where('id', $waiting->id)->update(['status' => 'cancelled', 'lock_version' => 2]));
        $this->assertRefused('23514', fn () => DB::table('hr_attendance_corrections')->where('id', $waiting->id)
            ->update(['status' => 'cancelled', 'withdrawn_by_user_id' => $hr->id, 'withdrawn_at' => now(), 'lock_version' => 2]));
        $this->assertRefused('23514', fn () => DB::table('hr_attendance_corrections')->where('id', $waiting->id)
            ->update(['withdrawn_by_user_id' => $ca->id, 'withdrawn_at' => now()]));

        $this->selectBranch($ca);
        $this->post(route('hr.attendance.corrections.cancel', $waiting->public_id), ['expected_lock_version' => 1, 'idempotency_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
        $waiting->refresh();
        $this->assertSame('cancelled', $waiting->status);
        $this->assertSame($ca->id, $waiting->withdrawn_by_user_id);
        $this->assertSame('2027-03-08 01:00:00', $waiting->withdrawn_at?->utc()->format('Y-m-d H:i:s')); // 09:00 Kuala Lumpur
        $this->assertNull($waiting->decided_by_user_id);

        // A correction never starts withdrawn.
        $this->assertRefused('23514', fn () => DB::table('hr_attendance_corrections')->insert([...collect((array) DB::table('hr_attendance_corrections')->where('id', $waiting->id)->first())
            ->except(['id'])->all(), 'public_id' => (string) Str::uuid(), 'status' => 'pending', 'lock_version' => 1]));
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

    private function at(string $time): void
    {
        $this->travelTo(CarbonImmutable::parse($time, $this->branch->timezone));
    }

    private function clock(User $actor, string $kind, ?string $key = null): TestResponse
    {
        $this->selectBranch($actor);

        return $this->post(route($kind === 'in' ? 'hr.attendance.clock-in' : 'hr.attendance.clock-out'), ['idempotency_key' => $key ?? (string) Str::uuid(), ...ClockPhotoFixture::press($this, $kind)]);
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
    private function roster(User $hr, User $director, array $shifts, ?Branch $branch = null): void
    {
        $branch ??= $this->branch;
        $this->selectBranch($hr);
        $this->post(route('hr.roster.drafts.store'), ['branch_id' => $branch->id, 'week' => self::WEEK, 'mode' => 'blank', 'idempotency_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
        $version = RosterVersion::query()->latest('id')->firstOrFail();
        foreach ($shifts as [$person, $start, $end, $break]) {
            $this->post(route('hr.roster.shifts.store', $version->public_id), ['staff_user_id' => $person->id, 'date' => self::WEEK, 'start_time' => $start, 'end_time' => $end,
                'break_minutes' => $break, 'session_type' => 'full_day', 'branch_room_id' => null, 'branch_id' => $branch->id, 'week' => self::WEEK,
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
