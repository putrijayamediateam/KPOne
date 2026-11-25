<?php

namespace Tests\Feature\HR;

use App\Domain\HR\Roster\Models\RosterShift;
use App\Domain\HR\Roster\Models\RosterVersion;
use App\Domain\HR\Roster\Models\RosterWeek;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Visit\VisitTestCase;

/**
 * HR-22e (HR-PLAN 29.6; owner, via B.E.R.N.I.S.S, 24 Nov 2026): the planner's month view and "Copy this week to the rest of
 * the month". The copy goes through the roster checks, makes or adds to one draft per later week, skips weeks waiting for
 * approval or approved, and each week is still sent and approved on its own. No migration, no new permission. Synthetic data.
 */
class RosterMonthPlannerTest extends VisitTestCase
{
    /** The first Monday of next month, in the branch time zone (never the UTC clock: BASELINE section 6 item 21). */
    private CarbonImmutable $w1;

    private string $month;

    private User $hr;

    private User $director;

    protected function setUp(): void
    {
        parent::setUp();
        $first = CarbonImmutable::now($this->branch->timezone)->startOfDay()->addMonthNoOverflow()->startOfMonth();
        $this->w1 = $first->dayOfWeekIso === 1 ? $first : $first->next(CarbonImmutable::MONDAY);
        $this->month = $this->w1->format('Y-m');
        $this->hr = $this->actor('hr_manager');
        $this->director = $this->actor('director');
    }

    public function test_the_month_view_lists_each_week_with_its_status_and_short_shifts_for_planners_only(): void
    {
        $ca = $this->actor('ca');
        $this->planStations();
        $w1 = $this->startDraft($this->w1);
        $this->addShift($w1, $this->w1, $ca, 0, '08:00', '14:00', 'registration');
        $this->addShift($w1, $this->w1, $ca, 1, '08:30', '13:30');
        $w2 = $this->startDraft($this->w1->addWeek());
        $this->addShift($w2, $this->w1->addWeek(), $ca, 0, '08:00', '14:00');
        $this->submit($w2);

        $this->selectBranch($this->hr);
        $this->get(route('hr.roster.index', ['tab' => 'planner', 'plannerView' => 'month', 'month' => $this->month]))->assertOk()
            ->assertInertia(function (Assert $page) use ($ca) {
                $month = $page->toArray()['props']['roster']['plannerMonth'];
                $this->assertSame('month', $page->toArray()['props']['roster']['plannerView']);
                $this->assertSame($this->month, $month['key']);
                $weeks = collect($month['weeks'])->keyBy('start');
                $first = $weeks[$this->w1->toDateString()];
                $this->assertSame(['draft', 'Draft', 2, true], [$first['status'], $first['statusLabel'], $first['shiftCount'], $first['canCopy']]);
                $this->assertSame($ca->id, $first['rows'][0]['staffUserId']);
                $this->assertSame([['time' => '8 AM–2 PM', 'station' => 'Reg', 'stationLabel' => 'Registration', 'onCall' => false]], $first['rows'][0]['days'][0]);
                $this->assertSame('8:30 AM–1:30 PM', $first['rows'][0]['days'][1][0]['time']);
                $this->assertSame([], $first['rows'][0]['days'][2]);
                $this->assertSame(['submitted', 'Waiting for approval'], [$weeks[$this->w1->addWeek()->toDateString()]['status'], $weeks[$this->w1->addWeek()->toDateString()]['statusLabel']]);
                $this->assertSame(['No roster yet', false], [$weeks[$this->w1->addWeeks(2)->toDateString()]['statusLabel'], $weeks[$this->w1->addWeeks(2)->toDateString()]['canCopy']]);
            });
        $this->get(route('hr.roster.index', ['tab' => 'planner', 'week' => $this->w1->toDateString()]))
            ->assertInertia(fn (Assert $page) => $page->where('roster.plannerView', 'week')->where('roster.plannerMonth', null));

        $this->selectBranch($ca);
        $this->get(route('hr.roster.index', ['tab' => 'planner', 'plannerView' => 'month', 'month' => $this->month]))
            ->assertInertia(fn (Assert $page) => $page->where('roster.planner', null)->where('roster.plannerMonth', null));
        $this->post(route('hr.roster.copy-month', $w1->public_id), ['month' => $this->month, 'idempotency_key' => (string) Str::uuid()])->assertForbidden();
    }

