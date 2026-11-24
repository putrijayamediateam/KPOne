<?php

namespace Tests\Feature\HR;

use App\Domain\HR\Roster\Models\RosterShift;
use App\Domain\HR\Roster\Models\RosterVersion;
use App\Domain\HR\Roster\Services\RosterOnDuty;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\HttpFoundation\Response;
use Tests\Feature\Visit\VisitTestCase;

/**
 * HR-22c (HR-PLAN 29.4 and 29.8; owner, 24 Nov 2026): a CA, CA Supervisor or Medical Assistant shift may carry a station
 * (Registration, MA, Dispensary, Treatment); doctors get a room only; on call has none; a branch may plan stations (off by
 * default) to see a warning only. Synthetic data only.
 */
class RosterStationTest extends VisitTestCase
{
    private CarbonImmutable $week;

    private User $hr;

    private User $director;

    protected function setUp(): void
    {
        parent::setUp();
        // Next week's Monday in the branch time zone (never the UTC clock: BASELINE section 6 item 21).
        $this->week = CarbonImmutable::now($this->branch->timezone)->startOfDay()->addWeek()->startOfWeek(CarbonImmutable::MONDAY);
        $this->hr = $this->actor('hr_manager');
        $this->director = $this->actor('director');
    }

    public function test_a_ca_or_ma_shift_takes_a_station_shown_on_the_planner_my_roster_and_the_on_duty_lookup(): void
    {
        $ca = $this->actor('ca');
        $ma = $this->actor('medical_assistant');
        $version = $this->startDraft();
        $this->addShift($version, $ca, 0, '08:00', '14:00', 'dispensary')->assertSessionHasNoErrors();
        $this->addShift($version, $ma, 0, '08:00', '14:00', 'ma')->assertSessionHasNoErrors();
        $this->addShift($version, $ca, 1, '08:00', '14:00')->assertSessionHasNoErrors(); // optional
        $this->assertSame(['dispensary', 'ma', null], RosterShift::query()->orderBy('id')->pluck('station')->all());

        $this->get(route('hr.roster.index', ['tab' => 'planner', 'week' => $this->week->toDateString()]))->assertInertia(fn (Assert $page) => $page
            ->where('roster.planner.stations', [['value' => 'registration', 'label' => 'Registration'], ['value' => 'ma', 'label' => 'Medical Assistant (MA) desk'],
                ['value' => 'dispensary', 'label' => 'Dispensary'], ['value' => 'treatment', 'label' => 'Treatment']])
            ->where('roster.planner.planStations', false));
        $this->submit($version);
        $this->approve($version);

        $this->selectBranch($ca);
        $this->get(route('hr.roster.index', ['week' => $this->week->toDateString()]))->assertInertia(fn (Assert $page) => $page
            ->where('roster.my.days.0.shifts.0.station', 'dispensary')->where('roster.my.days.0.shifts.0.stationLabel', 'Dispensary'));
        $onDuty = app(RosterOnDuty::class)->onDutyNow($this->branch, $this->at('0 09:00'));
        $this->assertEqualsCanonicalizing([[$ca->id, 'dispensary'], [$ma->id, 'ma']], array_map(fn ($s): array => [$s->userId, $s->station], $onDuty));
    }

    public function test_a_doctor_an_on_call_shift_and_an_unknown_station_are_refused(): void
    {
        $doctor = $this->actor('resident_doctor');
        $ca = $this->actor('ca');
        $version = $this->startDraft();
        $this->addShift($version, $doctor, 0, '08:00', '14:00', 'registration')->assertSessionHasErrors('station');
        $this->addShift($version, $ca, 0, '20:00', '08:00', 'registration', 'on_call')->assertSessionHasErrors('station');
        $this->addShift($version, $ca, 0, '08:00', '14:00', 'pharmacy')->assertSessionHasErrors('station');
        $this->assertSame(0, RosterShift::query()->count());
        $this->staffOption($doctor, false);
        $this->staffOption($ca, true);
    }

