<?php

namespace Tests\Feature\HR;

use App\Domain\HR\Attendance\Models\ClockSession;
use App\Domain\HR\Payroll\Services\PayrollMath;
use App\Domain\HR\Payroll\Services\PayrollRunService;
use App\Domain\HR\Payroll\Services\PayrollSetupService;
use App\Domain\HR\Records\Models\Worker;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Visit\VisitTestCase;

/**
 * HR-7b payroll runs (HR-PLAN section 16; B.E.R.N.I.S.S for the owner, 2026-11-06). Every expected figure below is written
 * by hand from synthetic inputs, never computed by the code under test, and no figure is an official statutory rate.
 */
class PayrollRunsTest extends VisitTestCase
{
    private const MONEY_TABLES = ['invoices', 'invoice_lines', 'payments', 'payment_allocations', 'payment_reversals', 'payment_refunds', 'cash_closings', 'panel_claims'];

    private User $hr;

    private User $director;

    private User $director2;

    /** @var array<string, string> */
    private array $types = [];

    private string $group = '';

    /** @var array<string, list<User>> */
    private array $staff = [];

    protected function setUp(): void
    {
        parent::setUp();
        // Staff are assigned from the start of the year, so November clock sessions have a branch assignment.
        $this->travelTo(CarbonImmutable::parse('2026-01-02 09:00', $this->branch->timezone));
        $this->hr = $this->actor('hr_manager');
        $this->director = $this->actor('director');
        $this->director2 = $this->actor('director');
        $this->staff = ['ca' => [$this->actor('ca'), $this->actor('ca')], 'ca_supervisor' => [$this->actor('ca_supervisor')]];
        $this->travelTo(CarbonImmutable::parse('2026-12-03 09:00', $this->branch->timezone));
    }

