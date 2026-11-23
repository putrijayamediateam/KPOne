<?php

namespace Tests\Feature\HR;

use App\Domain\Access\PermissionCatalogue;
use App\Domain\HR\Records\Services\LocumSignIns;
use App\Domain\HR\Records\Services\StaffFileService;
use App\Domain\HR\Roster\Models\RosterVersion;
use App\Domain\HR\Roster\Services\RosterService;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Visit\VisitTestCase;

/**
 * HR-15f: the month calendar on My roster (owner, via B.E.R.N.I.S.S, 18 Nov 2026). A person sees only their own approved
 * shifts and their own open-shift asks that are waiting or confirmed into a week not yet approved. Synthetic data only.
 */
class RosterMonthTest extends VisitTestCase
{
    private User $hr;

    private User $director;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-11-10 09:00', $this->branch->timezone));
        $this->hr = $this->actor('hr_manager');
        $this->director = $this->actor('director');
    }

    public function test_the_month_shows_my_asks_then_my_approved_shift_and_nothing_of_anyone_else(): void
    {
        $locum = $this->linkedLocum('one');
        $other = $this->linkedLocum('two');
        $shift = $this->post_($this->hr);
        foreach ([$locum, $other] as $who) {
            $this->selectBranch($who);
            $this->post(route('hr.open-shifts.ask', $shift), ['idempotency_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
        }

        // Waiting: the ask shows on the 17th, with no shift and no hours.
        $this->selectBranch($locum);
        $this->get(route('hr.roster.index', ['myView' => 'month', 'month' => '2026-11']))->assertOk()->assertInertia(fn (Assert $p) => $p
            ->where('roster.view', 'month')->where('roster.month.key', '2026-11')->where('roster.month.label', 'November 2026')
            ->where('roster.month.previous', '2026-10')->where('roster.month.next', '2026-12')->where('roster.month.hoursLabel', '0 h')
            // 1 Nov 2026 is a Sunday: the grid runs Monday 26 Oct to Sunday 6 Dec, six weeks.
            ->has('roster.month.days', 42)->where('roster.month.days.0.date', '2026-10-26')->where('roster.month.days.0.inMonth', false)
            ->where('roster.month.days.22.date', '2026-11-17')->where('roster.month.days.22.asks.0.status', 'asked')
            ->where('roster.month.days.22.asks.0.label', 'Asked, waiting for HR')->where('roster.month.days.22.asks.0.startLabel', '8:00 AM')
            ->where('roster.month.days.22.shifts', [])->where('roster.month.days.15.today', true));

        // HR confirms mine: it shows as confirmed until the week is approved. The other locum's ask is declined and never shows to me.
        $this->selectBranch($this->hr);
        $ask = DB::table('hr_open_shift_requests')->where('locum_user_id', $locum->id)->sole();
        $this->post(route('hr.open-shifts.confirm', $ask->public_id), ['expected_lock_version' => 1, 'idempotency_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
        $this->selectBranch($locum);
        $this->get(route('hr.roster.index', ['myView' => 'month', 'month' => '2026-11']))->assertInertia(fn (Assert $p) => $p
            ->where('roster.month.days.22.asks.0.status', 'confirmed')->where('roster.month.days.22.asks.0.label', 'Confirmed, roster not approved yet')->where('roster.month.days.22.asks.0.shortLabel', 'Not approved yet')
            ->has('roster.month.days.22.asks', 1));

        // Approved: it becomes a shift with its hours; the ask is no longer listed beside it.
        $version = RosterVersion::query()->where('status', 'draft')->sole();
        app(RosterService::class)->submit($this->hr, $version->public_id, ['expected_version_lock' => $version->refresh()->lock_version, 'idempotency_key' => (string) Str::uuid()]);
        app(RosterService::class)->approve($this->director, $version->public_id, ['expected_version_lock' => $version->refresh()->lock_version, 'idempotency_key' => (string) Str::uuid()]);
        $this->selectBranch($locum);
        $this->get(route('hr.roster.index', ['myView' => 'month', 'month' => '2026-11']))->assertInertia(fn (Assert $p) => $p
            ->has('roster.month.days.22.shifts', 1)->where('roster.month.days.22.shifts.0.startLabel', '8:00 AM')
            ->where('roster.month.days.22.shifts.0.hours', '5 h 30 min')->where('roster.month.days.22.asks', [])
            ->where('roster.month.hoursLabel', '5 h 30 min'));

        // Another month has none of it; the other locum sees nothing of mine.
        $this->get(route('hr.roster.index', ['myView' => 'month', 'month' => '2026-12']))->assertInertia(fn (Assert $p) => $p
            ->where('roster.month.hoursLabel', '0 h')->where('roster.month.days.0.date', '2026-11-30'));
        $this->selectBranch($other);
        $this->get(route('hr.roster.index', ['myView' => 'month', 'month' => '2026-11']))->assertInertia(fn (Assert $p) => $p
            ->where('roster.month.days.22.shifts', [])->where('roster.month.days.22.asks', [])->where('roster.month.hoursLabel', '0 h'));
    }

    public function test_the_week_stays_the_default_and_a_bad_month_falls_back_to_this_month(): void
    {
        $ca = $this->actor('ca');
        $this->selectBranch($ca);
        $this->get(route('hr.roster.index'))->assertInertia(fn (Assert $p) => $p->where('roster.view', 'week')->where('roster.month', null));
        foreach (['2026-13', 'abc', '1999-12', '2026-1', '2026-11-01'] as $bad) {
            $this->get(route('hr.roster.index', ['myView' => 'month', 'month' => $bad]))->assertOk()
                ->assertInertia(fn (Assert $p) => $p->where('roster.view', 'month')->where('roster.month.key', '2026-11'));
        }
        $this->get(route('hr.roster.index', ['myView' => 'month']))->assertInertia(fn (Assert $p) => $p->where('roster.month.key', '2026-11')
            ->where('roster.month.current', '2026-11'));
        // A planner-only viewer has no My roster, so no month either.
        $this->assertNotContains('hr.roster.view.own', PermissionCatalogue::roles()['queue_display'] ?? []);
    }

    public function test_a_confirmed_ask_whose_draft_is_discarded_or_given_away_is_not_shown(): void
    {
        $locum = $this->linkedLocum('one');
        $shift = $this->post_($this->hr);
        $this->selectBranch($locum);
        $this->post(route('hr.open-shifts.ask', $shift), ['idempotency_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
        $ask = DB::table('hr_open_shift_requests')->sole();
        $this->selectBranch($this->hr);
        $this->post(route('hr.open-shifts.confirm', $ask->public_id), ['expected_lock_version' => 1, 'idempotency_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
        $this->selectBranch($locum);
        $this->get(route('hr.roster.index', ['myView' => 'month', 'month' => '2026-11']))->assertInertia(fn (Assert $p) => $p
            ->has('roster.month.days.22.asks', 1));

        // HR discards the draft: the ask no longer says it waits for approval (Suresh, M1).
        $version = RosterVersion::query()->where('status', 'draft')->sole();
        app(RosterService::class)->discard($this->hr, $version->public_id, ['expected_version_lock' => $version->refresh()->lock_version, 'idempotency_key' => (string) Str::uuid()]);
        $this->selectBranch($locum);
        $this->get(route('hr.roster.index', ['myView' => 'month', 'month' => '2026-11']))->assertInertia(fn (Assert $p) => $p
            ->where('roster.month.days.22.asks', [])->where('roster.month.days.22.shifts', []));
    }

    public function test_a_waiting_ask_on_a_withdrawn_open_shift_is_not_shown(): void
    {
        $locum = $this->linkedLocum('one');
        $shift = $this->post_($this->hr);
        $this->selectBranch($locum);
        $this->post(route('hr.open-shifts.ask', $shift), ['idempotency_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
        $this->selectBranch($this->hr);
        $this->post(route('hr.open-shifts.withdraw', $shift), ['expected_lock_version' => 1, 'idempotency_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
        $this->selectBranch($locum);
        $this->get(route('hr.roster.index', ['myView' => 'month', 'month' => '2026-11']))->assertInertia(fn (Assert $p) => $p
            ->where('roster.month.days.22.asks', []));
    }

    // ---- fixture ----

    private function linkedLocum(string $name): User
    {
        $locum = $this->actor(PermissionCatalogue::LOCUM_ROLE);
        $worker = app(StaffFileService::class)->addLocum($this->hr, ['display_name' => "Synthetic Locum {$name}", 'phone' => '012-345 6789',
            'idempotency_key' => (string) Str::uuid()]);
        app(LocumSignIns::class)->request($this->hr, $worker->public_id, ['email' => $locum->email, 'idempotency_key' => (string) Str::uuid()]);
        $request = DB::table('hr_locum_sign_ins')->where('worker_id', $worker->id)->first();
        app(LocumSignIns::class)->link($this->director, $request->public_id, ['user_id' => $locum->id, 'expected_lock_version' => 1,
            'idempotency_key' => (string) Str::uuid()]);

        return $locum;
    }

    /** @return array<string, mixed> */
    private function shiftInput(): array
    {
        return ['branch_id' => $this->branch->id, 'date' => '2026-11-17', 'start_time' => '08:00', 'end_time' => '14:00', 'break_minutes' => '30',
            'session_type' => 'morning', 'role_needed' => 'resident_doctor', 'idempotency_key' => (string) Str::uuid()];
    }

    private function post_(User $by): string
    {
        $this->selectBranch($by);
        $this->post(route('hr.open-shifts.store'), $this->shiftInput())->assertSessionHasNoErrors();

        return (string) DB::table('hr_open_shifts')->orderByDesc('id')->value('public_id');
    }
}
