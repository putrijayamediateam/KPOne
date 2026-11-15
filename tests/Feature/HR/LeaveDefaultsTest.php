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

    private function staffSince(string $role, string $startsOn): User
    {
        $user = $this->actor($role);
        $creator = User::query()->whereKeyNot($user->id)->where('organisation_id', $user->organisation_id)->value('id') ?? $user->id;
        $worker = DB::table('hr_workers')->insertGetId(['public_id' => (string) Str::uuid(), 'organisation_id' => $user->organisation_id, 'user_id' => $user->id,
            'kind' => 'staff', 'created_by_user_id' => $creator, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('hr_employment_periods')->insert(['public_id' => (string) Str::uuid(), 'organisation_id' => $user->organisation_id, 'worker_id' => $worker,
            'type' => 'permanent', 'starts_on' => $startsOn, 'created_by_user_id' => $creator, 'created_at' => now(), 'updated_at' => now()]);

        return $user;
    }

    /** The halves of the latest entitlement row for a type and a person or a role, or null. */
    private function row(int $typeId, ?User $person = null, ?string $role = null): ?int
    {
        $value = DB::table('hr_leave_entitlements')->where('leave_type_id', $typeId)->where('year', 2027)
            ->when($person !== null, fn ($q) => $q->where('user_id', $person->id), fn ($q) => $q->where('role', $role))->orderByDesc('id')->value('halves');

        return $value === null ? null : (int) $value;
    }
}
