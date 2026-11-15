<?php

namespace Tests\Feature\HR;

use App\Domain\HR\Leave\Models\LeaveType;
use App\Domain\HR\Leave\Services\LeaveDefaultsService;
use App\Domain\HR\Roster\RosterPermissions;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Visit\VisitTestCase;

/**
 * HR-DEFAULTS (owner's draft defaults, 2026-11-15): "Use KPOne defaults" adds the Employment Act 1955 minimums as
 * marked leave types and entitlement rows, never replacing what HR entered. The clock is frozen on a branch-local
 * Monday in 2027, so service is counted at 1 January 2027.
 */
class LeaveDefaultsTest extends VisitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2027-03-01 09:00', $this->branch->timezone));
    }

    public function test_defaults_add_marked_types_and_service_tiers_once(): void
    {
        $hr = $this->actor('hr_manager');
        $new = $this->staffSince('ca', '2026-06-01');      // under 1 year: first tier
        $middle = $this->staffSince('ca', '2024-07-01');   // 2.5 years at 1 January: second tier
        $long = $this->staffSince('resident_doctor', '2020-01-01'); // 7 years: third tier
        $crossing = $this->staffSince('ca', '2025-05-01'); // 1 year at 1 January, 2 years on 1 May 2027
        $this->actor('ca_supervisor');                     // no staff file: first tier, to check by hand

        $this->selectBranch($hr);
        $this->get(route('hr.leave.index', ['tab' => 'settings', 'year' => 2027]))->assertInertia(fn (Assert $page) => $page
            ->where('leave.settings.defaults.typesToAdd', ['ANNUAL', 'SICK', 'HOSPITAL', 'MATERNITY', 'PATERNITY'])
            ->where('leave.settings.defaults.middle', 1)->where('leave.settings.defaults.long', 1)
            ->where('leave.settings.defaults.crossing', 1)->where('leave.settings.defaults.noRecord', 2)
            ->where('leave.settings.defaults.label', LeaveDefaultsService::LABEL));

        $key = (string) Str::uuid();
        $this->defaults($hr, $key)->assertSessionHasNoErrors();
        $types = LeaveType::query()->pluck('id', 'code');
        $this->assertSame(['ANNUAL', 'HOSPITAL', 'MATERNITY', 'PATERNITY', 'SICK'], $types->keys()->sort()->values()->all());
        $this->assertTrue(LeaveType::query()->where('set_by_defaults', false)->doesntExist());
        $this->assertFalse(LeaveType::query()->where('code', 'MATERNITY')->value('uses_balance'));
        $this->assertNull(LeaveType::query()->where('code', 'ANNUAL')->value('carry_max_halves'));

        // First tier for every human role; person rows only for the second and third tiers.
        foreach (RosterPermissions::HUMAN_ROLES as $role) {
            $this->assertSame(16, $this->row($types['ANNUAL'], role: $role));
            $this->assertSame(28, $this->row($types['SICK'], role: $role));
            $this->assertSame(92, $this->row($types['HOSPITAL'], role: $role));
        }
        $this->assertSame([24, 36, 84], [$this->row($types['ANNUAL'], $middle), $this->row($types['SICK'], $middle), $this->row($types['HOSPITAL'], $middle)]);
        $this->assertSame([32, 44, 76], [$this->row($types['ANNUAL'], $long), $this->row($types['SICK'], $long), $this->row($types['HOSPITAL'], $long)]);
        $this->assertNull($this->row($types['ANNUAL'], $new));
        $this->assertNull($this->row($types['ANNUAL'], $crossing));
        $this->assertSame(0, DB::table('hr_leave_entitlements')->where('set_by_defaults', false)->count());
        $this->assertSame(0, DB::table('hr_leave_ledger')->count(), 'Nothing is granted until HR gives the entitlement.');
        $audit = DB::table('audit_logs')->where('event', 'hr.leave.defaults_applied')->sole();
        $this->assertSame(['year' => 2027, 'types_created' => 5, 'entitlement_rows' => count(RosterPermissions::HUMAN_ROLES) * 3 + 6],
            array_intersect_key(json_decode((string) $audit->metadata, true), array_flip(['year', 'types_created', 'entitlement_rows'])));

        // The same key replays; a new press adds nothing.
        $rows = DB::table('hr_leave_entitlements')->count();
        $this->defaults($hr, $key)->assertSessionHasNoErrors();
        $this->defaults($hr)->assertSessionHasNoErrors();
        $this->assertSame($rows, DB::table('hr_leave_entitlements')->count());
        $this->assertSame(5, LeaveType::query()->count());

        // The grant then gives the tiers.
        $this->post(route('hr.leave.grant'), ['year' => 2027, 'idempotency_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
        $this->assertSame(24, (int) DB::table('hr_leave_ledger')->where('user_id', $middle->id)->where('leave_type_id', $types['ANNUAL'])->sum('halves'));
        $this->assertSame(16, (int) DB::table('hr_leave_ledger')->where('user_id', $new->id)->where('leave_type_id', $types['ANNUAL'])->sum('halves'));

        $this->get(route('hr.leave.index', ['tab' => 'settings', 'year' => 2027]))->assertInertia(fn (Assert $page) => $page
            ->where('leave.settings.types.0.setByDefaults', true)->where('leave.settings.defaults.typesToAdd', [])->where('leave.settings.defaults.rows', 0));
    }

    public function test_types_and_figures_hr_entered_are_never_replaced_or_lowered(): void
    {
        $hr = $this->actor('hr_manager');
        $middle = $this->staffSince('ca', '2024-07-01');
        $this->selectBranch($hr);
        $this->put(route('hr.leave.types.save'), ['code' => 'ANNUAL', 'name' => 'Cuti tahunan', 'is_paid' => true, 'uses_balance' => true,
            'allow_half_days' => true, 'evidence_needed' => false])->assertSessionHasNoErrors();
        $annual = LeaveType::query()->where('code', 'ANNUAL')->sole();
        // The clinic gives every CA 20 days, more than the second tier's 12.
        $this->put(route('hr.leave.entitlements.save'), ['year' => 2027, 'rows' => [['leave_type_id' => $annual->id, 'role' => 'ca', 'days' => '20']]])->assertSessionHasNoErrors();

        $this->defaults($hr)->assertSessionHasNoErrors();
        $annual->refresh();
        $this->assertSame(['Cuti tahunan', false, 1], [$annual->name, $annual->set_by_defaults, $annual->lock_version]);
        $this->assertSame(4, LeaveType::query()->where('set_by_defaults', true)->count());
        $this->assertSame(1, DB::table('hr_leave_entitlements')->where('leave_type_id', $annual->id)->where('role', 'ca')->count());
        $this->assertNull($this->row($annual->id, $middle), 'A person row never lowers what the role already gives.');
        $this->assertSame(36, $this->row((int) LeaveType::query()->where('code', 'SICK')->value('id'), $middle));

        // Once HR changes a default type, it is the clinic's own.
        $sick = LeaveType::query()->where('code', 'SICK')->sole();
        $this->put(route('hr.leave.types.save'), ['leave_type_id' => $sick->id, 'expected_lock_version' => $sick->lock_version, 'name' => 'Cuti sakit',
            'is_paid' => true, 'uses_balance' => true, 'allow_half_days' => false, 'evidence_needed' => true, 'is_active' => true])->assertSessionHasNoErrors();
        $this->assertFalse($sick->refresh()->set_by_defaults);
    }

    public function test_service_runs_back_through_periods_that_join_up(): void
    {
        $hr = $this->actor('hr_manager');
        $renewed = $this->staffSince('ca', '2019-01-01', '2025-02-28');
        $this->addPeriod($renewed, '2025-03-01');          // renewed the next day: 8 years unbroken
        $returned = $this->staffSince('ca', '2018-01-01', '2024-12-31');
        $this->addPeriod($returned, '2025-03-01');         // back after a two-month gap: 1 year, 2 years on 1 March 2027

        $this->defaults($hr)->assertSessionHasNoErrors();
        $types = LeaveType::query()->pluck('id', 'code');
        $this->assertSame([32, 44, 76], [$this->row($types['ANNUAL'], $renewed), $this->row($types['SICK'], $renewed), $this->row($types['HOSPITAL'], $renewed)]);
        $this->assertNull($this->row($types['ANNUAL'], $returned));
        $this->selectBranch($hr);
        $this->get(route('hr.leave.index', ['tab' => 'settings', 'year' => 2027]))->assertInertia(fn (Assert $page) => $page
            ->where('leave.settings.defaults.long', 1)->where('leave.settings.defaults.crossing', 1));
    }

    public function test_annual_leave_is_pro_rated_for_joiners_and_leavers_in_the_year(): void
    {
        // s.60E(1A): completed months of service in the year; under half a day is dropped, half or more is a day.
        $this->assertSame([6, 5, 1, 1, 8, 16], [LeaveDefaultsService::prorate(8, 9), LeaveDefaultsService::prorate(8, 7), LeaveDefaultsService::prorate(8, 1),
            LeaveDefaultsService::prorate(16, 1), LeaveDefaultsService::prorate(16, 6), LeaveDefaultsService::prorate(16, 12)]);

        $hr = $this->actor('hr_manager');
        $joiner = $this->staffSince('ca', '2027-03-15');               // 9 completed months in 2027
        $leaver = $this->staffSince('resident_doctor', '2018-01-01', '2027-09-30'); // 9 years, leaves after 9 months
        $this->defaults($hr)->assertSessionHasNoErrors();
        $types = LeaveType::query()->pluck('id', 'code');
        $this->assertSame(12, $this->row($types['ANNUAL'], $joiner));
        $this->assertNull($this->row($types['SICK'], $joiner), 'Sick leave is not pro-rated.');
        $this->assertSame(24, $this->row($types['ANNUAL'], $leaver)); // 16 days x 9/12 = 12
        $this->assertSame(44, $this->row($types['SICK'], $leaver));
        $this->assertSame(76, $this->row($types['HOSPITAL'], $leaver));
        $this->selectBranch($hr);
        $this->get(route('hr.leave.index', ['tab' => 'settings', 'year' => 2027]))->assertInertia(fn (Assert $page) => $page->where('leave.settings.defaults.prorated', 2));
    }

    public function test_a_later_press_updates_only_the_rows_kpone_wrote(): void
    {
        $hr = $this->actor('hr_manager');
        $doctor = $this->staffSince('resident_doctor', '2018-01-01'); // 9 years: 16, 22, 38
        $other = $this->staffSince('ca', '2020-01-01');               // 7 years
        $this->defaults($hr)->assertSessionHasNoErrors();
        $types = LeaveType::query()->pluck('id', 'code');
        $this->assertSame(32, $this->row($types['ANNUAL'], $doctor));
        // HR enters its own figure for the other person; a later press must never touch it.
        $this->selectBranch($hr);
        $this->put(route('hr.leave.entitlements.save'), ['year' => 2027, 'rows' => [['leave_type_id' => $types['ANNUAL'], 'user_id' => $other->id, 'days' => '25']]])->assertSessionHasNoErrors();
        $otherRows = DB::table('hr_leave_entitlements')->where('leave_type_id', $types['ANNUAL'])->where('user_id', $other->id)->count();

        // The doctor's leaving date is recorded after the press, and someone joins in May.
        $period = DB::table('hr_employment_periods as p')->join('hr_workers as w', 'w.id', '=', 'p.worker_id')->where('w.user_id', $doctor->id)->value('p.id');
        DB::table('hr_employment_periods')->where('id', $period)->update(['ends_on' => '2027-09-30', 'end_reason' => 'resigned', 'lock_version' => 2]);
        $joiner = $this->staffSince('ca', '2027-05-10'); // 7 completed months: 8 x 7/12 = 4.67, so 5 days

        $this->defaults($hr)->assertSessionHasNoErrors();
        $this->assertSame(24, $this->row($types['ANNUAL'], $doctor), '16 x 9/12 = 12 days');
        $this->assertSame(44, $this->row($types['SICK'], $doctor), 'Sick leave is unchanged, so no new row.');
        $this->assertSame(1, DB::table('hr_leave_entitlements')->where('leave_type_id', $types['SICK'])->where('user_id', $doctor->id)->count());
        $this->assertSame(10, $this->row($types['ANNUAL'], $joiner));
        $this->assertSame(50, $this->row($types['ANNUAL'], $other));
        $this->assertSame($otherRows, DB::table('hr_leave_entitlements')->where('leave_type_id', $types['ANNUAL'])->where('user_id', $other->id)->count());

        // A third press with nothing new adds nothing.
        $rows = DB::table('hr_leave_entitlements')->count();
        $this->defaults($hr)->assertSessionHasNoErrors();
        $this->assertSame($rows, DB::table('hr_leave_entitlements')->count());
    }

    public function test_hospitalisation_follows_the_sick_figure_hr_entered(): void
    {
        $hr = $this->actor('hr_manager');
        $middle = $this->staffSince('ca', '2024-07-01');
        $this->selectBranch($hr);
        $this->put(route('hr.leave.types.save'), ['code' => 'SICK', 'name' => 'Sick leave', 'is_paid' => true, 'uses_balance' => true,
            'allow_half_days' => false, 'evidence_needed' => true])->assertSessionHasNoErrors();
        $sick = (int) LeaveType::query()->where('code', 'SICK')->value('id');
        $this->put(route('hr.leave.entitlements.save'), ['year' => 2027, 'rows' => [['leave_type_id' => $sick, 'role' => 'ca', 'days' => '20']]])->assertSessionHasNoErrors();

        $this->defaults($hr)->assertSessionHasNoErrors();
        $hospital = (int) LeaveType::query()->where('code', 'HOSPITAL')->value('id');
        $this->assertSame(80, $this->row($hospital, role: 'ca'), '60 days including the 20 sick days HR gives a CA.');
        $this->assertSame(92, $this->row($hospital, role: 'ca_supervisor'));
        // HR's 20 sick days already exceed the second tier's 18, so the CA keeps them and 40 hospitalisation days.
        $this->assertNull($this->row($sick, $middle));
        $this->assertNull($this->row($hospital, $middle));
    }

    public function test_only_leave_managers_may_use_the_defaults(): void
    {
        foreach (['ca', 'ca_supervisor', 'finance_officer', 'resident_doctor'] as $role) {
            $this->defaults($this->actor($role))->assertForbidden();
        }
        $this->assertSame(0, LeaveType::query()->count());
        $this->defaults($this->actor('director'))->assertSessionHasNoErrors();
        $this->assertSame(5, LeaveType::query()->count());
    }

    public function test_a_year_and_a_key_are_required(): void
    {
        $hr = $this->actor('hr_manager');
        $this->selectBranch($hr);
        $this->post(route('hr.leave.defaults'), ['year' => 'x', 'idempotency_key' => (string) Str::uuid()])->assertSessionHasErrors(['year' => 'Choose a year.']);
        $this->post(route('hr.leave.defaults'), ['year' => 2027])->assertSessionHasErrors('leave');
        $this->assertSame(0, LeaveType::query()->count());
    }

    private function defaults(User $actor, ?string $key = null): TestResponse
    {
        $this->selectBranch($actor);

        return $this->post(route('hr.leave.defaults'), ['year' => 2027, 'idempotency_key' => $key ?? (string) Str::uuid()]);
    }

    private function staffSince(string $role, string $startsOn, ?string $endsOn = null): User
    {
        $user = $this->actor($role);
        $creator = User::query()->whereKeyNot($user->id)->where('organisation_id', $user->organisation_id)->value('id') ?? $user->id;
        DB::table('hr_workers')->insert(['public_id' => (string) Str::uuid(), 'organisation_id' => $user->organisation_id, 'user_id' => $user->id,
            'kind' => 'staff', 'created_by_user_id' => $creator, 'created_at' => now(), 'updated_at' => now()]);
        $this->addPeriod($user, $startsOn, $endsOn);

        return $user;
    }

    private function addPeriod(User $user, string $startsOn, ?string $endsOn = null): void
    {
        $worker = DB::table('hr_workers')->where('user_id', $user->id)->sole();
        DB::table('hr_employment_periods')->insert(['public_id' => (string) Str::uuid(), 'organisation_id' => $user->organisation_id, 'worker_id' => $worker->id,
            'type' => 'contract', 'starts_on' => $startsOn, 'ends_on' => $endsOn, 'end_reason' => $endsOn === null ? null : 'contract_ended',
            'created_by_user_id' => $worker->created_by_user_id, 'created_at' => now(), 'updated_at' => now()]);
    }

    /** The halves of the latest entitlement row for a type and a person or a role, or null. */
    private function row(int $typeId, ?User $person = null, ?string $role = null): ?int
    {
        $value = DB::table('hr_leave_entitlements')->where('leave_type_id', $typeId)->where('year', 2027)
            ->when($person !== null, fn ($q) => $q->where('user_id', $person->id), fn ($q) => $q->where('role', $role))->orderByDesc('id')->value('halves');

        return $value === null ? null : (int) $value;
    }
}
