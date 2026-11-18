<?php

namespace Tests\Feature\HR;

use App\Domain\Access\PermissionCatalogue;
use App\Domain\HR\LocumPay\LocumPayPermissions;
use App\Domain\HR\LocumPay\Services\LocumPayStatement;
use App\Domain\HR\Records\Models\Worker;
use App\Domain\HR\Records\Services\LocumSignIns;
use App\Domain\HR\Records\Services\StaffFileService;
use App\Domain\HR\Roster\Models\RosterShift;
use App\Domain\HR\Roster\Services\RosterService;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Visit\VisitTestCase;

/**
 * HR-15g locum pay statement (owner, 18 Nov 2026). Synthetic data only.
 */
class LocumPayTest extends VisitTestCase
{
    private User $hr;

    private User $director;

    private User $finance;

    private User $locum;

    private Worker $worker;

    protected function setUp(): void
    {
        parent::setUp();
        // October is set up from 1 October; the statements are read and paid on 10 November.
        $this->travelTo(CarbonImmutable::parse('2026-10-01 09:00', $this->branch->timezone));
        $this->hr = $this->actor('hr_manager');
        $this->director = $this->actor('director');
        $this->finance = $this->actor('finance_officer');
        [$this->locum, $this->worker] = $this->linkedLocum();
        // Tue 6 Oct 08:00-14:00 with a 30 min break (5 h 30 min) and Wed 7 Oct 08:00-12:00 (4 h).
        $this->approvedWeek('2026-10-05', [['2026-10-06', '08:00', '14:00', 30], ['2026-10-07', '08:00', '12:00', 0]]);
        $this->travelTo(CarbonImmutable::parse('2026-11-10 09:00', $this->branch->timezone));
    }

    public function test_grants_are_exact_and_only_their_holders_open_the_page(): void
    {
        $roles = PermissionCatalogue::roles();
        $holders = fn (string $p): array => collect($roles)->filter(fn (array $ps) => in_array($p, $ps, true))->keys()->sort()->values()->all();
        $this->assertSame(['locum'], $holders(LocumPayPermissions::VIEW_OWN));
        $this->assertSame(['director', 'hr_manager'], $holders(LocumPayPermissions::MANAGE));
        $this->assertSame(['director', 'finance_officer'], $holders(LocumPayPermissions::FINANCE));
        foreach (array_keys(LocumPayPermissions::grants()) as $permission) {
            $this->assertFalse(PermissionCatalogue::isAdministrativeAuthority($permission));
        }
        foreach (['ca', 'resident_doctor', 'panel_officer', 'queue_display'] as $role) {
            $this->selectBranch($this->actor($role));
            $this->get(route('hr.locum-pay.index'))->assertForbidden();
        }
        $this->selectBranch($this->finance);
        $this->get(route('hr.locum-pay.index'))->assertOk()->assertInertia(fn (Assert $p) => $p->component('HR/LocumPay')
            ->where('locumPay.tabs', ['statements', 'rates'])->where('locumPay.canManage', false)->where('locumPay.canFinance', true));
        $this->post(route('hr.locum-pay.rates.store', $this->worker->public_id), $this->rate('hour', '50'))->assertForbidden();
        $this->selectBranch($this->locum);
        $this->get(route('hr.locum-pay.index'))->assertInertia(fn (Assert $p) => $p->where('locumPay.tabs', ['mine'])
            ->where('locumPay.mine.name', 'Dr Synthetic Locum'));
        $this->get(route('hr.locum-pay.index', ['tab' => 'statements']))->assertInertia(fn (Assert $p) => $p->where('locumPay.tab', 'mine')
            ->where('locumPay.locums', []));
    }