    public function test_copy_to_the_rest_of_the_month_makes_or_adds_to_drafts_and_skips_approved_weeks_and_leave(): void
    {
        $ca = $this->actor('ca');
        $source = $this->startDraft($this->w1);
        $this->addShift($source, $this->w1, $ca, 0, '08:00', '14:00', 'registration');
        $this->addShift($source, $this->w1, $ca, 1, '08:00', '14:00');
        $w2 = $this->startDraft($this->w1->addWeek());
        $this->addShift($w2, $this->w1->addWeek(), $ca, 3, '08:00', '14:00');
        $this->submit($w2);
        $this->approve($w2);
        $w3 = $this->startDraft($this->w1->addWeeks(2));
        $this->addShift($w3, $this->w1->addWeeks(2), $ca, 0, '09:00', '12:00'); // overlaps the copied Monday
        $tuesday = $this->w1->addWeeks(3)->addDay()->toDateString();
        $this->leave($ca, $tuesday);
        $before = DB::table('audit_logs')->where('event', 'hr.roster.week_copied_to_month')->count();

        $key = (string) Str::uuid();
        $this->selectBranch($this->hr);
        $this->post(route('hr.roster.copy-month', $source->public_id), ['month' => $this->month, 'branch_id' => $this->branch->id, 'idempotency_key' => $key])
            ->assertRedirect(route('hr.roster.index', ['tab' => 'planner', 'plannerView' => 'month', 'month' => $this->month, 'branch' => $this->branch->id]))
            ->assertSessionHasNoErrors();

        $weeks = collect($this->flash('rosterMonthCopy'))->keyBy('week');
        $label = fn (CarbonImmutable $w): string => 'Week of '.$w->format('D j M');
        $this->assertSame('skipped', $weeks[$label($this->w1->addWeek())]['outcome'], 'an approved week is left alone');
        $this->assertSame(['updated', '1 shift added.'], [$weeks[$label($this->w1->addWeeks(2))]['outcome'], $weeks[$label($this->w1->addWeeks(2))]['note']]);
        $this->assertSame(['created', '1 shift.'], [$weeks[$label($this->w1->addWeeks(3))]['outcome'], $weeks[$label($this->w1->addWeeks(3))]['note']]);
        $this->assertCount(2, $this->flash('rosterSkipped'), 'the overlapping Monday and the Tuesday on leave');

        $this->assertSame(1, $this->versions($this->w1->addWeek())->where('status', 'approved')->count());
        $this->assertSame(0, $this->versions($this->w1->addWeek())->where('status', 'draft')->count(), 'no draft over the approved week');
        $draft3 = $this->versions($this->w1->addWeeks(2))->firstWhere('status', 'draft');
        $this->assertSame(2, RosterShift::query()->where('roster_version_id', $draft3->id)->count());
        $draft4 = $this->versions($this->w1->addWeeks(3))->firstWhere('status', 'draft');
        $copied = RosterShift::query()->where('roster_version_id', $draft4->id)->get();
        $this->assertSame([['registration', '08:00']], $copied->map(fn (RosterShift $s): array => [$s->station, $s->starts_at->setTimezone($this->branch->timezone)->format('H:i')])->all());
        $this->assertSame($source->id, $draft4->based_on_version_id);
        $this->assertSame('draft', $source->refresh()->status, 'the source week is unchanged');
        $after = DB::table('audit_logs')->where('event', 'hr.roster.week_copied_to_month')->count();
        $this->assertGreaterThanOrEqual(2, $after - $before);

        // A retry with the same key changes nothing more; a different key would refuse overlaps only.
        $shiftsBefore = RosterShift::query()->count();
        $this->post(route('hr.roster.copy-month', $source->public_id), ['month' => $this->month, 'branch_id' => $this->branch->id, 'idempotency_key' => $key])->assertSessionHasNoErrors();
        $this->assertSame($shiftsBefore, RosterShift::query()->count());
        $this->assertSame($after, DB::table('audit_logs')->where('event', 'hr.roster.week_copied_to_month')->count());
    }

