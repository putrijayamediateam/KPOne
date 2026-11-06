<?php

namespace Tests\Feature\Billing;

use App\Domain\Organisation\Models\Branch;
use App\Domain\Visit\Billing\Models\CashClosing;
use App\Domain\Visit\Billing\Models\Payment;
use App\Domain\Visit\Billing\Models\PaymentMethod;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Clinical\ClinicalTestCase;

/**
 * BIL-7 (started 2026-11-05, B.E.R.N.I.S.S for the owner): a read-only month view of the daily cash closings of the
 * active branch, one row per day up to today, with the same status and "changed after approval" rules as the daily page.
 */
class CashClosingMonthTest extends ClinicalTestCase
{
    private CarbonImmutable $today;

    protected function setUp(): void
    {
        parent::setUp();
        $this->today = CarbonImmutable::parse('2026-11-05 10:00', $this->branch->timezone);
        $this->travelTo($this->today);
    }

    public static function viewers(): array
    {
        return [['ca', 200], ['ca_supervisor', 200], ['finance_officer', 200], ['director', 200],
            ['resident_doctor', 403], ['panel_officer', 403], ['administrator', 403], ['technical_admin', 403]];
    }

    #[DataProvider('viewers')]
    public function test_only_reconcilers_open_the_month_view(string $role, int $status): void
    {
        $actor = $this->actor($role);
        $this->selectBranch($actor);
        $this->get(route('cash-closings.month'))->assertStatus($status);
    }

