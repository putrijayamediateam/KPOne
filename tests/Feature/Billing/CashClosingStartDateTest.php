<?php

namespace Tests\Feature\Billing;

use App\Domain\Organisation\Models\Branch;
use App\Domain\Visit\Billing\Models\CashClosingStartDate;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Role;
use Tests\Feature\Clinical\ClinicalTestCase;

/**
 * BIL-15 (owner decisions 2026-11-07): a branch's cash closing start date. A Finance Officer or Director sets or
 * changes it on the month page; the month view then counts uncounted ended days from that date as Not counted.
 * Until one is set, the first count is the start (as BIL-7). Append-only revisions.
 */
class CashClosingStartDateTest extends ClinicalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-11-05 10:00', $this->branch->timezone));
    }

    public static function setters(): array
    {
        return [['finance_officer', 302], ['director', 302], ['ca_supervisor', 403], ['ca', 403], ['administrator', 403]];
    }

    #[DataProvider('setters')]
    public function test_only_finance_officers_and_directors_set_the_start_date(string $role, int $status): void
    {
        $actor = $this->actor($role);
        $this->selectBranch($actor);
        $this->post(route('cash-closings.start-date'), $this->input('2026-11-02'))->assertStatus($status);
        $this->assertSame($status === 302 ? 1 : 0, CashClosingStartDate::query()->count());
    }

    public function test_the_permission_belongs_to_exactly_finance_officer_and_director(): void
    {
        $holders = Role::query()->whereHas('permissions', fn ($q) => $q->where('name', 'payments.cash_closing.start_date.manage.branch'))->orderBy('name')->pluck('name')->all();
        $this->assertSame(['director', 'finance_officer'], $holders);
    }

    public function test_the_month_view_counts_missed_days_from_the_set_date_and_from_the_first_count_until_one_is_set(): void
    {
        $ca = $this->actor('ca');
        $this->selectBranch($ca);
        $this->post(route('cash-closings.store'), ['expected_branch_id' => $this->branch->id, 'business_date' => '2026-11-04', 'counted_cash' => '0.00',
            'variance_reason' => null, 'idempotency_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
        // Not set: days before the first count (4 Nov) are not in use.
        $this->get(route('cash-closings.month'))->assertInertia(fn (Assert $page) => $page
            ->where('cashClosingMonth.startsOn', '2026-11-04')->where('cashClosingMonth.startDate.startsOn', null)->where('cashClosingMonth.startDate.canSet', false)
            ->where('cashClosingMonth.rows.1.status', 'before_start')->where('cashClosingMonth.rows.2.status', 'before_start'));

        $finance = $this->actor('finance_officer');
        $this->selectBranch($finance);
        $this->post(route('cash-closings.start-date'), [...$this->input('2026-11-02'), 'month' => '2026-11'])
            ->assertRedirect(route('cash-closings.month', ['month' => '2026-11']))->assertSessionHasNoErrors();
        $this->get(route('cash-closings.month'))->assertInertia(fn (Assert $page) => $page
            ->where('cashClosingMonth.startsOn', '2026-11-02')->where('cashClosingMonth.startDate.revision', 1)
            ->where('cashClosingMonth.startDate.canSet', true)->where('cashClosingMonth.startDate.setBy', $finance->name)
            ->where('cashClosingMonth.rows.0.status', 'before_start')
            ->where('cashClosingMonth.rows.1.status', 'not_counted')->where('cashClosingMonth.rows.2.status', 'not_counted')
            ->where('cashClosingMonth.rows.3.status', 'waiting')->where('cashClosingMonth.summary.notCounted', 2));
        $this->assertSame(['revision' => 1, 'starts_on' => '2026-11-02', 'previous_starts_on' => null],
            json_decode((string) DB::table('audit_logs')->where('event', 'billing.cash_closing_start_date_set')->sole()->metadata, true));

        // A later date: earlier uncounted days are no longer missed. The previous date is kept as revision 1.
        $director = $this->actor('director');
        $this->selectBranch($director);
        $this->post(route('cash-closings.start-date'), [...$this->input('2026-11-03'), 'expected_revision' => 1])->assertSessionHasNoErrors();
        $this->get(route('cash-closings.month'))->assertInertia(fn (Assert $page) => $page
            ->where('cashClosingMonth.rows.1.status', 'before_start')->where('cashClosingMonth.rows.2.status', 'not_counted'));
        $this->assertSame(['2026-11-02', '2026-11-03'], CashClosingStartDate::query()->orderBy('revision')->get()->map(fn ($r) => $r->starts_on->toDateString())->all());

        // A start date in the future marks nothing as missed this month, but never hides the count already made:
        // the 4 Nov count stays on the page, waiting (BIL-15 review).
        $this->post(route('cash-closings.start-date'), [...$this->input('2026-12-01'), 'expected_revision' => 2])->assertSessionHasNoErrors();
        $this->get(route('cash-closings.month'))->assertInertia(fn (Assert $page) => $page
            ->where('cashClosingMonth.beforeStart', false)->where('cashClosingMonth.hasClosings', true)->where('cashClosingMonth.summary.notCounted', 0)
            ->where('cashClosingMonth.rows.3.status', 'waiting')->where('cashClosingMonth.rows.2.status', 'before_start'));
        // A month with no counts before the start date reads "starts" and shows nothing to count.
        $this->get(route('cash-closings.month', ['month' => '2026-10']))->assertInertia(fn (Assert $page) => $page
            ->where('cashClosingMonth.beforeStart', true)->where('cashClosingMonth.hasClosings', false)->where('cashClosingMonth.summary.notCounted', 0));
    }

    public function test_bad_dates_stale_revisions_retries_and_other_branches_are_handled(): void
    {
        $finance = $this->actor('finance_officer');
        $this->selectBranch($finance);
        foreach (['', '2026-02-30', '1999-12-31', '2027-11-06', 'yesterday'] as $bad) {
            $this->post(route('cash-closings.start-date'), $this->input($bad))->assertSessionHasErrors('starts_on');
        }
        $input = $this->input('2026-11-01');
        $this->post(route('cash-closings.start-date'), $input)->assertSessionHasNoErrors();
        $this->post(route('cash-closings.start-date'), $input)->assertSessionHasNoErrors(); // Same-key retry.
        $this->post(route('cash-closings.start-date'), [...$input, 'starts_on' => '2026-11-03'])->assertSessionHasErrors('starts_on'); // Changed payload.
        $this->post(route('cash-closings.start-date'), $this->input('2026-11-03'))->assertSessionHasErrors('starts_on'); // Stale revision 0.
        $this->post(route('cash-closings.start-date'), [...$this->input('2026-11-01'), 'expected_revision' => 1])->assertSessionHasErrors('starts_on'); // Same date.
        $this->assertSame(1, CashClosingStartDate::query()->count());
        // A branch other than the active one is refused and saves nothing.
        $puchong = Branch::query()->where('organisation_id', $this->branch->organisation_id)->where('code', 'PUCHONG')->firstOrFail();
        $this->post(route('cash-closings.start-date'), [...$this->input('2026-11-01'), 'expected_branch_id' => $puchong->id])->assertSessionHasErrors();
        $this->assertSame(1, CashClosingStartDate::query()->count());

        $row = CashClosingStartDate::query()->sole();
        $this->assertThrows(fn () => $row->forceFill(['starts_on' => '2026-10-01'])->save(), LogicException::class);
        foreach ([fn () => DB::table('cash_closing_start_dates')->where('id', $row->id)->update(['starts_on' => '2026-10-01']),
            fn () => DB::table('cash_closing_start_dates')->delete(), fn () => DB::statement('TRUNCATE cash_closing_start_dates')] as $statement) {
            try {
                DB::transaction(fn () => $statement());
                $this->fail('PostgreSQL accepted a change to a start date.');
            } catch (QueryException $e) {
                $this->assertSame('P0001', $e->errorInfo[0] ?? null);
            }
        }
        try {
            DB::transaction(fn () => DB::table('cash_closing_start_dates')->insert(collect($row->getAttributes())->except(['id'])->merge(['revision' => 3, 'idempotency_key' => (string) Str::uuid()])->all()));
            $this->fail('PostgreSQL accepted a skipped revision.');
        } catch (QueryException $e) {
            $this->assertSame('23514', $e->errorInfo[0] ?? null);
        }
    }

    /** @return array<string, mixed> */
    private function input(string $date): array
    {
        return ['expected_branch_id' => $this->branch->id, 'starts_on' => $date, 'expected_revision' => 0, 'idempotency_key' => (string) Str::uuid()];
    }
}