    public function test_a_week_waiting_for_approval_is_skipped_and_bad_requests_are_refused(): void
    {
        $ca = $this->actor('ca');
        $source = $this->startDraft($this->w1);
        $this->addShift($source, $this->w1, $ca, 0, '08:00', '14:00');
        $w2 = $this->startDraft($this->w1->addWeek());
        $this->addShift($w2, $this->w1->addWeek(), $ca, 2, '08:00', '14:00');
        $this->submit($w2);
        $empty = $this->startDraft($this->w1->addWeeks(2));
        $this->selectBranch($this->hr);
        $this->post(route('hr.roster.copy-month', $empty->public_id), ['month' => $this->month, 'idempotency_key' => (string) Str::uuid()])
            ->assertSessionHasErrors(['roster' => 'This week has no shifts to copy.']);
        $other = CarbonImmutable::parse($this->month.'-01')->addMonths(2)->format('Y-m');
        $this->post(route('hr.roster.copy-month', $source->public_id), ['month' => $other, 'idempotency_key' => (string) Str::uuid()])
            ->assertSessionHasErrors(['roster' => 'This week is not in the month shown. Reload the page.']);
        $this->post(route('hr.roster.copy-month', $source->public_id), ['month' => 'soon', 'idempotency_key' => (string) Str::uuid()])->assertSessionHasErrors(['roster' => 'Choose a month.']);

        $this->post(route('hr.roster.copy-month', $source->public_id), ['month' => $this->month, 'idempotency_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
        $this->assertSame('skipped', collect($this->flash('rosterMonthCopy'))->firstWhere('week', 'Week of '.$this->w1->addWeek()->format('D j M'))['outcome']);
        $this->assertSame(1, RosterShift::query()->where('roster_version_id', $w2->id)->count(), 'the waiting week is unchanged');

        // The month's last week now holds a copied draft; copying from it has nowhere to go.
        $lastMonday = CarbonImmutable::parse($this->month.'-01', $this->branch->timezone)->endOfMonth()->startOfDay()->startOfWeek(CarbonImmutable::MONDAY);
        $last = $this->versions($lastMonday)->firstWhere('status', 'draft');
        $this->assertNotNull($last);
        $this->post(route('hr.roster.copy-month', $last->public_id), ['month' => $this->month, 'idempotency_key' => (string) Str::uuid()])
            ->assertSessionHasErrors(['roster' => 'This is the last week of the month. There is no later week to copy to.']);
    }

    // ---- fixture ----

    /** @return array<int, mixed> */
    private function flash(string $key): array
    {
        return (array) (session('inertia.flash_data')[$key] ?? []);
    }

    /** @return Collection<int, RosterVersion> */
    private function versions(CarbonImmutable $weekStart)
    {
        $week = RosterWeek::query()->where('branch_id', $this->branch->id)->whereDate('week_start', $weekStart->toDateString())->first();

        return $week === null ? collect() : RosterVersion::query()->where('roster_week_id', $week->id)->get();
    }

    private function startDraft(CarbonImmutable $week): RosterVersion
    {
        $this->selectBranch($this->hr);
        $this->post(route('hr.roster.drafts.store'), ['branch_id' => $this->branch->id, 'week' => $week->toDateString(), 'mode' => 'blank', 'idempotency_key' => (string) Str::uuid()])
            ->assertSessionHasNoErrors();

        return RosterVersion::query()->where('branch_id', $this->branch->id)->where('status', 'draft')->latest('id')->firstOrFail();
    }

    private function addShift(RosterVersion $version, CarbonImmutable $week, User $subject, int $day, string $start, string $end, ?string $station = null): void
    {
        $this->selectBranch($this->hr);
        $this->post(route('hr.roster.shifts.store', $version->public_id), ['staff_user_id' => $subject->id, 'date' => $week->addDays($day)->toDateString(),
            'start_time' => $start, 'end_time' => $end, 'break_minutes' => 0, 'session_type' => 'morning', 'branch_room_id' => null, 'station' => $station,
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

    /** Synthetic approved full-day leave at this branch. */
    private function leave(User $person, string $day): void
    {
        $type = DB::table('hr_leave_types')->where('organisation_id', $this->organisation->id)->where('code', 'AL')->value('id')
            ?? DB::table('hr_leave_types')->insertGetId(['organisation_id' => $this->organisation->id, 'code' => 'AL', 'name' => 'Synthetic annual', 'is_paid' => true,
                'uses_balance' => false, 'allow_half_days' => true, 'evidence_needed' => false, 'updated_by_user_id' => $this->hr->id, 'created_at' => now(), 'updated_at' => now()]);
        $next = CarbonImmutable::parse($day)->addDay()->toDateString();
        $id = DB::table('hr_leave_requests')->insertGetId(['public_id' => (string) Str::uuid(), 'organisation_id' => $this->organisation->id, 'user_id' => $person->id,
            'leave_type_id' => $type, 'branch_id' => $this->branch->id, 'start_date' => $day, 'end_date' => $day, 'start_part' => 'full', 'end_part' => 'full',
            'slot_start' => "{$day} 00:00:00", 'slot_end' => "{$next} 00:00:00", 'year' => (int) substr($day, 0, 4), 'halves' => 2,
            'status' => 'pending', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('hr_leave_requests')->where('id', $id)->update(['status' => 'approved', 'decided_by_user_id' => $this->director->id, 'decided_at' => now()]);
    }
}
