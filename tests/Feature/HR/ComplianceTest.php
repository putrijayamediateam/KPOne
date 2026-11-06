<?php

namespace Tests\Feature\HR;

use App\Domain\HR\Leave\Models\LeaveRequest;
use App\Domain\HR\Leave\Models\LeaveType;
use App\Domain\HR\Roster\Models\RosterVersion;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Visit\VisitTestCase;

/**
 * HR-5b roster compliance warnings (owner, 2026-11-06: "approve the HR-5 note with the defaults"; HR-PLAN 12.3 rule
 * 7). KPOne ships no number; rules are versions; warnings come from approved and draft weeks, count approved leave as
 * a day off, and never refuse a save, a send or an approval. The clock is frozen in Kuala Lumpur.
 */
class ComplianceTest extends VisitTestCase
{
    private CarbonImmutable $week;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2027-03-01 09:00', $this->branch->timezone));
        $this->week = CarbonImmutable::parse('2027-03-08', $this->branch->timezone); // a Monday
    }

    public function test_kpone_ships_no_rule_rules_are_versions_and_only_managers_save_them(): void
    {
        $hr = $this->actor('hr_manager');
        $this->selectBranch($hr);
        $this->get(route('hr.roster.index', ['tab' => 'minimums']))->assertInertia(fn (Assert $page) => $page
            ->where('roster.minimums.compliance.coverage.version', 0)->where('roster.minimums.compliance.workHours.version', 0)
            ->where('roster.minimums.compliance.suggested', ['min_resident_doctors' => 1, 'max_days_in_row' => 6, 'min_rest_hours' => 11, 'max_week_hours' => 48]));
        $this->assertSame(0, DB::table('hr_coverage_rules')->count() + DB::table('hr_work_hour_rules')->count());

        $this->coverage($hr, [1 => ['open' => '10:00', 'close' => '09:00']], 1)->assertSessionHasErrors(['hours.1' => 'Closing must be after opening on the same day.']);
        $this->coverage($hr, [2 => ['open' => '10:00', 'close' => '']], 1)->assertSessionHasErrors(['hours.2' => 'Enter both times, or leave both empty for a closed day.']);
        $this->coverage($hr, [1 => ['open' => '08:00', 'close' => '22:00']], 21)->assertSessionHasErrors('min_resident_doctors');
        $this->coverage($hr, [1 => ['open' => '08:00', 'close' => '22:00']], 1)->assertSessionHasNoErrors();
        $this->coverage($hr, [1 => ['open' => '08:00', 'close' => '20:00']], 1, 0)->assertSessionHasErrors(['compliance' => 'These rules were changed by someone else. Reload to see the latest version.']);
        $this->coverage($hr, [1 => ['open' => '08:00', 'close' => '20:00']], 2, 1)->assertSessionHasNoErrors();
        $this->assertSame([1, 2], DB::table('hr_coverage_rules')->where('branch_id', $this->branch->id)->orderBy('version')->pluck('version')->map(fn ($v): int => (int) $v)->all());
        $this->workHours($hr, ['max_days_in_row' => 14])->assertSessionHasErrors('max_days_in_row');
        $this->workHours($hr, ['max_days_in_row' => 6, 'min_rest_hours' => '', 'max_week_hours' => 48])->assertSessionHasNoErrors();
        $this->assertNull(DB::table('hr_work_hour_rules')->value('min_rest_minutes'));
        $this->get(route('hr.roster.index', ['tab' => 'minimums']))->assertInertia(fn (Assert $page) => $page
            ->where('roster.minimums.compliance.coverage.version', 2)->where('roster.minimums.compliance.coverage.minDoctors', 2)
            ->where('roster.minimums.compliance.coverage.hours', [1 => ['open' => '08:00', 'close' => '20:00']])
            ->where('roster.minimums.compliance.workHours.maxWeekHours', 48)->where('roster.minimums.compliance.workHours.minRestHours', null));

        // Only Director and HR Manager; the TV account and other staff get 403, and see no rules on the page.
        foreach (['ca', 'resident_doctor', 'queue_display'] as $role) {
            $this->selectBranch($other = $this->actor($role));
            $this->put(route('hr.roster.compliance.coverage'), ['branch_id' => $this->branch->id, 'hours' => [], 'min_resident_doctors' => 0, 'expected_version' => 2,
                'idempotency_key' => (string) Str::uuid()])->assertForbidden();
            $this->put(route('hr.roster.compliance.work-hours'), ['expected_version' => 1, 'idempotency_key' => (string) Str::uuid()])->assertForbidden();
        }

        // PostgreSQL: versions are append-only and gapless, and stay inside the branch's organisation.
        $this->assertRefused('P0001', fn () => DB::table('hr_coverage_rules')->update(['min_resident_doctors' => 5]));
        $this->assertRefused('P0001', fn () => DB::table('hr_work_hour_rules')->delete());
        $this->assertRefused('23514', fn () => DB::table('hr_coverage_rules')->insert(['organisation_id' => $this->branch->organisation_id, 'branch_id' => $this->branch->id,
            'version' => 9, 'opening_hours' => '{}', 'min_resident_doctors' => 1, 'set_by_user_id' => $hr->id, 'created_at' => now()]));
        $this->assertSame(1, DB::table('audit_logs')->where('event', 'hr.compliance.work_hours_saved')->count());
    }

    public function test_cover_gaps_days_in_a_row_short_rest_and_planned_hours_warn_and_never_refuse(): void
    {
        $hr = $this->actor('hr_manager');
        $director = $this->actor('director');
        $doctor = $this->actor('resident_doctor');
        $draft = $this->startDraft($hr);
        $this->selectBranch($hr);
        $this->get($this->plannerUrl())->assertInertia(fn (Assert $page) => $page->where('roster.planner.compliance.setUp', false)
            ->where('roster.planner.compliance.warnings', []));

        $this->coverage($hr, array_fill_keys(range(1, 7), ['open' => '08:00', 'close' => '22:00']), 1)->assertSessionHasNoErrors();
        $this->workHours($hr, ['max_days_in_row' => 6, 'min_rest_hours' => 11, 'max_week_hours' => 48])->assertSessionHasNoErrors();
        $this->addShift($hr, $draft, $doctor, 0, '08:00', '14:00')->assertSessionHasNoErrors();
        $this->addShift($hr, $draft, $doctor, 0, '17:00', '22:00', ['session_type' => 'evening'])->assertSessionHasNoErrors();
        foreach (range(1, 6) as $day) {
            $this->addShift($hr, $draft, $doctor, $day, '08:00', '22:00', ['session_type' => $day === 6 ? 'night' : 'full_day'])->assertSessionHasNoErrors();
        }

        $compliance = $this->compliance($hr);
        $messages = collect($compliance['warnings'])->groupBy('kind')->map(fn ($g) => $g->pluck('message')->all());
        $branch = $this->branch->name;
        $this->assertSame(["No resident doctor Mon 2:00 PM – 5:00 PM ({$branch})"], $messages['coverage']);
        $this->assertSame(["{$doctor->name}: 7 days in a row, Mon 8 Mar to Sun 14 Mar (limit 6)"], $messages['days_in_row']);
        $this->assertSame(["{$doctor->name}: 3 h rest before Mon 8 Mar (11 h set)", "{$doctor->name}: 10 h rest before Tue 9 Mar (11 h set)"], array_slice($messages['rest'], 0, 2));
        $this->assertCount(7, $messages['rest']);
        $this->assertSame(["{$doctor->name}: planned 95 h this week (limit 48 h)"], $messages['hours']);
        $this->assertSame([['name' => $doctor->name, 'weekends' => 2, 'holidays' => 0, 'nights' => 1]], $compliance['rotation']['rows']);
        $this->assertSame('March 2027', $compliance['rotation']['month']);

        // Approved leave counts as a day off: Tuesday's shift no longer covers the branch and the run is broken.
        $type = $this->leaveReady($hr, $doctor);
        $this->selectBranch($doctor);
        $this->post(route('hr.leave.requests.store'), ['leave_type_id' => $type->id, 'start_date' => '2027-03-09', 'end_date' => '2027-03-09', 'start_part' => 'full',
            'end_part' => 'full', 'idempotency_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
        $leave = LeaveRequest::query()->where('user_id', $doctor->id)->sole();
        $this->selectBranch($hr);
        $this->post(route('hr.leave.requests.approve', $leave->public_id), ['expected_lock_version' => $leave->refresh()->lock_version, 'idempotency_key' => (string) Str::uuid()])
            ->assertSessionHasNoErrors();
        $after = collect($this->compliance($hr)['warnings'])->groupBy('kind')->map(fn ($g) => $g->pluck('message')->all());
        $this->assertContains("No resident doctor Tue 8:00 AM – 10:00 PM ({$branch})", $after['coverage']);
        $this->assertArrayNotHasKey('days_in_row', $after->all());

        // Warnings never refuse: the week is sent and approved as it stands, and the payload names only the rule.
        $this->submit($hr, $draft)->assertSessionHasNoErrors();
        $this->approve($director, $draft)->assertSessionHasNoErrors();
        $this->assertSame('approved', $draft->refresh()->status);
        $this->selectBranch($this->actor('ca'));
        $this->get(route('hr.roster.index'))->assertInertia(fn (Assert $page) => $page->where('roster.planner', null)->where('roster.minimums', null));
    }

    public function test_rules_are_saved_once_per_key_and_another_organisations_branch_is_refused(): void
    {
        $hr = $this->actor('hr_manager');
        $key = (string) Str::uuid();
        foreach ([1, 2] as $attempt) {
            $this->selectBranch($hr);
            $this->put(route('hr.roster.compliance.coverage'), ['branch_id' => $this->branch->id, 'hours' => [1 => ['open' => '08:00', 'close' => '22:00']],
                'min_resident_doctors' => 1, 'expected_version' => 0, 'idempotency_key' => $key])->assertSessionHasNoErrors();
        }
        $this->assertSame(1, DB::table('hr_coverage_rules')->count());
        $other = DB::table('branches')->where('organisation_id', '<>', $hr->organisation_id)->value('id') ?? 999999;
        $this->selectBranch($hr);
        $this->put(route('hr.roster.compliance.coverage'), ['branch_id' => $other, 'hours' => [], 'min_resident_doctors' => 1, 'expected_version' => 0,
            'idempotency_key' => (string) Str::uuid()])->assertSessionHasErrors(['branch_id' => 'Choose a branch.']);
    }

    /** @return array<string, mixed> */
    private function compliance(User $viewer): array
    {
        $this->selectBranch($viewer);

        return $this->get($this->plannerUrl())->viewData('page')['props']['roster']['planner']['compliance'];
    }

    private function plannerUrl(): string
    {
        return route('hr.roster.index', ['tab' => 'planner', 'branch' => $this->branch->id, 'week' => $this->week->toDateString()]);
    }

    /** @param array<int, array{open: string, close: string}> $hours */
    private function coverage(User $actor, array $hours, int $min, ?int $expected = null): TestResponse
    {
        $this->selectBranch($actor);
        $expected ??= (int) DB::table('hr_coverage_rules')->where('branch_id', $this->branch->id)->max('version');

        return $this->put(route('hr.roster.compliance.coverage'), ['branch_id' => $this->branch->id, 'hours' => $hours, 'min_resident_doctors' => $min,
            'expected_version' => $expected, 'idempotency_key' => (string) Str::uuid()]);
    }

    /** @param array<string, mixed> $input */
    private function workHours(User $actor, array $input): TestResponse
    {
        $this->selectBranch($actor);

        return $this->put(route('hr.roster.compliance.work-hours'), [...$input, 'expected_version' => (int) DB::table('hr_work_hour_rules')->max('version'),
            'idempotency_key' => (string) Str::uuid()]);
    }

    private function leaveReady(User $manager, User $person): LeaveType
    {
        $this->selectBranch($manager);
        $this->put(route('hr.leave.types.save'), ['code' => 'AL', 'name' => 'Annual leave', 'is_paid' => true, 'uses_balance' => true, 'allow_half_days' => true,
            'evidence_needed' => false])->assertSessionHasNoErrors();
        $type = LeaveType::query()->where('code', 'AL')->sole();
        $lock = DB::table('hr_branch_working_days')->where('branch_id', $this->branch->id)->value('lock_version');
        $this->put(route('hr.leave.working-days'), ['branch_id' => $this->branch->id, 'weekdays' => [1, 2, 3, 4, 5, 6, 7], 'expected_lock_version' => $lock])->assertSessionHasNoErrors();
        $this->put(route('hr.leave.entitlements.save'), ['year' => 2027, 'rows' => [['leave_type_id' => $type->id, 'role' => $person->roles->first()->name, 'days' => '14']]])
            ->assertSessionHasNoErrors();
        $this->post(route('hr.leave.grant'), ['year' => 2027, 'idempotency_key' => (string) Str::uuid()])->assertSessionHasNoErrors();

        return $type;
    }

    private function startDraft(User $actor): RosterVersion
    {
        $this->selectBranch($actor);
        $this->post(route('hr.roster.drafts.store'), ['branch_id' => $this->branch->id, 'week' => $this->week->toDateString(), 'mode' => 'blank', 'idempotency_key' => (string) Str::uuid()])
            ->assertSessionHasNoErrors();

        return RosterVersion::query()->where('branch_id', $this->branch->id)->where('status', 'draft')->latest('id')->firstOrFail();
    }

    /** @param array<string, mixed> $overrides */
    private function addShift(User $actor, RosterVersion $version, User $subject, int $day, string $start, string $end, array $overrides = []): TestResponse
    {
        $this->selectBranch($actor);

        return $this->post(route('hr.roster.shifts.store', $version->public_id), ['staff_user_id' => $subject->id, 'date' => $this->week->addDays($day)->toDateString(),
            'start_time' => $start, 'end_time' => $end, 'break_minutes' => 0, 'session_type' => 'morning', 'branch_room_id' => null, 'branch_id' => $this->branch->id,
            'expected_version_lock' => $version->refresh()->lock_version, 'idempotency_key' => (string) Str::uuid(), ...$overrides]);
    }

    private function submit(User $actor, RosterVersion $version): TestResponse
    {
        $this->selectBranch($actor);

        return $this->post(route('hr.roster.submit', $version->public_id), ['expected_version_lock' => $version->refresh()->lock_version, 'idempotency_key' => (string) Str::uuid()]);
    }

    private function approve(User $actor, RosterVersion $version): TestResponse
    {
        $this->selectBranch($actor);

        return $this->post(route('hr.roster.approve', $version->public_id), ['expected_version_lock' => $version->refresh()->lock_version, 'idempotency_key' => (string) Str::uuid()]);
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