    public function test_each_day_up_to_today_shows_its_latest_count_status_and_flags_and_the_summary_adds_the_counts_shown(): void
    {
        $ca = $this->actor('ca');
        $finance = $this->actor('finance_officer');
        $this->selectBranch($ca);
        $this->countDay('2026-11-01', '0.00', null);
        $this->countDay('2026-11-02', '5.00', 'Synthetic: RM 5.00 over');
        $this->countDay('2026-11-03', '0.00', null);
        $this->countDay('2026-11-05', '0.00', null); // Today: counted, but the day has not ended.
        $this->selectBranch($finance);
        $this->decide('2026-11-01', 'approved');
        $this->decide('2026-11-03', 'returned', 'Synthetic: count again');
        // A Cash receipt recorded on 1 Nov after approval: the approved snapshot no longer matches the live figure.
        $this->cashReceipt($ca, CarbonImmutable::parse('2026-11-01 09:00', $this->branch->timezone), 1250);
        // Another branch's closing never shows.
        $puchong = Branch::query()->where('organisation_id', $this->branch->organisation_id)->where('code', 'PUCHONG')->firstOrFail();
        $other = CashClosing::query()->firstOrFail()->getAttributes();
        DB::table('cash_closings')->insert(['public_id' => (string) Str::uuid(), 'idempotency_key' => (string) Str::uuid(), 'branch_id' => $puchong->id, 'business_date' => '2026-11-04']
            + collect($other)->except(['id'])->all());
        $before = $this->rows();

        $this->selectBranch($ca);
        $this->get(route('cash-closings.month'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Billing/CashClosingMonth')
            ->where('cashClosingMonth.month', '2026-11')->where('cashClosingMonth.today', '2026-11-05')
            ->where('cashClosingMonth.isCurrentMonth', true)->where('cashClosingMonth.previousMonth', '2026-10')->where('cashClosingMonth.nextMonth', null)
            ->where('cashClosingMonth.hasClosings', true)
            ->has('cashClosingMonth.rows', 5)
            ->where('cashClosingMonth.rows.0.date', '2026-11-01')->where('cashClosingMonth.rows.0.status', 'approved')
            ->where('cashClosingMonth.rows.0.decision', 'approved')->where('cashClosingMonth.rows.0.expectedCashSen', 0)
            ->where('cashClosingMonth.rows.0.flags', ['changed_after_approval'])
            ->where('cashClosingMonth.rows.1.status', 'waiting')->where('cashClosingMonth.rows.1.countedCashSen', 500)
            ->where('cashClosingMonth.rows.1.differenceSen', 500)->where('cashClosingMonth.rows.1.decidedBy', null)
            ->where('cashClosingMonth.rows.1.flags', ['not_approved'])
            ->where('cashClosingMonth.rows.2.status', 'returned')->where('cashClosingMonth.rows.2.decision', 'returned')
            ->where('cashClosingMonth.rows.2.flags', ['not_approved'])
            ->where('cashClosingMonth.rows.3.date', '2026-11-04')->where('cashClosingMonth.rows.3.status', 'not_counted')
            ->where('cashClosingMonth.rows.3.countedCashSen', null)->where('cashClosingMonth.rows.3.countedBy', null)
            ->where('cashClosingMonth.rows.3.flags', ['no_count'])
            ->where('cashClosingMonth.rows.4.date', '2026-11-05')->where('cashClosingMonth.rows.4.status', 'open')
            ->where('cashClosingMonth.rows.4.countedCashSen', 0)->where('cashClosingMonth.rows.4.flags', [])
            ->where('cashClosingMonth.summary', ['approved' => 1, 'waiting' => 1, 'returned' => 1, 'notCounted' => 1,
                'countedSen' => 500, 'expectedSen' => 0, 'differenceSen' => 500, 'countsShown' => 4]));

        // Read only: nothing changes by opening the month.
        $this->assertSame($before, $this->rows());
    }

    public function test_an_earlier_month_lists_every_day_and_a_future_or_malformed_month_shows_the_current_month(): void
    {
        $ca = $this->actor('ca');
        $this->selectBranch($ca);
        $this->get(route('cash-closings.month', ['month' => '2026-10']))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('cashClosingMonth.month', '2026-10')->where('cashClosingMonth.isCurrentMonth', false)
            ->where('cashClosingMonth.previousMonth', '2026-09')->where('cashClosingMonth.nextMonth', '2026-11')
            ->where('cashClosingMonth.hasClosings', false)->has('cashClosingMonth.rows', 31)
            ->where('cashClosingMonth.rows.30.date', '2026-10-31')->where('cashClosingMonth.rows.30.status', 'not_counted')
            ->where('cashClosingMonth.summary.notCounted', 31)->where('cashClosingMonth.summary.countsShown', 0));

        foreach (['2026-12', '2026-13', 'november', '1999-01', '2026-1'] as $month) {
            $this->get(route('cash-closings.month', ['month' => $month]))->assertOk()->assertInertia(fn (Assert $page) => $page
                ->where('cashClosingMonth.month', '2026-11')->has('cashClosingMonth.rows', 5));
        }

        // The first day of a month with nothing counted: one open row, no "not counted" days.
        $this->travelTo(CarbonImmutable::parse('2026-12-01 08:00', $this->branch->timezone));
        $this->get(route('cash-closings.month'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('cashClosingMonth.month', '2026-12')->has('cashClosingMonth.rows', 1)
            ->where('cashClosingMonth.rows.0.status', 'open')->where('cashClosingMonth.summary.notCounted', 0));
    }

    private function countDay(string $date, string $counted, ?string $reason): void
    {
        $this->post(route('cash-closings.store'), ['expected_branch_id' => $this->branch->id, 'business_date' => $date, 'counted_cash' => $counted,
            'variance_reason' => $reason, 'idempotency_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
    }

    private function decide(string $date, string $decision, ?string $reason = null): void
    {
        $closing = CashClosing::query()->where('branch_id', $this->branch->id)->whereDate('business_date', $date)->orderByDesc('revision')->firstOrFail();
        $this->post(route('cash-closings.decide', [$closing->public_id, $decision]), ['expected_branch_id' => $this->branch->id, 'business_date' => $date,
            'reason' => $reason, 'idempotency_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
    }

    private function cashReceipt(User $actor, CarbonImmutable $at, int $sen): void
    {
        $method = new PaymentMethod;
        $method->forceFill(['organisation_id' => $actor->organisation_id, 'code' => 'CASH', 'name' => 'Synthetic CASH', 'is_active' => true, 'classification' => 'cash'])->save();
        (new Payment)->forceFill(['public_id' => (string) Str::uuid(), 'organisation_id' => $actor->organisation_id, 'branch_id' => $this->branch->id,
            'patient_id' => $this->patient($this->actor('director'))->id, 'payment_method_id' => $method->id, 'receipt_number' => 'SYN-'.Str::upper(Str::random(12)),
            'amount_sen' => $sen, 'currency' => 'MYR', 'method_snapshot' => $method->name, 'status' => 'posted', 'reference' => null,
            'idempotency_key' => (string) Str::uuid(), 'payload_hash' => hash('sha256', (string) Str::uuid()), 'received_at' => $at->utc(),
            'recorded_by_user_id' => $actor->id, 'lock_version' => 1])->save();
    }

    /** @return array<string, string> */
    private function rows(): array
    {
        return collect(['cash_closings', 'cash_closing_decisions', 'payments', 'audit_logs'])
            ->mapWithKeys(fn (string $table) => [$table => DB::table($table)->orderBy('id')->get()->toJson()])->all();
    }
}