    public function test_hourly_statement_with_a_confirmed_rate_not_worked_correction_and_payment_that_locks_the_month(): void
    {
        $this->selectBranch($this->hr);
        $this->post(route('hr.locum-pay.rates.store', $this->worker->public_id), $this->rate('hour', ''))->assertSessionHasErrors('amount');
        $this->post(route('hr.locum-pay.rates.store', $this->worker->public_id), $this->rate('hour', '50'))->assertSessionHasNoErrors();
        $this->post(route('hr.locum-pay.rates.store', $this->worker->public_id), $this->rate('session', '300'))->assertSessionHasErrors('rate'); // one waiting

        // Not counted until confirmed: the shifts need a rate.
        $this->get(route('hr.locum-pay.index', ['tab' => 'statements', 'month' => '2026-10', 'locum' => $this->worker->public_id]))
            ->assertInertia(fn (Assert $p) => $p->where('locumPay.locums.0.status', 'needs_rate')->where('locumPay.statement.lines.0.noRate', true)
                ->where('locumPay.statement.canMarkPaid', false)->where('locumPay.statement.totalLabel', 'RM —')
                ->where('locumPay.statement.lines.0.amountLabel', 'RM —')->where('locumPay.locums.0.statusLabel', 'Rate missing')->where('locumPay.locums.0.totalLabel', 'RM —'));

        // The HR Manager entered it, so the Director confirms it.
        $rate = DB::table('hr_locum_pay_rates')->sole();
        $this->selectBranch($this->director);
        $this->post(route('hr.locum-pay.rates.confirm', $rate->public_id), $this->lock(1))->assertSessionHasNoErrors();
        $this->assertSame(['confirmed', $this->director->id], [DB::table('hr_locum_pay_rates')->value('status'), (int) DB::table('hr_locum_pay_rates')->value('decided_by_user_id')]);

        // 5 h 30 min × RM 50.00 = RM 275.00, and 4 h × RM 50.00 = RM 200.00.
        $this->selectBranch($this->hr);
        $this->get($this->statementUrl())->assertInertia(fn (Assert $p) => $p->where('locumPay.statement.totalLabel', 'RM 475.00')
            ->where('locumPay.statement.lines.0.basis', '5 h 30 min × RM 50.00 an hour')->where('locumPay.statement.lines.0.amountLabel', 'RM 275.00')
            ->where('locumPay.statement.lines.1.amountLabel', 'RM 200.00')->where('locumPay.statement.problems', [])->where('locumPay.statement.canEdit', true));

        // Wednesday was not worked; a RM 10.00 extra payment is added.
        $wednesday = RosterShift::query()->orderByDesc('starts_at')->first();
        $this->post(route('hr.locum-pay.not-worked.store', $this->worker->public_id), ['shift_id' => $wednesday->public_id, 'reason' => 'did_not_come',
            'month' => '2026-10', 'idempotency_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
        $this->post(route('hr.locum-pay.corrections.store', $this->worker->public_id), ['month' => '2026-10', 'direction' => 'add', 'amount' => '10',
            'reason' => 'extra_payment', 'idempotency_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
        $this->get($this->statementUrl())->assertInertia(fn (Assert $p) => $p->where('locumPay.statement.totalLabel', 'RM 285.00')
            ->where('locumPay.statement.lines.1.notWorked.reason', 'Did not come')->where('locumPay.statement.lines.1.amountLabel', 'RM 0.00')
            ->where('locumPay.statement.corrections.0.amountLabel', 'RM 10.00'));

        // The locum sees their own lines and total, without HR's controls or fingerprint.
        $this->selectBranch($this->locum);
        $this->get(route('hr.locum-pay.index', ['month' => '2026-10']))->assertInertia(fn (Assert $p) => $p
            ->where('locumPay.mine.statement.totalLabel', 'RM 285.00')->where('locumPay.mine.statement.fingerprint', null)
            ->where('locumPay.mine.statement.lines.1.notWorked', ['reason' => 'Did not come'])->where('locumPay.mine.rate.amountLabel', 'RM 50.00 an hour'));

        // Finance records the payment. A stale page is refused; the current month cannot be paid yet.
        $this->selectBranch($this->finance);
        $this->get($this->statementUrl())->assertInertia(fn (Assert $p) => $p->where('locumPay.statement.canMarkPaid', true)
            ->where('locumPay.statement.canEdit', false)->has('locumPay.statement.fingerprint'));
        $fingerprint = LocumPayStatement::build((int) $this->worker->organisation_id, (int) $this->worker->id, '2026-10-01')['fingerprint'];
        $this->post(route('hr.locum-pay.paid', $this->worker->public_id), $this->paid('stale'))->assertSessionHasErrors('locum_pay');
        $this->post(route('hr.locum-pay.paid', $this->worker->public_id), [...$this->paid($fingerprint), 'month' => '2026-11'])->assertSessionHasErrors('locum_pay');
        $this->post(route('hr.locum-pay.paid', $this->worker->public_id), [...$this->paid($fingerprint), 'paid_on' => '2026-11-11'])->assertSessionHasErrors('paid_on');
        $this->post(route('hr.locum-pay.paid', $this->worker->public_id), $this->paid($fingerprint))->assertSessionHasNoErrors();
        $this->assertSame(['5678', '2026-11-05'], [DB::table('hr_locum_pay_months')->value('reference_last4'), substr((string) DB::table('hr_locum_pay_months')->value('paid_on'), 0, 10)]);
        $this->assertStringNotContainsString('TRX-5678', (string) DB::table('hr_locum_pay_months')->value('reference_encrypted'));
        $audit = DB::table('audit_logs')->where('event', 'hr.locum_pay.month_paid')->sole();
        $this->assertStringNotContainsString('285', (string) $audit->metadata);

        // Paid: shown as paid, and the month is locked.
        $this->get($this->statementUrl())->assertInertia(fn (Assert $p) => $p->where('locumPay.statement.paid', true)
            ->where('locumPay.statement.payment.reference', '•••• 5678')->where('locumPay.statement.payment.paidOnLabel', '5 Nov 2026')
            ->where('locumPay.statement.totalLabel', 'RM 285.00')->where('locumPay.locums.0.status', 'paid'));
        $this->selectBranch($this->hr);
        $this->post(route('hr.locum-pay.corrections.store', $this->worker->public_id), ['month' => '2026-10', 'direction' => 'deduct', 'amount' => '5',
            'reason' => 'deduction', 'idempotency_key' => (string) Str::uuid()])->assertSessionHasErrors('locum_pay');
        $exclusion = DB::table('hr_locum_pay_exclusions')->sole();
        $this->post(route('hr.locum-pay.not-worked.remove', $exclusion->public_id), $this->lock(1))->assertSessionHasErrors('locum_pay');
        // A correction for October goes into November instead.
        $this->post(route('hr.locum-pay.corrections.store', $this->worker->public_id), ['month' => '2026-11', 'direction' => 'deduct', 'amount' => '5',
            'reason' => 'earlier_month', 'for_month' => '2026-10', 'idempotency_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
        $this->get(route('hr.locum-pay.index', ['tab' => 'statements', 'month' => '2026-11', 'locum' => $this->worker->public_id]))
            ->assertInertia(fn (Assert $p) => $p->where('locumPay.statement.corrections.0.forMonth', 'October 2026')
                ->where('locumPay.statement.corrections.0.amountLabel', '−RM 5.00'));
        $this->refused('23514', fn () => DB::table('hr_locum_pay_adjustments')->insert(['public_id' => (string) Str::uuid(), 'organisation_id' => $this->worker->organisation_id,
            'worker_id' => $this->worker->id, 'month' => '2026-10-01', 'amount_encrypted' => 'x', 'reason' => 'other', 'entered_by_user_id' => $this->hr->id,
            'entered_at' => now(), 'created_at' => now(), 'updated_at' => now()]));
    }

    public function test_the_entrant_never_confirms_and_a_session_rate_pays_once_per_shift(): void
    {
        $this->selectBranch($this->director);
        $this->post(route('hr.locum-pay.rates.store', $this->worker->public_id), $this->rate('session', '300'))->assertSessionHasNoErrors();
        $rate = DB::table('hr_locum_pay_rates')->sole();
        $this->get(route('hr.locum-pay.index', ['tab' => 'rates']))->assertInertia(fn (Assert $p) => $p
            ->where('locumPay.rates.0.waiting.canDecide', false)->where('locumPay.rates.0.waiting.ownEntry', true));
        $this->post(route('hr.locum-pay.rates.confirm', $rate->public_id), $this->lock(1))->assertSessionHasErrors('locum_pay');
        $this->assertSame('waiting', DB::table('hr_locum_pay_rates')->value('status'));

        $this->selectBranch($this->finance);
        $this->post(route('hr.locum-pay.rates.reject', $rate->public_id), $this->lock(1))->assertSessionHasErrors('reason');
        $this->post(route('hr.locum-pay.rates.confirm', $rate->public_id), $this->lock(2))->assertSessionHasErrors('records'); // stale page
        $this->post(route('hr.locum-pay.rates.confirm', $rate->public_id), $this->lock(1))->assertSessionHasNoErrors();
        $this->get($this->statementUrl())->assertInertia(fn (Assert $p) => $p->where('locumPay.statement.totalLabel', 'RM 600.00')
            ->where('locumPay.statement.lines.0.basis', 'One session at RM 300.00'));

        // A later hourly rate from 7 October, rejected once and then confirmed, prices only the Wednesday.
        $this->selectBranch($this->hr);
        $this->post(route('hr.locum-pay.rates.store', $this->worker->public_id), [...$this->rate('hour', '40'), 'effective_from' => '2026-10-07'])->assertSessionHasNoErrors();
        $later = DB::table('hr_locum_pay_rates')->where('status', 'waiting')->sole();
        $this->selectBranch($this->finance);
        $this->post(route('hr.locum-pay.rates.reject', $later->public_id), [...$this->lock(1), 'reason' => 'wrong_amount'])->assertSessionHasNoErrors();
        $this->selectBranch($this->hr);
        $this->post(route('hr.locum-pay.rates.store', $this->worker->public_id), [...$this->rate('hour', '45'), 'effective_from' => '2026-10-07'])->assertSessionHasNoErrors();
        $this->selectBranch($this->director);
        $again = DB::table('hr_locum_pay_rates')->where('status', 'waiting')->sole();
        $this->post(route('hr.locum-pay.rates.confirm', $again->public_id), $this->lock(1))->assertSessionHasNoErrors();
        // RM 300.00 + 4 h × RM 45.00.
        $this->get($this->statementUrl())->assertInertia(fn (Assert $p) => $p->where('locumPay.statement.totalLabel', 'RM 480.00')
            ->where('locumPay.statement.lines.1.basis', '4 h × RM 45.00 an hour'));
        $this->get(route('hr.locum-pay.index', ['tab' => 'rates']))->assertInertia(fn (Assert $p) => $p
            ->where('locumPay.rates.0.history.1.statusLabel', 'Not confirmed')->where('locumPay.rates.0.history.1.rejectReason', 'Wrong amount'));
    }

    public function test_a_mark_on_a_shift_the_roster_replaced_blocks_payment_until_removed(): void
    {
        $this->selectBranch($this->hr);
        $this->post(route('hr.locum-pay.rates.store', $this->worker->public_id), $this->rate('session', '300'))->assertSessionHasNoErrors();
        $this->selectBranch($this->finance);
        $this->post(route('hr.locum-pay.rates.confirm', DB::table('hr_locum_pay_rates')->value('public_id')), $this->lock(1))->assertSessionHasNoErrors();
        $this->selectBranch($this->hr);
        $wednesday = RosterShift::query()->orderByDesc('starts_at')->first();
        $this->post(route('hr.locum-pay.not-worked.store', $this->worker->public_id), ['shift_id' => $wednesday->public_id, 'reason' => 'did_not_come',
            'idempotency_key' => (string) Str::uuid()])->assertSessionHasNoErrors();

        // HR changes the week and approves it again: every shift is copied to a new row, so the mark no longer matches.
        $this->travelTo(CarbonImmutable::parse('2026-10-02 09:00', $this->branch->timezone));
        $roster = app(RosterService::class);
        $version = $roster->startDraft($this->hr, ['branch_id' => $this->branch->id, 'week' => '2026-10-05', 'mode' => 'change', 'idempotency_key' => (string) Str::uuid()])['version'];
        $roster->submit($this->hr, $version->public_id, ['expected_version_lock' => $version->refresh()->lock_version, 'idempotency_key' => (string) Str::uuid()]);
        $roster->approve($this->director, $version->public_id, ['expected_version_lock' => $version->refresh()->lock_version, 'idempotency_key' => (string) Str::uuid()]);
        $this->travelTo(CarbonImmutable::parse('2026-11-10 09:00', $this->branch->timezone));

        $this->get($this->statementUrl())->assertInertia(fn (Assert $p) => $p->where('locumPay.statement.totalLabel', 'RM 600.00')
            ->where('locumPay.statement.canMarkPaid', false)->has('locumPay.statement.staleMarks', 1)
            ->where('locumPay.statement.staleMarks.0.dateLabel', '7 Oct')->where('locumPay.locums.0.status', 'needs_check')
            ->where('locumPay.statement.problems.0', 'A shift marked not worked on 7 Oct was changed in the roster. Remove the old mark, then mark the shift again if it was still not worked.'));
        $this->selectBranch($this->finance);
        $fingerprint = LocumPayStatement::build((int) $this->worker->organisation_id, (int) $this->worker->id, '2026-10-01')['fingerprint'];
        $this->post(route('hr.locum-pay.paid', $this->worker->public_id), $this->paid($fingerprint))->assertSessionHasErrors('locum_pay');
        $this->assertSame(0, DB::table('hr_locum_pay_months')->count());

        // HR removes the old mark and marks the copied shift; the month can then be paid at RM 300.00.
        $this->selectBranch($this->hr);
        $this->post(route('hr.locum-pay.not-worked.remove', DB::table('hr_locum_pay_exclusions')->value('public_id')), $this->lock(1))->assertSessionHasNoErrors();
        $copied = RosterShift::query()->where('roster_version_id', $version->id)->orderByDesc('starts_at')->first();
        $this->post(route('hr.locum-pay.not-worked.store', $this->worker->public_id), ['shift_id' => $copied->public_id, 'reason' => 'did_not_come',
            'idempotency_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
        $this->selectBranch($this->finance);
        $fingerprint = LocumPayStatement::build((int) $this->worker->organisation_id, (int) $this->worker->id, '2026-10-01')['fingerprint'];
        $this->post(route('hr.locum-pay.paid', $this->worker->public_id), $this->paid($fingerprint))->assertSessionHasNoErrors();
        $this->get($this->statementUrl())->assertInertia(fn (Assert $p) => $p->where('locumPay.statement.totalLabel', 'RM 300.00'));
    }

    public function test_a_refused_rate_or_payment_never_flashes_the_amount_or_reference(): void
    {
        $this->selectBranch($this->hr);
        $this->post(route('hr.locum-pay.rates.store', $this->worker->public_id), [...$this->rate('hour', '55'), 'kind' => 'week'])->assertSessionHasErrors('kind');
        $this->assertArrayNotHasKey('amount', (array) session()->get('_old_input', []));
        $this->selectBranch($this->finance);
        $this->post(route('hr.locum-pay.paid', $this->worker->public_id), $this->paid('stale'))->assertSessionHasErrors('locum_pay');
        $this->assertArrayNotHasKey('reference', (array) session()->get('_old_input', []));
    }

    public function test_postgresql_keeps_the_rules(): void
    {
        $this->selectBranch($this->hr);
        $this->post(route('hr.locum-pay.rates.store', $this->worker->public_id), $this->rate('hour', '50'))->assertSessionHasNoErrors();
        $rate = DB::table('hr_locum_pay_rates')->sole();
        $this->refused('23514', fn () => DB::table('hr_locum_pay_rates')->where('id', $rate->id)->update(['status' => 'confirmed',
            'decided_by_user_id' => $this->hr->id, 'decided_at' => now()])); // never the entrant
        $this->refused('P0001', fn () => DB::table('hr_locum_pay_rates')->where('id', $rate->id)->update(['amount_encrypted' => 'changed']));
        $this->refused('P0001', fn () => DB::table('hr_locum_pay_rates')->delete());
        $this->refused('P0001', fn () => DB::statement('TRUNCATE hr_locum_pay_rates'));
        // A shift of someone else cannot be marked as this locum's.
        $other = $this->actor('ca');
        $this->refused('23514', fn () => DB::table('hr_locum_pay_exclusions')->insert(['public_id' => (string) Str::uuid(), 'organisation_id' => $this->worker->organisation_id,
            'worker_id' => $this->worker->id, 'roster_shift_id' => RosterShift::query()->value('id') + 100000, 'month' => '2026-10-01', 'reason' => 'other',
            'entered_by_user_id' => $other->id, 'entered_at' => now(), 'created_at' => now(), 'updated_at' => now()]), true);
    }

    // ---- fixture ----

    /** @return array{0: User, 1: Worker} */
    private function linkedLocum(): array
    {
        $locum = $this->actor(PermissionCatalogue::LOCUM_ROLE);
        $worker = app(StaffFileService::class)->addLocum($this->hr, ['display_name' => 'Dr Synthetic Locum', 'phone' => '012-345 6789',
            'idempotency_key' => (string) Str::uuid()]);
        app(LocumSignIns::class)->request($this->hr, $worker->public_id, ['email' => $locum->email, 'idempotency_key' => (string) Str::uuid()]);
        $request = DB::table('hr_locum_sign_ins')->where('worker_id', $worker->id)->first();
        app(LocumSignIns::class)->link($this->director, $request->public_id, ['user_id' => $locum->id, 'expected_lock_version' => 1,
            'idempotency_key' => (string) Str::uuid()]);

        return [$locum, $worker->refresh()];
    }

    /** @param list<array{0: string, 1: string, 2: string, 3: int}> $shifts */
    private function approvedWeek(string $week, array $shifts): void
    {
        $roster = app(RosterService::class);
        $version = $roster->startDraft($this->hr, ['branch_id' => $this->branch->id, 'week' => $week, 'mode' => 'blank', 'idempotency_key' => (string) Str::uuid()])['version'];
        foreach ($shifts as [$date, $from, $to, $break]) {
            $roster->addShift($this->hr, $version->public_id, ['staff_user_id' => $this->locum->id, 'date' => $date, 'start_time' => $from, 'end_time' => $to,
                'break_minutes' => $break, 'session_type' => 'morning', 'expected_version_lock' => $version->refresh()->lock_version, 'idempotency_key' => (string) Str::uuid()]);
        }
        $roster->submit($this->hr, $version->public_id, ['expected_version_lock' => $version->refresh()->lock_version, 'idempotency_key' => (string) Str::uuid()]);
        $roster->approve($this->director, $version->public_id, ['expected_version_lock' => $version->refresh()->lock_version, 'idempotency_key' => (string) Str::uuid()]);
    }

    private function statementUrl(): string
    {
        return route('hr.locum-pay.index', ['tab' => 'statements', 'month' => '2026-10', 'locum' => $this->worker->public_id]);
    }

    /** @return array<string, string> */
    private function rate(string $kind, string $amount): array
    {
        return ['kind' => $kind, 'amount' => $amount, 'effective_from' => '2026-10-01', 'idempotency_key' => (string) Str::uuid()];
    }

    /** @return array<string, mixed> */
    private function lock(int $version): array
    {
        return ['expected_lock_version' => $version, 'idempotency_key' => (string) Str::uuid()];
    }

    /** @return array<string, string> */
    private function paid(string $fingerprint): array
    {
        return ['month' => '2026-10', 'paid_on' => '2026-11-05', 'reference' => 'TRX-5678', 'fingerprint' => $fingerprint, 'idempotency_key' => (string) Str::uuid()];
    }

    private function refused(string $state, callable $write, bool $anyIntegrity = false): void
    {
        try {
            DB::transaction(fn () => $write());
            $this->fail("Expected PostgreSQL to refuse with {$state}.");
        } catch (QueryException $e) {
            $code = (string) ($e->errorInfo[0] ?? '');
            $anyIntegrity ? $this->assertStringStartsWith('23', $code, $e->getMessage()) : $this->assertSame($state, $code, $e->getMessage());
        }
    }
}