    public function test_a_monthly_run_works_out_each_line_once_from_approved_records(): void
    {
        [$aina, $ben, $cara] = $this->clinic();
        $money = $this->moneySnapshot();

        // Without the run line types or MTD, the run prepares but names what stops sending.
        $run = $this->prepare($this->hr)->assertRedirect();
        $id = (string) DB::table('hr_payroll_runs')->value('public_id');
        $this->selectBranch($this->hr);
        $this->get(route('hr.payroll.runs.show', $id))->assertOk()->assertInertia(fn (Assert $page) => $page->component('HR/PayrollRun')
            ->where('run.people', fn ($people) => collect($people)->firstWhere('name', $aina->name())['errors'] === ['No pay item type chosen for overtime',
                'No pay item type chosen for unpaid leave', 'No pay item type chosen for staff claim refunds', 'MTD not entered'])
            ->where('run.sendRefusal', 'Fix 4 errors first.'));
        $this->step($this->hr, $id, 'send')->assertSessionHasErrors('payroll');

        $this->lineTypes($this->hr);
        $this->step($this->hr, $id, 'mtd', ['worker_id' => $aina->public_id, 'mtd' => '12.00'])->assertSessionHasNoErrors();
        $runRow = DB::table('hr_payroll_runs')->first();
        $this->assertNotNull($runRow);
        $this->assertSame(['Cheras pay', 28], [$runRow->group_name, (int) $runRow->group_pay_day]);

        $lines = $this->lines($aina);
        $this->assertSame([
            ['basic', 'earnings', 260000, 'Basic salary'],
            ['allowance', 'earnings', 15000, 'Fixed allowance'],
            ['overtime', 'earnings', 6563, 'Overtime · 3 h 30 min × 1.5 (normal, 12 Nov)'],
            ['unpaid_leave', 'deduction', 15000, 'Unpaid leave · 1½ days from 18 Nov'],
            ['claim', 'refund', 4560, 'Staff claim SC-000001 refund'],
            ['employee', 'employee', 28600, 'EPF employee · 11.00% of RM 2,600.00 (up to the next ringgit)'],
            ['employer', 'employer', 33800, 'EPF employer · 13.00% of RM 2,600.00 (up to the next ringgit)'],
            ['employee', 'employee', 1408, 'SOCSO employee · 0.50% of RM 2,815.63 (to the nearest sen)'],
            ['employer', 'employer', 4927, 'SOCSO employer · 1.75% of RM 2,815.63 (to the nearest sen)'],
            ['employee', 'employee', 563, 'EIS employee · 0.20% of RM 2,815.63 (to the nearest sen)'],
            ['employer', 'employer', 563, 'EIS employer · 0.20% of RM 2,815.63 (to the nearest sen)'],
            ['mtd', 'mtd', 1200, 'MTD (PCB) · entered for this run'],
        ], $lines);
        $this->assertSame(['earnings' => 281563, 'deduction' => 15000, 'refund' => 4560, 'employee' => 30571, 'employer' => 39290, 'mtd' => 1200, 'net' => 239352],
            $this->totals($aina));
        // Hourly: 12 h 45 min × RM 9.00, and EIS only.
        $this->assertSame([['hourly', 'earnings', 11475, 'Worked hours · 12 h 45 min × RM 9.00 an hour'],
            ['employee', 'employee', 23, 'EIS employee · 0.20% of RM 114.75 (to the nearest sen)'],
            ['employer', 'employer', 23, 'EIS employer · 0.20% of RM 114.75 (to the nearest sen)']], $this->lines($ben));
        $this->assertSame(11452, $this->totals($ben)['net']);
        // A joiner on 16 November: 15 of 30 days, rounded down.
        $this->assertSame([['basic', 'earnings', 150000, 'Basic salary · 15 of 30 days employed']], $this->lines($cara));
        // Managers and locums are never in a run.
        $this->assertSame(3, DB::table('hr_payroll_run_people')->count());

        // The preparer never approves; another Director does, and the approver locks.
        $this->step($this->hr, $id, 'send')->assertSessionHasNoErrors();
        $this->assertSame('sent', DB::table('hr_payroll_runs')->value('status'));
        $this->step($this->hr, $id, 'mtd', ['worker_id' => $aina->public_id, 'mtd' => '13.00'])->assertSessionHasErrors(['payroll' => 'Only a draft run changes. This one has been sent.']);
        $this->step($this->director, $id, 'return', ['reason' => 'missing_record'])->assertSessionHasNoErrors();
        $this->assertSame('draft', DB::table('hr_payroll_runs')->value('status'));
        $this->step($this->director, $id, 'send')->assertSessionHasNoErrors(); // the Director sends this time
        $this->step($this->director, $id, 'approve')->assertSessionHasErrors(['payroll' => 'Someone else approves this. You prepared or sent it.']);
        $this->step($this->director2, $id, 'approve')->assertSessionHasNoErrors();
        $this->step($this->director, $id, 'lock')->assertSessionHasErrors('payroll');
        $this->step($this->director2, $id, 'lock')->assertSessionHasNoErrors();
        $locked = DB::table('hr_payroll_runs')->first();
        $this->assertNotNull($locked);
        $this->assertSame('locked', $locked->status);
        $this->assertSame((int) $this->director2->id, (int) $locked->locked_by_user_id);
        $this->assertSame([(int) $runRow->id + 0], [(int) $locked->id]);
        $versions = json_decode((string) $locked->versions, true);
        $this->assertCount(3, $versions['profiles']);
        $this->assertSame(1, $versions['rates']);

        // The claim is paid once, through payroll, with HR-6's own payment row.
        $claim = DB::table('hr_staff_claims')->first();
        $this->assertNotNull($claim);
        $this->assertSame('paid', $claim->status);
        $this->assertSame(['payroll'], DB::table('hr_staff_claim_payments')->where('claim_id', $claim->id)->pluck('method')->all());
        // Finance sees it as paid through payroll and cannot reverse it there; the correction is an adjustment run.
        $finance = $this->actor('finance_officer');
        $this->selectBranch($finance);
        $this->get(route('hr.claims.index', ['tab' => 'pay']))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('claims.pay.claims.0.paid.method', 'Payroll')
            ->where('claims.pay.claims.0.refusal', 'Paid through a locked payroll run. Correct it with an adjustment run.')
            ->where('claims.options.methods', fn ($methods) => ! collect($methods)->contains('value', 'payroll')));
        $this->post(route('hr.claims.reverse', $claim->public_id), ['reason' => 'wrong_amount', 'expected_lock_version' => (int) $claim->lock_version,
            'idempotency_key' => (string) Str::uuid()])->assertSessionHasErrors(['claims' => 'Paid through a locked payroll run. Correct it with an adjustment run.']);
        $this->assertSame('paid', DB::table('hr_staff_claims')->where('id', $claim->id)->value('status'));
        $this->assertSame(1, DB::table('hr_staff_claim_payments')->where('claim_id', $claim->id)->count());

        // A locked run and its lines never change; a second regular run for the month is refused.
        $this->assertRefused('P0001', fn () => DB::table('hr_payroll_runs')->where('id', $locked->id)->update(['group_name' => 'Changed']));
        $this->assertRefused('P0001', fn () => DB::table('hr_payroll_lines')->where('run_id', $locked->id)->delete());
        $this->assertRefused('P0001', fn () => DB::table('hr_payroll_run_people')->where('run_id', $locked->id)->update(['errors' => '[]']));
        $this->prepare($this->hr)->assertSessionHasErrors(['month' => 'This pay group already has a run for this month.']);

