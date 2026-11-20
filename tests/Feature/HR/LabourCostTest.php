<?php

namespace Tests\Feature\HR;

use App\Domain\Access\PermissionCatalogue;
use App\Domain\HR\LabourCost\LabourCostPermissions;
use App\Domain\Organisation\Models\Branch;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Role;
use Tests\Feature\Visit\VisitTestCase;

/**
 * HR-18 labour cost (owner, 18 and 20 Nov 2026): branch-by-month aggregates from approved pay runs and paid locum months;
 * fewer than 3 people is not shown, and one hidden branch hides the next smallest too. Synthetic data only.
 */
class LabourCostTest extends VisitTestCase
{
    private const MONTH = '2026-11-01';

    private Branch $second;

    private Branch $third;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-11-15 10:00', $this->branch->timezone));
        $others = Branch::query()->where('organisation_id', $this->branch->organisation_id)->where('id', '<>', $this->branch->id)->where('is_active', true)->orderBy('id')->get();
        [$this->second, $this->third] = [$others[0], $others[1]];
    }

    /** @return list<array{string, int}> */
    public static function viewers(): array
    {
        return [['director', 200], ['hr_manager', 200], ['finance_officer', 200], ['ca', 403], ['ca_supervisor', 403], ['resident_doctor', 403], ['technical_admin', 403], ['queue_display', 403]];
    }

    #[DataProvider('viewers')]
    public function test_only_the_director_hr_manager_and_finance_officer_open_the_page(string $role, int $status): void
    {
        $this->selectBranch($this->actor($role));
        $this->get(route('hr.labour-cost.index'))->assertStatus($status);
    }

    public function test_the_grant_is_exact_and_not_people_or_access_authority(): void
    {
        $this->assertSame(['director', 'finance_officer', 'hr_manager'], Role::query()->whereHas('permissions', fn ($q) => $q->where('name', LabourCostPermissions::VIEW))->orderBy('name')->pluck('name')->all());
        $this->assertFalse(PermissionCatalogue::isAdministrativeAuthority(LabourCostPermissions::VIEW));
    }

    public function test_branches_with_fewer_than_three_people_are_hidden_and_one_hidden_branch_hides_the_next_smallest(): void
    {
        $group = $this->group();
        // Main branch: 3 staff; second branch: 4 staff and a locum; third branch: 1 staff.
        $main = array_map(fn () => $this->worker($this->actor('ca')), range(1, 3));
        $second = array_map(fn () => $this->worker($this->actor('ca', $this->second)), range(1, 4));
        $third = [$this->worker($this->actor('ca', $this->third))];
        $people = [];
        foreach ([...$main, ...$second, ...$third] as $worker) {
            $people[] = [$worker, 300000, 40000]; // RM 3,000.00 pay and RM 400.00 employer contributions each
        }
        $this->payRun($group, 'approved', $people, unpaid: [$second[0] => 10000]); // RM 100.00 unpaid leave
        $this->payRun($this->group('Other group'), 'sent', [[$this->worker($this->actor('ca')), 999999, 0]]); // waiting: not counted
        $locum = $this->locumPaid([['branch' => $this->second->name, 'amount' => 50000], ['branch' => $this->second->name, 'amount' => 0]], [['amount' => -1000]]);

        $page = $this->page($this->actor('finance_officer'));
        $rows = collect($page['rows'])->keyBy('branch');
        $this->assertSame('shown', $rows[$this->second->name]['state']);
        $this->assertSame(5, $rows[$this->second->name]['people']); // 4 staff and the locum
        $this->assertSame('RM 11,900.00', $rows[$this->second->name]['pay']); // 4 × 3,000 less 100 unpaid leave
        $this->assertSame('RM 1,600.00', $rows[$this->second->name]['employer']);
        $this->assertSame('RM 500.00', $rows[$this->second->name]['locum']);
        $this->assertSame('RM 14,000.00', $rows[$this->second->name]['total']);
        $this->assertSame('few', $rows[$this->third->name]['state']);
        $this->assertSame('protect', $rows[$this->branch->name]['state']); // the next smallest, so the total cannot reveal the third
        foreach ([$this->third->name, $this->branch->name] as $hidden) {
            $this->assertArrayNotHasKey('pay', $rows[$hidden]);
            $this->assertArrayNotHasKey('people', $rows[$hidden]);
        }
        $this->assertSame(['people' => 9, 'pay' => 'RM 23,900.00', 'employer' => 'RM 3,200.00', 'locum' => 'RM 490.00', 'total' => 'RM 27,590.00'], $page['total']);
        $this->assertSame('RM -10.00', $page['locumCorrections']);
        $this->assertSame([1, 1, 0, 0], [$page['runs'], $page['waiting'], $page['unassigned'], $page['unreadable']]);
        $this->assertNotNull($locum);

        // Nothing per person leaves the server.
        $json = (string) json_encode($page);
        $this->assertStringNotContainsString('worker', $json);
        $this->assertStringNotContainsString('"name"', $json);
    }

    public function test_a_clinic_total_under_three_people_is_hidden_and_an_unreadable_total_is_counted(): void
    {
        $group = $this->group();
        $this->payRun($group, 'locked', [[$this->worker($this->actor('ca')), 300000, 40000], [$this->worker($this->actor('ca')), 300000, 40000]]);
        $page = $this->page($this->actor('director'));
        $this->assertNull($page['total']);
        $this->assertTrue($page['totalHidden']);
        $this->assertSame('few', collect($page['rows'])->firstWhere('branch', $this->branch->name)['state']);

        $this->payRun($this->group('Second'), 'approved', [[$this->worker($this->actor('ca')), 1, 1]], totalsOverride: 'not-encrypted');
        $this->assertSame(1, $this->page($this->actor('director'))['unreadable']);
    }

    public function test_a_staff_member_counts_at_the_primary_branch_on_the_months_last_day(): void
    {
        $mover = $this->actor('ca'); // primary at the main branch from yesterday
        // From 20 Nov the primary branch is the second branch.
        $profile = DB::table('staff_profiles')->where('user_id', $mover->id)->value('id');
        DB::table('staff_branch_assignments')->where('staff_profile_id', $profile)->update(['valid_until' => '2026-11-19']);
        DB::table('staff_branch_assignments')->insert(['staff_profile_id' => $profile, 'branch_id' => $this->second->id, 'is_primary' => true,
            'assignment_type' => 'permanent', 'valid_from' => '2026-11-20', 'valid_until' => null, 'created_at' => now(), 'updated_at' => now()]);
        $people = [[$this->worker($mover), 100000, 0]];
        foreach (range(1, 2) as $i) {
            $people[] = [$this->worker($this->actor('ca', $this->second)), 100000, 0];
        }
        $this->payRun($this->group(), 'approved', $people);
        $rows = collect($this->page($this->actor('director'))['rows'])->keyBy('branch');
        $this->assertSame(3, $rows[$this->second->name]['people']);
        $this->assertSame('none', $rows[$this->branch->name]['state']);
    }

    // ---- fixture ----

    /** @return array<string, mixed> */
    private function page(User $actor, string $month = '2026-11'): array
    {
        $this->selectBranch($actor);

        return $this->get(route('hr.labour-cost.index', ['month' => $month]))->assertOk()->viewData('page')['props']['labourCost'];
    }

    private function worker(User $user): int
    {
        return (int) DB::table('hr_workers')->insertGetId(['public_id' => (string) Str::uuid(), 'organisation_id' => $user->organisation_id, 'user_id' => $user->id,
            'kind' => 'staff', 'created_by_user_id' => $user->id, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function group(string $name = 'Clinic staff'): int
    {
        $by = $this->actor('hr_manager');

        return (int) DB::table('hr_pay_groups')->insertGetId(['public_id' => (string) Str::uuid(), 'organisation_id' => $by->organisation_id, 'name' => $name,
            'pay_day' => 28, 'is_active' => true, 'created_by_user_id' => $by->id, 'created_at' => now(), 'updated_at' => now()]);
    }

    /**
     * The StaffCostTest fixture: a run walked through its states one at a time, so the database checks accept each.
     *
     * @param  list<array{int, int, int}>  $people  worker id, gross earnings (sen), employer contributions (sen)
     * @param  array<int, int>  $unpaid  worker id => unpaid-leave deduction (sen)
     */
    private function payRun(int $group, string $status, array $people, array $unpaid = [], ?string $totalsOverride = null): int
    {
        $org = $this->organisation->id;
        $preparer = $this->actor('hr_manager');
        $sender = $this->actor('hr_manager');
        $approver = $this->actor('director');
        $id = DB::table('hr_payroll_runs')->insertGetId(['public_id' => (string) Str::uuid(), 'organisation_id' => $org, 'pay_group_id' => $group, 'month' => self::MONTH, 'kind' => 'regular',
            'adjusts_run_id' => null, 'status' => 'draft', 'group_name' => 'g', 'prepared_by_user_id' => $preparer->id, 'prepared_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        foreach ($people as [$worker, $earnings, $employer]) {
            $personId = DB::table('hr_payroll_run_people')->insertGetId(['organisation_id' => $org, 'run_id' => $id, 'worker_id' => $worker, 'errors' => '[]', 'warnings' => '[]',
                'totals_encrypted' => $totalsOverride ?? Crypt::encryptString(json_encode(['earnings' => $earnings, 'deduction' => $unpaid[$worker] ?? 0, 'refund' => 0, 'employee' => 0, 'employer' => $employer, 'mtd' => 0, 'net' => $earnings]))]);
            if (isset($unpaid[$worker])) {
                DB::table('hr_payroll_lines')->insert(['organisation_id' => $org, 'run_id' => $id, 'run_person_id' => $personId, 'position' => 1, 'kind' => 'unpaid_leave', 'section' => 'deduction',
                    'amount_encrypted' => Crypt::encryptString((string) $unpaid[$worker]), 'explanation_encrypted' => Crypt::encryptString('Unpaid leave')]);
            }
        }
        if ($status !== 'draft') {
            DB::table('hr_payroll_runs')->where('id', $id)->update(['status' => 'sent', 'sent_by_user_id' => $sender->id, 'sent_at' => now(), 'versions' => '{}']);
        }
        if (in_array($status, ['approved', 'locked'], true)) {
            DB::table('hr_payroll_runs')->where('id', $id)->update(['status' => 'approved', 'approved_by_user_id' => $approver->id, 'approved_at' => now()]);
        }
        if ($status === 'locked') {
            DB::table('hr_payroll_runs')->where('id', $id)->update(['status' => 'locked', 'locked_by_user_id' => $approver->id, 'locked_at' => now()]);
        }

        return (int) $id;
    }

    /**
     * A locum month recorded as paid, with the statement as paid (the shape HR-15g stores).
     *
     * @param  list<array<string, mixed>>  $lines
     * @param  list<array<string, mixed>>  $corrections
     */
    private function locumPaid(array $lines, array $corrections): int
    {
        $hr = $this->actor('hr_manager');
        $finance = $this->actor('finance_officer');
        $worker = (int) DB::table('hr_workers')->insertGetId(['public_id' => (string) Str::uuid(), 'organisation_id' => $hr->organisation_id, 'user_id' => null,
            'kind' => 'locum', 'display_name' => 'Dr Synthetic Locum', 'created_by_user_id' => $hr->id, 'created_at' => now(), 'updated_at' => now()]);
        $total = array_sum(array_column($lines, 'amount')) + array_sum(array_column($corrections, 'amount'));
        DB::table('hr_locum_pay_months')->insert(['public_id' => (string) Str::uuid(), 'organisation_id' => $hr->organisation_id, 'worker_id' => $worker, 'month' => self::MONTH,
            'total_encrypted' => Crypt::encryptString((string) $total),
            'statement_encrypted' => Crypt::encryptString(json_encode(['total' => $total, 'lines' => $lines, 'corrections' => $corrections])),
            'paid_on' => '2026-11-15', 'paid_by_user_id' => $finance->id, 'recorded_at' => now(), 'created_at' => now()]);

        return $worker;
    }
}
