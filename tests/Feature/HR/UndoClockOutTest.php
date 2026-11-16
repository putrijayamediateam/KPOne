<?php

namespace Tests\Feature\HR;

use App\Domain\HR\Attendance\Models\ClockPhoto;
use App\Domain\HR\Attendance\Models\ClockSession;
use App\Domain\HR\Attendance\Photos\ClockPhotoService;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Visit\VisitTestCase;
use Tests\Support\ClockPhotoFixture;

/**
 * Undo clock-out (owner, 16 Nov 2026: a 15-minute window, staff only; HR uses the correction). The same session reopens;
 * nothing is deleted, the undone press keeps its event and photo, and the next clock-out is a new attempt.
 */
class UndoClockOutTest extends VisitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(ClockPhotoService::DISK);
        $this->travelTo(CarbonImmutable::parse('2027-03-08 08:55', $this->branch->timezone));
    }

    public function test_an_undo_within_15_minutes_reopens_the_same_session_and_keeps_the_first_photo(): void
    {
        $ca = $this->clockedOut();
        $session = ClockSession::query()->sole();
        $firstOut = $session->clock_out_at;
        $this->get(route('hr.attendance.index'))->assertInertia(fn (Assert $page) => $page
            ->where('attendance.my.undo.sessionId', $session->public_id)->where('attendance.my.undo.pressedAt', '5:02 PM')
            ->where('attendance.my.undo.until', '5:17 PM'));

        $this->travel(14)->minutes();
        $this->undo($session)->assertSessionHasNoErrors()->assertSessionHas('inertia.flash_data.toast.message', 'Clock-out undone. You are clocked in again.');
        $session->refresh();
        $this->assertSame('open', $session->status);
        $this->assertNull($session->clock_out_at);
        $this->assertSame(1, ClockSession::query()->count());
        $this->assertSame(['in', 'out', 'out_undone'], DB::table('hr_clock_events')->orderBy('id')->pluck('kind')->all());
        $this->assertTrue(CarbonImmutable::parse((string) DB::table('hr_clock_events')->where('kind', 'out_undone')->value('occurred_at'))->equalTo($firstOut));
        $this->assertSame(['in' => 1, 'out' => 1], ClockPhoto::query()->pluck('attempt', 'kind')->map(fn ($v) => (int) $v)->all());
        $this->assertSame(1, DB::table('audit_logs')->where('event', 'hr.attendance.clock_out_undone')->count());
        // The week line counts the reopened session up to now (08:55 to 17:16 is 8 h 21 min), never the earlier close.
        $this->get(route('hr.attendance.index'))->assertInertia(fn (Assert $page) => $page->where('attendance.my.state', 'in')->where('attendance.my.undo', null)
            ->where('attendance.my.overtime.weekHours.minutes', 501)->where('attendance.my.overtime.weekHours.label', '8 h 21 min so far'));

        // The next clock-out is a new attempt with its own photo; the earlier one stays on record.
        $this->travel(30)->minutes();
        $this->press('out')->assertSessionHasNoErrors();
        $this->assertSame('closed', $session->refresh()->status);
        $this->assertSame([1, 2], ClockPhoto::query()->where('kind', 'out')->orderBy('attempt')->pluck('attempt')->map(fn ($v) => (int) $v)->all());

        // HR sees on Review that a clock-out was taken back.
        $hr = $this->actor('hr_manager');
        $this->selectBranch($hr);
        $this->get(route('hr.attendance.index', ['tab' => 'review', 'branch' => $this->branch->id, 'date' => '2027-03-08']))
            ->assertInertia(fn (Assert $page) => $page->where('attendance.review.rows', fn ($rows) => collect($rows)->firstWhere('userId', $ca->id)['undoneLabel'] === 'Clock-out undone at 5:02 PM'));
    }

    public function test_an_undo_is_refused_after_15_minutes_for_another_session_or_after_a_correction(): void
    {
        $this->clockedOut();
        $session = ClockSession::query()->sole();
        $this->travel(16)->minutes();
        $this->get(route('hr.attendance.index'))->assertInertia(fn (Assert $page) => $page->where('attendance.my.undo', null));
        $this->undo($session)->assertSessionHasErrors(['clock' => 'The time to undo has passed. Ask for a correction instead.']);
        $this->assertSame('closed', $session->refresh()->status);

        // Someone else's session is never theirs to undo.
        $other = $this->actor('ca');
        $this->selectBranch($other);
        $this->undo($session)->assertSessionHasErrors(['clock' => 'Only your latest clock-out can be undone. Reload the page.']);
    }

    public function test_a_correction_or_a_new_clock_in_stops_the_undo(): void
    {
        $ca = $this->clockedOut();
        $session = ClockSession::query()->sole();
        DB::table('hr_attendance_corrections')->insert(['public_id' => (string) Str::uuid(), 'organisation_id' => $ca->organisation_id, 'user_id' => $ca->id,
            'branch_id' => $session->branch_id, 'clock_session_id' => $session->id, 'kind' => 'set_out', 'proposed_out_at' => $session->clock_out_at->subMinutes(10), 'reason_code' => 'forgot',
            'proposed_by_user_id' => $ca->id, 'status' => 'pending', 'created_at' => now(), 'updated_at' => now()]);
        $this->get(route('hr.attendance.index'))->assertInertia(fn (Assert $page) => $page->where('attendance.my.undo', null));
        $this->undo($session)->assertSessionHasErrors(['clock' => 'A correction or overtime request already uses this day. Ask HR instead.']);
    }

    public function test_a_payroll_run_already_sent_for_the_month_stops_the_offer_and_the_undo(): void
    {
        $ca = $this->clockedOut();
        $session = ClockSession::query()->sole();
        $org = $ca->organisation_id;
        $worker = DB::table('hr_workers')->insertGetId(['public_id' => (string) Str::uuid(), 'organisation_id' => $org, 'user_id' => $ca->id, 'kind' => 'staff',
            'created_by_user_id' => $ca->id, 'created_at' => now(), 'updated_at' => now()]);
        $group = DB::table('hr_pay_groups')->insertGetId(['public_id' => (string) Str::uuid(), 'organisation_id' => $org, 'name' => 'Synthetic pay', 'is_active' => true,
            'created_by_user_id' => $ca->id, 'created_at' => now(), 'updated_at' => now()]);
        $run = DB::table('hr_payroll_runs')->insertGetId(['public_id' => (string) Str::uuid(), 'organisation_id' => $org, 'pay_group_id' => $group, 'month' => '2027-03-01',
            'kind' => 'regular', 'status' => 'draft', 'group_name' => 'Synthetic pay', 'prepared_by_user_id' => $ca->id, 'prepared_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('hr_payroll_run_people')->insert(['organisation_id' => $org, 'run_id' => $run, 'worker_id' => $worker, 'errors' => '[]', 'warnings' => '[]']);
        // A draft that includes the person still allows the undo; once it is sent, it does not.
        $this->get(route('hr.attendance.index'))->assertInertia(fn (Assert $page) => $page->where('attendance.my.undo.sessionId', $session->public_id));
        DB::table('hr_payroll_runs')->where('id', $run)->update(['status' => 'sent', 'sent_by_user_id' => $ca->id, 'sent_at' => now(), 'versions' => '{}']);
        $this->get(route('hr.attendance.index'))->assertInertia(fn (Assert $page) => $page->where('attendance.my.undo', null));
        $this->undo($session)->assertSessionHasErrors(['clock' => 'Payroll for this month is already with the approver. Ask HR for a correction.']);
    }

    public function test_after_a_new_clock_in_the_earlier_clock_out_is_not_undone(): void
    {
        $this->clockedOut();
        $session = ClockSession::query()->sole();
        $this->travel(2)->minutes();
        $this->press('in')->assertSessionHasNoErrors();
        $this->undo($session)->assertSessionHasErrors(['clock' => 'Only your latest clock-out can be undone. Reload the page.']);
    }

    public function test_postgresql_refuses_any_other_reopen(): void
    {
        $this->clockedOut();
        $session = ClockSession::query()->sole();
        // Without the undo's event and flag, a closed session never reopens, even inside the window.
        $this->assertRefused('P0001', fn () => DB::table('hr_clock_sessions')->where('id', $session->id)->update(['status' => 'open', 'clock_out_at' => null]));
        // The flag alone is not enough: the undo's event must exist for that clock-out.
        $this->assertRefused('P0001', function () use ($session): void {
            DB::statement("SELECT set_config('kpone.hr_undo_clock_out', ?, true)", [(string) $session->id]);
            DB::table('hr_clock_sessions')->where('id', $session->id)->update(['status' => 'open', 'clock_out_at' => null]);
        });
        // A second out photo of the same attempt is refused.
        $photo = ClockPhoto::query()->where('kind', 'out')->sole();
        $this->assertRefused('23505', fn () => DB::table('hr_clock_photos')->insert([...collect($photo->getAttributes())->except(['id', 'public_id', 'sha256', 'capture_token_id'])->all(),
            'public_id' => (string) Str::uuid(), 'sha256' => str_repeat('a', 64), 'capture_token_id' => $photo->capture_token_id]));
        $this->assertSame('closed', $session->refresh()->status);
    }

    private function clockedOut(): User
    {
        $ca = $this->actor('ca');
        $this->selectBranch($ca);
        $this->press('in')->assertSessionHasNoErrors();
        $this->travelTo(CarbonImmutable::parse('2027-03-08 17:02', $this->branch->timezone));
        $this->press('out')->assertSessionHasNoErrors();

        return $ca;
    }

    private function press(string $kind): TestResponse
    {
        return $this->post(route($kind === 'in' ? 'hr.attendance.clock-in' : 'hr.attendance.clock-out'),
            ['idempotency_key' => (string) Str::uuid(), ...ClockPhotoFixture::press($this, $kind)]);
    }

    private function undo(ClockSession $session): TestResponse
    {
        return $this->post(route('hr.attendance.clock-out.undo'), ['session_id' => $session->public_id, 'expected_lock_version' => $session->lock_version,
            'idempotency_key' => (string) Str::uuid()]);
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
}