        // An adjustment holds only difference lines and goes through the same steps.
        $this->prepare($this->hr, 'adjustment')->assertRedirect();
        $adjustment = (string) DB::table('hr_payroll_runs')->where('kind', 'adjustment')->value('public_id');
        $this->step($this->hr, $adjustment, 'manual', ['worker_id' => $aina->public_id, 'item_type_id' => $this->types['Other addition'], 'amount' => '20.00',
            'reason_code' => 'correction'])->assertSessionHasNoErrors();
        $this->assertSame([['manual', 'earnings', 2000, 'Other addition · Correction']], $this->lines($aina, $adjustment));
        $this->prepare($this->hr, 'adjustment')->assertSessionHasErrors(['month' => 'An adjustment for this run is already open.']);

        // Nothing else moves: clinic money is untouched, and no amount reaches audit.
        $this->assertSame($money, $this->moneySnapshot());
        $audit = (string) json_encode(DB::table('audit_logs')->where('event', 'like', 'hr.payroll.%')->pluck('metadata')->all());
        foreach (['260000', '239352', '6563', 'amount', 'rate'] as $value) {
            $this->assertStringNotContainsString($value, $audit);
        }
        $this->assertStringNotContainsString('Basic salary', (string) DB::table('hr_payroll_lines')->value('explanation_encrypted'));
    }

    public function test_a_stale_draft_is_not_sent_and_a_claim_paid_meanwhile_stops_the_lock(): void
    {
        [$aina] = $this->clinic();
        $this->lineTypes($this->hr);
        $this->prepare($this->hr)->assertRedirect();
        $id = (string) DB::table('hr_payroll_runs')->value('public_id');
        $this->step($this->hr, $id, 'mtd', ['worker_id' => $aina->public_id, 'mtd' => '12.00'])->assertSessionHasNoErrors();

        // A new approved overtime after preparing: sending asks to prepare again.
        $session = $this->closedSession($aina->user, '2026-11-20', '18:00', '19:00');
        $this->approvedOvertime($aina->user, $session, 60);
        $this->step($this->hr, $id, 'send')->assertSessionHasErrors(['payroll' => 'Something this run was prepared from has changed. Prepare it again, check it, then send it.']);
        $this->step($this->hr, $id, 'again')->assertSessionHasNoErrors();
        $this->step($this->hr, $id, 'send')->assertSessionHasNoErrors();
        $this->step($this->director, $id, 'approve')->assertSessionHasNoErrors();

        // Finance pays the claim outside payroll after the run was sent: the lock is refused and names the claim.
        $claim = DB::table('hr_staff_claims')->first();
        $this->assertNotNull($claim);
        $finance = $this->actor('finance_officer');
        $this->selectBranch($finance);
        $this->post(route('hr.claims.pay', $claim->public_id), ['method' => 'bank_transfer', 'paid_on' => '2026-12-02', 'reference' => 'SYN-TRF-0001',
            'expected_lock_version' => (int) $claim->lock_version, 'idempotency_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
        $paid = 'Staff claim SC-000001 was paid or changed after this run was sent. Send the run back and prepare it again.';
        $this->step($this->director, $id, 'lock')->assertSessionHasErrors(['payroll' => $paid]);
        $this->assertSame('approved', DB::table('hr_payroll_runs')->value('status'));
        $this->assertSame(1, DB::table('hr_staff_claim_payments')->count());
        $this->selectBranch($this->director);
        $this->get(route('hr.payroll.runs.show', $id))->assertInertia(fn (Assert $page) => $page->where('run.steps.lock.refusal', $paid)
            ->where('run.steps.return.refusal', null));

        // The approved run is not stuck: only its approver sends it back, it is prepared again without the paid claim, then locked.
        $this->step($this->director2, $id, 'return', ['reason' => 'missing_record'])->assertSessionHasErrors(['payroll' => 'The person who approved this run sends it back.']);
        $this->step($this->director, $id, 'return', ['reason' => 'missing_record'])->assertSessionHasNoErrors();
        $run = DB::table('hr_payroll_runs')->first();
        $this->assertNotNull($run);
        $this->assertSame(['draft', null, null], [$run->status, $run->approved_by_user_id, $run->sent_by_user_id]);
        $this->step($this->hr, $id, 'again')->assertSessionHasNoErrors();
        $this->assertSame(0, DB::table('hr_payroll_lines')->where('kind', 'claim')->count());
        $this->step($this->hr, $id, 'send')->assertSessionHasNoErrors();
        $this->step($this->director, $id, 'approve')->assertSessionHasNoErrors();
        $this->step($this->director, $id, 'lock')->assertSessionHasNoErrors();
        $this->assertSame('locked', DB::table('hr_payroll_runs')->value('status'));
        $this->assertSame(['bank_transfer'], DB::table('hr_staff_claim_payments')->pluck('method')->all());
    }

    public function test_the_only_approver_left_never_prepares_so_a_run_can_always_be_approved(): void
    {
        [$aina] = $this->clinic();
        $this->lineTypes($this->hr);
        // With the second Director inactive, the one Director left neither prepares a run nor takes a draft step.
        DB::table('users')->where('id', $this->director2->id)->update(['is_active' => false]);
        $last = 'You are the only person left who can approve this run, so the HR Manager makes this change.';
        $prepare = 'You are the only person left who can approve payroll runs, so the HR Manager prepares them.';
        $this->prepare($this->director)->assertSessionHasErrors(['payroll' => $prepare]);
        $this->assertSame(0, DB::table('hr_payroll_runs')->count());
        $this->selectBranch($this->director);
        $this->get(route('hr.payroll.index', ['tab' => 'runs']))->assertInertia(fn (Assert $page) => $page->where('payroll.runs.prepareRefusal', $prepare));

        $this->prepare($this->hr)->assertRedirect();
        $id = (string) DB::table('hr_payroll_runs')->value('public_id');
        foreach ([['again', []], ['mtd', ['worker_id' => $aina->public_id, 'mtd' => '12.00']], ['send', []],
            ['manual', ['worker_id' => $aina->public_id, 'item_type_id' => $this->types['Other addition'], 'amount' => '5.00', 'reason_code' => 'bonus']]] as [$step, $input]) {
            $this->step($this->director, $id, $step, $input)->assertSessionHasErrors(['payroll' => $last]);
        }
        $this->assertFalse(DB::table('hr_payroll_run_actions')->where('actor_user_id', $this->director->id)->exists());
        $this->selectBranch($this->director);
        $this->get(route('hr.payroll.runs.show', $id))->assertInertia(fn (Assert $page) => $page->where('run.draftRefusal', $last));
        $this->selectBranch($this->hr);
        $this->get(route('hr.payroll.runs.show', $id))->assertInertia(fn (Assert $page) => $page->where('run.draftRefusal', null));

        // So the HR Manager finishes the draft and the one Director can still approve and lock it.
        $this->step($this->hr, $id, 'mtd', ['worker_id' => $aina->public_id, 'mtd' => '12.00'])->assertSessionHasNoErrors();
        $this->step($this->hr, $id, 'send')->assertSessionHasNoErrors();
        $this->step($this->director, $id, 'approve')->assertSessionHasNoErrors();
        $this->step($this->director, $id, 'lock')->assertSessionHasNoErrors();
        $this->assertSame('locked', DB::table('hr_payroll_runs')->value('status'));
    }

    public function test_anyone_who_prepared_never_approves_and_a_recovery_adjustment_can_be_sent(): void
    {
        [$aina] = $this->clinic();
        $this->lineTypes($this->hr);
        $this->prepare($this->hr)->assertRedirect();
        $id = (string) DB::table('hr_payroll_runs')->value('public_id');
        // The Director types a bonus; the HR Manager enters the MTD last and sends. The Director still never approves it.
        $this->step($this->director, $id, 'manual', ['worker_id' => $aina->public_id, 'item_type_id' => $this->types['Other addition'], 'amount' => '500.00',
            'reason_code' => 'bonus'])->assertSessionHasNoErrors();
        $this->step($this->hr, $id, 'mtd', ['worker_id' => $aina->public_id, 'mtd' => '12.00'])->assertSessionHasNoErrors();
        $this->assertSame((int) $this->hr->id, (int) DB::table('hr_payroll_runs')->value('prepared_by_user_id'));
        $this->step($this->hr, $id, 'send')->assertSessionHasNoErrors();
        $this->step($this->director, $id, 'approve')->assertSessionHasErrors(['payroll' => 'Someone else approves this. You prepared or sent it.']);
        $run = DB::table('hr_payroll_runs')->first();
        $this->assertNotNull($run);
        $this->assertRefused('23514', fn () => DB::table('hr_payroll_run_actions')->insert(['organisation_id' => $run->organisation_id, 'run_id' => $run->id,
            'action' => 'approved', 'actor_user_id' => $this->director->id, 'created_at' => now()]));
        $this->step($this->director2, $id, 'approve')->assertSessionHasNoErrors();
        $this->step($this->director2, $id, 'lock')->assertSessionHasNoErrors();

        // A correction that takes money back is negative by nature: a warning, never a block.
        $this->prepare($this->hr, 'adjustment')->assertRedirect();
        $adjustment = (string) DB::table('hr_payroll_runs')->where('kind', 'adjustment')->value('public_id');
        $this->step($this->hr, $adjustment, 'manual', ['worker_id' => $aina->public_id, 'item_type_id' => $this->types['Unpaid leave'], 'amount' => '45.60',
            'reason_code' => 'recovery'])->assertSessionHasNoErrors();
        $person = DB::table('hr_payroll_run_people')->where('run_id', DB::table('hr_payroll_runs')->where('public_id', $adjustment)->value('id'))->first();
        $this->assertNotNull($person);
        $this->assertSame([], json_decode((string) $person->errors, true));
        $this->assertContains('net_negative_adjustment', json_decode((string) $person->warnings, true));
        $this->step($this->hr, $adjustment, 'send')->assertSessionHasNoErrors();
        // A manual line amount is never flashed back into the session.
        $this->step($this->hr, $adjustment, 'manual', ['worker_id' => (string) Str::uuid(), 'item_type_id' => $this->types['Other addition'], 'amount' => '77.00',
            'reason_code' => 'bonus'])->assertSessionMissing('_old_input.amount');
    }

    public function test_postgresql_refuses_what_the_service_refuses(): void
    {
        [$aina] = $this->clinic();
        $this->lineTypes($this->hr);
        $this->prepare($this->hr)->assertRedirect();
        $run = DB::table('hr_payroll_runs')->first();
        $this->assertNotNull($run);
        // The approver is never the preparer, and only the approver locks.
        $this->assertRefused('23514', fn () => DB::table('hr_payroll_runs')->where('id', $run->id)->update(['status' => 'sent', 'sent_by_user_id' => $this->hr->id,
            'sent_at' => now(), 'versions' => '{}', 'approved_by_user_id' => $this->hr->id, 'approved_at' => now()]));
        $this->assertRefused('P0001', fn () => DB::table('hr_payroll_runs')->where('id', $run->id)->update(['status' => 'approved', 'approved_by_user_id' => $this->director->id,
            'approved_at' => now()]));
        $this->assertRefused('23514', fn () => DB::table('hr_payroll_run_actions')->insert(['organisation_id' => $run->organisation_id, 'run_id' => $run->id,
            'action' => 'approved', 'actor_user_id' => $this->hr->id, 'created_at' => now()]));
        $this->assertRefused('P0001', fn () => DB::table('hr_payroll_runs')->where('id', $run->id)->update(['month' => '2026-10-01']));
        $this->assertRefused('P0001', fn () => DB::table('hr_payroll_runs')->delete());
        // Managers and locums are never in a run.
        $manager = Worker::query()->where('user_id', $this->hr->id)->first() ?? $this->workerFor($this->director, $this->hr);
        $this->assertRefused('23514', fn () => DB::table('hr_payroll_run_people')->insert(['organisation_id' => $run->organisation_id, 'run_id' => $run->id,
            'worker_id' => $manager->id, 'errors' => '[]', 'warnings' => '[]']));
        // An adjustment corrects only a locked regular run.
        $this->assertRefused('23514', fn () => DB::table('hr_payroll_runs')->insert(['public_id' => (string) Str::uuid(), 'organisation_id' => $run->organisation_id,
            'pay_group_id' => $run->pay_group_id, 'month' => $run->month, 'kind' => 'adjustment', 'adjusts_run_id' => $run->id, 'status' => 'draft',
            'group_name' => 'x', 'prepared_by_user_id' => $this->hr->id, 'prepared_at' => now(), 'created_at' => now(), 'updated_at' => now()]));
        // Payroll is now an HR-6 payment method, and only that changed.
        $claim = DB::table('hr_staff_claims')->first();
        $this->assertNotNull($claim);
        $this->assertRefused('23514', fn () => DB::table('hr_staff_claim_payments')->insert(['public_id' => (string) Str::uuid(), 'organisation_id' => $claim->organisation_id,
            'claim_id' => $claim->id, 'kind' => 'payment', 'method' => 'crypto', 'paid_on' => '2026-12-01', 'recorded_by_user_id' => $this->director->id, 'created_at' => now()]));
        $this->assertSame(1, DB::table('hr_payroll_runs')->count());
        unset($aina);
    }

    public function test_only_payroll_holders_open_runs_and_the_payroll_code_has_no_float_on_money(): void
    {
        $this->clinic();
        $this->prepare($this->hr)->assertRedirect();
        $id = (string) DB::table('hr_payroll_runs')->value('public_id');
        foreach (['ca', 'finance_officer', 'queue_display'] as $role) {
            $this->selectBranch($this->actor($role));
            $this->get(route('hr.payroll.runs.show', $id))->assertForbidden();
            $this->get(route('hr.payroll.index', ['tab' => 'runs']))->assertForbidden();
            $this->post(route('hr.payroll.runs.step', [$id, 'send']), ['expected_lock_version' => 1, 'idempotency_key' => (string) Str::uuid()])->assertForbidden();
        }
        $this->selectBranch($this->hr);
        $this->get(route('hr.payroll.index', ['tab' => 'runs']))->assertInertia(fn (Assert $page) => $page->where('payroll.runs.runs.0.status', 'draft')
            ->where('payroll.runs.runs.0.people', 3)->missing('payroll.runs.runs.0.dbId'));
        $this->step($this->hr, $id, 'mtd', ['worker_id' => (string) Str::uuid(), 'mtd' => '1.00'])->assertNotFound();

        // One rounding per line, and a figure too large for 64 bits is refused rather than wrapped.
        $this->assertSame(8, PayrollMath::fraction([10, 3], 4));
        $this->assertSame(7, PayrollMath::fraction([10, 3], 4, PayrollMath::DOWN));
        $this->assertThrows(fn () => PayrollMath::fraction([PHP_INT_MAX - 2, 4], PHP_INT_MAX - 1), \RuntimeException::class);

        foreach (glob(base_path('app/Domain/HR/Payroll/Services/*.php')) ?: [] as $file) {
            $source = (string) file_get_contents($file);
            $this->assertDoesNotMatchRegularExpression('/\(float\)|floatval\(|\bround\(|number_format\(|\bfloor\(|\bceil\(/', $source, basename($file));
        }
    }

    /** @return array{0: Worker, 1: Worker, 2: Worker} the monthly worker, the hourly part-timer and the joiner */
    private function clinic(): array
    {
        $setup = app(PayrollSetupService::class);
        $k = fn (): string => (string) Str::uuid();
        $this->group = $setup->saveGroup($this->hr, ['name' => 'Cheras pay', 'pay_day' => '28', 'is_active' => true, 'idempotency_key' => $k()]);
        foreach ([['Fixed allowance', 'earnings', ['counts_epf' => true, 'counts_socso' => true, 'counts_eis' => true]], ['Overtime', 'earnings', ['counts_socso' => true, 'counts_eis' => true]],
            ['Unpaid leave', 'deduction', ['counts_epf' => true]], ['Staff claim refund', 'refund', []], ['Other addition', 'earnings', []]] as [$name, $kind, $counts]) {
            $this->types[$name] = $setup->saveItemType($this->hr, ['name' => $name, 'kind' => $kind, ...$counts, 'is_active' => true, 'idempotency_key' => $k()]);
        }
        $setup->saveRates($this->hr, ['daily_divisor' => '26', 'hourly_divisor' => '8', 'expected_version' => 0, 'idempotency_key' => $k()]);
        $note = 'Synthetic test figures, not an official table';
        foreach ([
            ['epf', 'Malaysian below 60', 'up_ringgit', [['0', '5,000.00', 'rate', '11', 'rate', '13'], ['5,000.01', '', 'rate', '11', 'rate', '12']]],
            ['socso', 'Category 1', 'nearest_sen', [['0', '6,000.00', 'rate', '0.5', 'rate', '1.75'], ['6,000.01', '', 'fixed', '29.75', 'fixed', '104.15']]],
            ['eis', 'All staff', 'nearest_sen', [['0', '6,000.00', 'rate', '0.2', 'rate', '0.2'], ['6,000.01', '', 'fixed', '11.90', 'fixed', '11.90']]],
        ] as [$scheme, $category, $rounding, $bands]) {
            $table = $setup->enterTable($this->hr, ['scheme' => $scheme, 'category' => $category, 'effective_from' => '2026-01-01', 'rounding' => $rounding, 'source_note' => $note,
                'rows' => array_map(fn (array $b): array => ['from' => $b[0], 'to' => $b[1], 'employee_kind' => $b[2], 'employee' => $b[3], 'employer_kind' => $b[4], 'employer' => $b[5]], $bands),
                'idempotency_key' => $k()]);
            $setup->decideTable($this->director, $table, 'check', ['expected_lock_version' => 1, 'idempotency_key' => $k()]);
        }

        $aina = $this->workerFor($this->director, $this->staff['ca'][0]);
        $ben = $this->workerFor($this->director, $this->staff['ca'][1]);
        $cara = $this->workerFor($this->director, $this->staff['ca_supervisor'][0]);
        $this->period($aina, '2026-01-01');
        $this->period($ben, '2026-01-01', 'part_time');
        $this->period($cara, '2026-11-16');
        $profile = ['pay_group_id' => $this->group, 'effective_from' => '2026-01-01', 'epf' => false, 'socso' => false, 'eis' => false, 'mtd' => false, 'hrd' => false, 'items' => []];
        $confirm = fn (string $id) => $setup->decideProfile($this->director, $id, 'confirm', ['expected_lock_version' => 1, 'idempotency_key' => $k()]);
        $confirm($setup->enterProfile($this->hr, [...$profile, 'worker_id' => $aina->public_id, 'pay_type' => 'monthly', 'rate' => '2,600.00', 'epf' => true,
            'epf_category' => 'Malaysian below 60', 'socso' => true, 'socso_category' => 'Category 1', 'eis' => true, 'mtd' => true,
            'items' => [['item_type_id' => $this->types['Fixed allowance'], 'amount' => '150.00']], 'idempotency_key' => $k()]));
        $confirm($setup->enterProfile($this->hr, [...$profile, 'worker_id' => $ben->public_id, 'pay_type' => 'hourly', 'rate' => '9.00', 'eis' => true, 'idempotency_key' => $k()]));
        $confirm($setup->enterProfile($this->hr, [...$profile, 'worker_id' => $cara->public_id, 'pay_type' => 'monthly', 'rate' => '3,000.00', 'idempotency_key' => $k()]));
        // A locum never joins a run.
        $this->selectBranch($this->director);
        $this->post(route('hr.records.locums.store'), ['display_name' => 'Synthetic Locum', 'phone' => '+60123456780', 'idempotency_key' => $k()])->assertSessionHasNoErrors();

        // Aina: 3 h 30 min overtime on a normal day at 1.5, 1½ days of unpaid leave, an approved RM 45.60 claim.
        $session = $this->closedSession($aina->user, '2026-11-12', '18:00', '21:30');
        $this->approvedOvertime($aina->user, $session, 210);
        $this->unpaidLeave($aina->user, '2026-11-18', '2026-11-19', 3);
        $this->approvedClaim($aina->user, 4560);
        // Ben: 8 h 30 min and 4 h 15 min worked.
        $this->closedSession($ben->user, '2026-11-10', '09:00', '17:30');
        $this->closedSession($ben->user, '2026-11-24', '09:00', '13:15');

        return [$aina->refresh()->load('user'), $ben->refresh()->load('user'), $cara->refresh()->load('user')];
    }

    private function lineTypes(User $actor): void
    {
        app(PayrollRunService::class)->saveLineTypes($actor, ['overtime_type_id' => $this->types['Overtime'], 'unpaid_leave_type_id' => $this->types['Unpaid leave'],
            'claim_type_id' => $this->types['Staff claim refund'], 'expected_version' => 0, 'idempotency_key' => (string) Str::uuid()]);
    }

    private function prepare(User $actor, string $kind = 'regular'): TestResponse
    {
        $this->selectBranch($actor);

        return $this->post(route('hr.payroll.runs.prepare'), ['pay_group_id' => $this->group, 'month' => '2026-11', 'kind' => $kind, 'idempotency_key' => (string) Str::uuid()]);
    }

    /** @param array<string, mixed> $input */
    private function step(User $actor, string $run, string $step, array $input = []): TestResponse
    {
        $this->selectBranch($actor);

        return $this->post(route('hr.payroll.runs.step', [$run, $step]), [...$input,
            'expected_lock_version' => (int) (DB::table('hr_payroll_runs')->where('public_id', $run)->value('lock_version') ?? 1), 'idempotency_key' => (string) Str::uuid()]);
    }

    /** @return list<array{0:string, 1:string, 2:int, 3:string}> */
    private function lines(Worker $worker, ?string $runId = null): array
    {
        $run = $runId === null ? DB::table('hr_payroll_runs')->where('kind', 'regular')->value('id') : DB::table('hr_payroll_runs')->where('public_id', $runId)->value('id');
        $person = DB::table('hr_payroll_run_people')->where('run_id', $run)->where('worker_id', $worker->id)->value('id');

        return DB::table('hr_payroll_lines')->where('run_person_id', $person)->orderBy('position')->get()
            ->map(fn ($l) => [(string) $l->kind, (string) $l->section, (int) Crypt::decryptString((string) $l->amount_encrypted), Crypt::decryptString((string) $l->explanation_encrypted)])
            ->all();
    }

    /** @return array<string, int> */
    private function totals(Worker $worker): array
    {
        $run = DB::table('hr_payroll_runs')->where('kind', 'regular')->value('id');
        $encrypted = DB::table('hr_payroll_run_people')->where('run_id', $run)->where('worker_id', $worker->id)->value('totals_encrypted');

        return json_decode(Crypt::decryptString((string) $encrypted), true);
    }

    private function workerFor(User $manager, User $subject): Worker
    {
        $this->selectBranch($manager);
        $this->post(route('hr.records.files.store'), ['user_id' => $subject->id, 'idempotency_key' => (string) Str::uuid()])->assertSessionHasNoErrors();

        return Worker::query()->with('user')->where('user_id', $subject->id)->firstOrFail();
    }

    private function period(Worker $worker, string $from, string $type = 'permanent'): void
    {
        DB::table('hr_employment_periods')->insert(['public_id' => (string) Str::uuid(), 'organisation_id' => $worker->organisation_id, 'worker_id' => $worker->id,
            'type' => $type, 'starts_on' => $from, 'created_by_user_id' => $this->director->id, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function closedSession(User $person, string $date, string $in, string $out): ClockSession
    {
        $zone = $this->branch->timezone;
        $session = new ClockSession;
        $session->forceFill(['public_id' => (string) Str::uuid(), 'organisation_id' => $person->organisation_id, 'user_id' => $person->id, 'branch_id' => $this->branch->id,
            'local_date' => $date, 'timezone' => $zone, 'clock_in_at' => CarbonImmutable::parse("{$date} {$in}", $zone)->utc(),
            'clock_out_at' => CarbonImmutable::parse("{$date} {$out}", $zone)->utc(), 'status' => 'closed'])->save();

        return $session->refresh();
    }

    private function approvedOvertime(User $person, ClockSession $session, int $minutes): void
    {
        $setting = DB::table('hr_overtime_settings')->where('organisation_id', $person->organisation_id)->value('id') ?? DB::table('hr_overtime_settings')->insertGetId([
            'organisation_id' => $person->organisation_id, 'effective_from' => '2026-01-01', 'normal_multiplier' => '1.50', 'rest_day_multiplier' => '2.00',
            'public_holiday_multiplier' => '3.00', 'nearing_hours' => 40, 'limit_hours' => 45, 'created_by_user_id' => $this->hr->id, 'created_at' => now()]);
        $id = DB::table('hr_overtime_requests')->insertGetId(['public_id' => (string) Str::uuid(), 'organisation_id' => $person->organisation_id, 'user_id' => $person->id,
            'requested_by_user_id' => $person->id, 'clock_session_id' => $session->id, 'branch_id' => $session->branch_id, 'local_date' => $session->local_date->toDateString(),
            'minutes_requested' => $minutes, 'reason_code' => 'patient_load', 'day_type' => 'normal', 'status' => 'pending', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('hr_overtime_requests')->where('id', $id)->update(['status' => 'approved', 'minutes_approved' => $minutes, 'overtime_setting_id' => $setting,
            'multiplier' => '1.50', 'worked_minutes_at_decision' => $minutes, 'decided_by_user_id' => $this->hr->id, 'decided_at' => now()]);
    }

    private function unpaidLeave(User $person, string $from, string $to, int $halves): void
    {
        $type = DB::table('hr_leave_types')->insertGetId(['organisation_id' => $person->organisation_id, 'code' => 'UNPAID', 'name' => 'Unpaid leave', 'is_paid' => false,
            'uses_balance' => false, 'allow_half_days' => true, 'evidence_needed' => false, 'updated_by_user_id' => $this->hr->id, 'created_at' => now(), 'updated_at' => now()]);
        $id = DB::table('hr_leave_requests')->insertGetId(['public_id' => (string) Str::uuid(), 'organisation_id' => $person->organisation_id, 'user_id' => $person->id,
            'leave_type_id' => $type, 'branch_id' => $this->branch->id, 'start_date' => $from, 'end_date' => $to, 'start_part' => 'full', 'end_part' => 'am',
            'slot_start' => "{$from} 00:00:00", 'slot_end' => "{$to} 12:00:00", 'year' => 2026, 'halves' => $halves, 'status' => 'pending', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('hr_leave_requests')->where('id', $id)->update(['status' => 'approved', 'decided_by_user_id' => $this->hr->id, 'decided_at' => now()]);
    }

    private function approvedClaim(User $person, int $sen): void
    {
        $category = DB::table('hr_claim_categories')->insertGetId(['public_id' => (string) Str::uuid(), 'organisation_id' => $person->organisation_id, 'current_version' => 1,
            'created_by_user_id' => $this->hr->id, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('hr_claim_category_versions')->insert(['organisation_id' => $person->organisation_id, 'category_id' => $category, 'version' => 1, 'name' => 'Meals',
            'needs_receipt' => false, 'is_active' => true, 'set_by_user_id' => $this->hr->id, 'created_at' => now()]);
        $id = DB::table('hr_staff_claims')->insertGetId(['public_id' => (string) Str::uuid(), 'organisation_id' => $person->organisation_id, 'number' => 'SC-000001',
            'claimant_user_id' => $person->id, 'branch_id' => $this->branch->id, 'category_id' => $category, 'category_version' => 1, 'spent_on' => '2026-11-20',
            'amount_sen' => $sen, 'purpose_encrypted' => Crypt::encryptString('Synthetic team lunch'), 'status' => 'waiting', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('hr_staff_claims')->where('id', $id)->update(['status' => 'approved', 'decided_by_user_id' => $this->hr->id, 'decided_at' => now()]);
    }

    /** @return array<string, string> */
    private function moneySnapshot(): array
    {
        $out = [];
        foreach (self::MONEY_TABLES as $table) {
            $out[$table] = md5((string) json_encode(DB::table($table)->orderBy('id')->get()));
        }

        return $out;
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