    public function test_changing_only_the_station_is_recorded_and_the_planner_offers_it_only_to_station_roles(): void
    {
        $ca = $this->actor('ca_supervisor');
        $version = $this->startDraft();
        $this->addShift($version, $ca, 0, '08:00', '14:00', 'registration')->assertSessionHasNoErrors();
        $shift = RosterShift::query()->sole();
        $this->put(route('hr.roster.shifts.update', [$version->public_id, $shift->public_id]), $this->fields($ca, 0, '08:00', '14:00', 'treatment')
            + ['expected_version_lock' => $version->refresh()->lock_version])->assertSessionHasNoErrors();
        $this->assertSame('treatment', $shift->refresh()->station);
        $this->assertSame(['station'], json_decode((string) DB::table('audit_logs')->where('event', 'hr.roster.shift_updated')->latest('id')->value('metadata'), true)['changed_fields']);
    }

    public function test_copy_last_approved_week_keeps_the_station_while_the_person_still_takes_one(): void
    {
        $ca = $this->actor('ca');
        $moved = $this->actor('ca');
        $v1 = $this->startDraft();
        $this->addShift($v1, $ca, 0, '08:00', '14:00', 'dispensary')->assertSessionHasNoErrors();
        $this->addShift($v1, $moved, 1, '08:00', '14:00', 'registration')->assertSessionHasNoErrors();
        $this->submit($v1);
        $this->approve($v1);
        $moved->syncRoles(['resident_doctor']); // now a doctor: the copy keeps the shift but drops the station

        $this->selectBranch($this->hr);
        $this->post(route('hr.roster.drafts.store'), ['branch_id' => $this->branch->id, 'week' => $this->week->addWeek()->toDateString(), 'mode' => 'copy',
            'idempotency_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
        $copy = RosterVersion::query()->where('roster_week_id', '<>', $v1->roster_week_id)->sole();
        $this->assertSame([[$ca->id, 'dispensary'], [$moved->id, null]],
            $copy->shifts()->orderBy('starts_at')->get()->map(fn (RosterShift $s): array => [$s->staff_user_id, $s->station])->all());
    }

    public function test_plan_stations_is_off_by_default_and_only_adds_a_warning(): void
    {
        $ca = $this->actor('ca');
        $doctor = $this->actor('resident_doctor');
        $version = $this->startDraft();
        $this->addShift($version, $ca, 0, '08:00', '14:00')->assertSessionHasNoErrors();
        $this->addShift($version, $ca, 2, '08:00', '14:00')->assertSessionHasNoErrors();
        $this->addShift($version, $doctor, 0, '08:00', '14:00')->assertSessionHasNoErrors();
        $warnings = fn (): array => $this->get(route('hr.roster.index', ['tab' => 'planner', 'week' => $this->week->toDateString()]))
            ->viewData('page')['props']['roster']['planner']['warnings'];
        $this->assertSame([], array_values(array_filter($warnings(), fn (string $w): bool => str_starts_with($w, 'No station'))));

        $this->get(route('hr.roster.index', ['tab' => 'minimums']))->assertInertia(fn (Assert $page) => $page->where('roster.minimums.planStations', false));
        $this->put(route('hr.roster.minimums'), ['branch_id' => $this->branch->id, 'minimums' => [], 'plan_stations' => 'maybe', 'expected_lock_version' => 0])
            ->assertSessionHasErrors('plan_stations');
        $this->put(route('hr.roster.minimums'), ['branch_id' => $this->branch->id, 'minimums' => [], 'plan_stations' => true, 'expected_lock_version' => 0])
            ->assertSessionHasNoErrors();
        $this->assertContains('plan_stations', json_decode((string) DB::table('audit_logs')->where('event', 'hr.roster.minimum_staffing_updated')->latest('id')->value('metadata'), true)['changed_fields']);
        $this->assertSame(["No station for {$ca->name} on {$this->week->format('D j M')}, {$this->week->addDays(2)->format('D j M')}."],
            array_values(array_filter($warnings(), fn (string $w): bool => str_starts_with($w, 'No station')))); // never for the doctor
        // A warning only: the week still sends and approves.
        $this->submit($version);
        $this->approve($version);
        // Saving the minimums without the switch keeps it.
        $this->selectBranch($this->hr);
        $this->put(route('hr.roster.minimums'), ['branch_id' => $this->branch->id, 'minimums' => [], 'expected_lock_version' => 1])->assertSessionHasNoErrors();
        $this->assertTrue((bool) DB::table('hr_minimum_staffing')->where('branch_id', $this->branch->id)->value('plan_stations'));
    }

    public function test_approval_refuses_a_station_on_a_person_who_no_longer_takes_one(): void
    {
        $ca = $this->actor('ca');
        $version = $this->startDraft();
        $this->addShift($version, $ca, 0, '08:00', '14:00', 'registration')->assertSessionHasNoErrors();
        $this->submit($version);
        $ca->syncRoles(['resident_doctor']); // now a doctor
        $this->selectBranch($this->director);
        $this->post(route('hr.roster.approve', $version->public_id), ['expected_version_lock' => $version->refresh()->lock_version, 'idempotency_key' => (string) Str::uuid()])
            ->assertSessionHasErrors(['roster' => "{$ca->name} no longer takes a station. Remove the station from their shift, then approve."]);
        $this->assertSame('submitted', $version->refresh()->status);
    }

    public function test_postgresql_refuses_a_station_for_a_doctor_on_call_or_an_unknown_value(): void
    {
        $doctor = $this->actor('resident_doctor');
        $ca = $this->actor('ca');
        $version = $this->startDraft();
        $this->addShift($version, $ca, 0, '08:00', '14:00')->assertSessionHasNoErrors();
        $this->addShift($version, $doctor, 1, '08:00', '14:00')->assertSessionHasNoErrors();
        $caShift = RosterShift::query()->where('staff_user_id', $ca->id)->sole();
        $doctorShift = RosterShift::query()->where('staff_user_id', $doctor->id)->sole();

        $this->refused('23514', fn () => DB::table('hr_roster_shifts')->where('id', $doctorShift->id)->update(['station' => 'registration']));
        $this->refused('23514', fn () => DB::table('hr_roster_shifts')->where('id', $caShift->id)->update(['station' => 'pharmacy']));
        $this->refused('23514', fn () => DB::table('hr_roster_shifts')->where('id', $caShift->id)->update(['station' => 'registration', 'session_type' => 'on_call']));
        DB::table('hr_roster_shifts')->where('id', $caShift->id)->update(['station' => 'registration']);
        $this->assertSame('registration', $caShift->refresh()->station);
    }

    // ---- fixture ----

    private function staffOption(User $person, bool $takesStation): void
    {
        $this->get(route('hr.roster.index', ['tab' => 'planner', 'week' => $this->week->toDateString()]))->assertInertia(function (Assert $page) use ($person, $takesStation) {
            /** @var list<array{id: int, takesStation: bool}> $options */
            $options = $page->toArray()['props']['roster']['planner']['staffOptions'];
            $option = array_values(array_filter($options, fn (array $o): bool => $o['id'] === $person->id))[0] ?? null;
            $this->assertSame($takesStation, $option['takesStation'] ?? null);
        });
    }

    private function at(string $when): CarbonImmutable
    {
        [$day, $time] = explode(' ', $when);

        return CarbonImmutable::parse($this->week->addDays((int) $day)->toDateString().' '.$time, $this->branch->timezone);
    }

    private function startDraft(): RosterVersion
    {
        $this->selectBranch($this->hr);
        $this->post(route('hr.roster.drafts.store'), ['branch_id' => $this->branch->id, 'week' => $this->week->toDateString(), 'mode' => 'blank', 'idempotency_key' => (string) Str::uuid()])
            ->assertSessionHasNoErrors();

        return RosterVersion::query()->where('branch_id', $this->branch->id)->where('status', 'draft')->latest('id')->firstOrFail();
    }

    /** @return array<string, mixed> */
    private function fields(User $subject, int $day, string $start, string $end, ?string $station = null, string $session = 'morning'): array
    {
        return ['staff_user_id' => $subject->id, 'date' => $this->week->addDays($day)->toDateString(), 'start_time' => $start, 'end_time' => $end,
            'break_minutes' => 0, 'session_type' => $session, 'branch_room_id' => null, 'station' => $station, 'branch_id' => $this->branch->id,
            'idempotency_key' => (string) Str::uuid()];
    }

    /** @return TestResponse<Response> */
    private function addShift(RosterVersion $version, User $subject, int $day, string $start, string $end, ?string $station = null, string $session = 'morning'): TestResponse
    {
        $this->selectBranch($this->hr);

        return $this->post(route('hr.roster.shifts.store', $version->public_id), $this->fields($subject, $day, $start, $end, $station, $session)
            + ['expected_version_lock' => $version->refresh()->lock_version]);
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

    private function refused(string $state, callable $write): void
    {
        try {
            DB::transaction(fn () => $write());
            $this->fail("Expected PostgreSQL to refuse with {$state}.");
        } catch (QueryException $e) {
            $this->assertSame($state, $e->errorInfo[0] ?? null, $e->getMessage());
        }
    }
}
