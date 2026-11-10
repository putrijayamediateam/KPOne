<?php

namespace Tests\Feature\HR;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Visit\VisitTestCase;

/**
 * HR-9b staff cost on the HR dashboard: one clinic-wide monthly figure from approved or locked pay runs, for the
 * Director only. Synthetic data; every address is `.test`. Runs are inserted directly (the run flow has its own tests).
 */
class StaffCostTest extends VisitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2027-03-08 10:00', 'Asia/Kuala_Lumpur'));
    }

    public function test_the_director_sees_one_total_from_approved_and_locked_runs_only(): void
    {
        $director = $this->actor('director');
        $a = $this->worker($this->actor('ca'));
        $b = $this->worker($this->actor('ca'));
        $group = $this->group($director);
        $this->payRun($group, '2027-03-01', 'locked', [[$a, 300000, 33000], [$b, 250000, 27500]]);
        // A second approved run in the same month (another group) and an adjustment add to the same figure.
        $other = $this->group($director, 'Second group');
        $this->payRun($other, '2027-03-01', 'approved', [[$a, 10000, 1000]]);
        // Drafts and runs waiting for approval never count, but the page says how many are waiting.
        $third = $this->group($director, 'Third group');
        $this->payRun($third, '2027-03-01', 'draft', [[$b, 999999, 99999]]);
        $fourth = $this->group($director, 'Fourth group');
        $this->payRun($fourth, '2027-03-01', 'sent', [[$b, 888888, 88888]]);
        // Another month never leaks in.
        $this->payRun($group, '2027-02-01', 'locked', [[$a, 777777, 77777]]);

        $cost = $this->dashboard($director)['staffCost'];

        $this->assertSame(2, $cost['runs']);
        $this->assertSame(2, $cost['waiting']);
        $this->assertSame(2, $cost['people'], 'A person in two runs counts once.');
        $this->assertSame(560000, $cost['earnings']);
        $this->assertSame(61500, $cost['employer']);
        $this->assertSame(621500, $cost['total']);
        $this->assertSame('RM 6,215.00', $cost['totalText']);
    }

    public function test_the_month_picker_changes_the_month_and_an_empty_month_reads_zero_runs(): void
    {
        $director = $this->actor('director');
        $group = $this->group($director);
        $this->payRun($group, '2027-02-01', 'locked', [[$this->worker($this->actor('ca')), 100000, 10000]]);

        $this->assertSame(0, $this->dashboard($director)['staffCost']['runs']);
        $feb = $this->dashboard($director, ['month' => '2027-02'])['staffCost'];
        $this->assertSame(1, $feb['runs']);
        $this->assertSame(110000, $feb['total']);
    }

    public function test_only_the_director_gets_the_figure_and_nothing_per_person_or_branch_leaves_the_server(): void
    {
        $director = $this->actor('director');
        $staff = $this->actor('ca');
        $group = $this->group($director);
        $this->payRun($group, '2027-03-01', 'locked', [[$this->worker($staff), 300000, 33000]]);

        $this->assertNull($this->dashboard($this->actor('hr_manager'))['staffCost']);
        $payload = json_encode($this->dashboard($director));
        $this->assertStringNotContainsString($staff->name, (string) $payload);
        $this->assertSame(['runs', 'waiting', 'people', 'earnings', 'employer', 'total', 'earningsText', 'employerText', 'totalText'], array_keys($this->dashboard($director)['staffCost']));
        foreach ($this->dashboard($director)['branches'] as $branch) {
            $this->assertArrayNotHasKey('staffCost', $branch);
            $this->assertArrayNotHasKey('cost', $branch['month']);
        }
    }

    public function test_the_audit_row_names_the_cost_section_for_the_director_and_nobody_else(): void
    {
        $this->dashboard($this->actor('hr_manager'));
        $this->dashboard($this->actor('director'));
        $rows = DB::table('audit_logs')->where('event', 'hr.dashboard.viewed')->orderBy('id')->get();
        $this->assertCount(2, $rows);
        $this->assertStringNotContainsString('staff_cost', (string) json_encode($rows[0]->metadata ?? $rows[0]->context ?? ''));
        $this->assertStringContainsString('staff_cost', (string) json_encode($rows[1]->metadata ?? $rows[1]->context ?? ''));
    }

    public function test_viewing_the_cost_writes_nothing_but_the_audit_row(): void
    {
        $director = $this->actor('director');
        $this->payRun($this->group($director), '2027-03-01', 'locked', [[$this->worker($this->actor('ca')), 100000, 10000]]);
        $tables = ['hr_payroll_runs', 'hr_payroll_run_people', 'hr_payroll_lines', 'hr_payroll_run_actions', 'hr_pay_groups', 'hr_workers'];
        $count = fn (): array => array_map(fn (string $t): int => DB::table($t)->count(), $tables);
        $before = $count();
        $this->dashboard($director);
        $this->assertSame($before, $count());
    }

    /** @param  array<string, mixed>  $query */
    private function dashboard(User $actor, array $query = []): array
    {
        $this->selectBranch($actor);
        $page = null;
        $this->get(route('hr.dashboard.index', $query))->assertOk()->assertInertia(function (Assert $p) use (&$page): void {
            $page = $p->toArray()['props']['dashboard'];
        });

        return $page;
    }

    private function worker(User $user): int
    {
        return (int) DB::table('hr_workers')->insertGetId(['public_id' => (string) Str::uuid(), 'organisation_id' => $user->organisation_id, 'user_id' => $user->id,
            'kind' => 'staff', 'created_by_user_id' => $user->id, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function group(User $by, string $name = 'Clinic staff'): int
    {
        return (int) DB::table('hr_pay_groups')->insertGetId(['public_id' => (string) Str::uuid(), 'organisation_id' => $by->organisation_id, 'name' => $name,
            'pay_day' => 28, 'is_active' => true, 'created_by_user_id' => $by->id, 'created_at' => now(), 'updated_at' => now()]);
    }

    /** @param  list<array{int, int, int}>  $people  worker id, gross earnings (sen), employer contributions (sen) */
    private function payRun(int $group, string $month, string $status, array $people): void
    {
        $org = $this->organisation->id;
        $preparer = $this->actor('hr_manager');
        $sender = $this->actor('hr_manager');
        $approver = $this->actor('director');
        $id = DB::table('hr_payroll_runs')->insertGetId(['public_id' => (string) Str::uuid(), 'organisation_id' => $org, 'pay_group_id' => $group, 'month' => $month, 'kind' => 'regular',
            'status' => 'draft', 'group_name' => 'g', 'prepared_by_user_id' => $preparer->id, 'prepared_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        foreach ($people as [$worker, $earnings, $employer]) {
            DB::table('hr_payroll_run_people')->insert(['organisation_id' => $org, 'run_id' => $id, 'worker_id' => $worker, 'errors' => '[]', 'warnings' => '[]',
                'totals_encrypted' => Crypt::encryptString(json_encode(['earnings' => $earnings, 'deduction' => 0, 'refund' => 0, 'employee' => 0, 'employer' => $employer, 'mtd' => 0, 'net' => $earnings]))]);
        }
        // The same steps the run flow takes, one at a time, so the database checks accept each.
        if ($status !== 'draft') {
            DB::table('hr_payroll_runs')->where('id', $id)->update(['status' => 'sent', 'sent_by_user_id' => $sender->id, 'sent_at' => now(), 'versions' => '{}']);
        }
        if (in_array($status, ['approved', 'locked'], true)) {
            DB::table('hr_payroll_runs')->where('id', $id)->update(['status' => 'approved', 'approved_by_user_id' => $approver->id, 'approved_at' => now()]);
        }
        if ($status === 'locked') {
            DB::table('hr_payroll_runs')->where('id', $id)->update(['status' => 'locked', 'locked_by_user_id' => $approver->id, 'locked_at' => now()]);
        }
    }
}
