<?php

namespace Tests\Feature\Billing;

use App\Domain\Clinical\Services\CompleteConsultationService;
use App\Domain\Shared\Services\ClinicInsightsReportService;
use App\Domain\Shared\Services\PanelSummaryReportService;
use App\Domain\Visit\Billing\Models\CoverageAllocation;
use App\Domain\Visit\Billing\Models\Invoice;
use App\Domain\Visit\Billing\Models\PaymentMethod;
use App\Domain\Visit\Billing\Overview\DirectorOverviewService;
use App\Domain\Visit\Billing\Services\BillingBuilderService;
use App\Domain\Visit\Billing\Services\SalesTargetService;
use App\Domain\Visit\Models\Panel;
use App\Domain\Visit\Models\Visit;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * BIL-31: the Director and Finance overview (owner item 32, P8, answers all A). Every figure must equal the code behind
 * the page it links to; waiting counts follow the rules of design note 2.3; only Director and Finance Officer see it.
 * All data is synthetic.
 */
class DirectorOverviewTest extends BillingTestCase
{
    public function test_figures_equal_insights_targets_and_the_panel_summary_and_waiting_counts_follow_the_rules(): void
    {
        $saleDay = CarbonImmutable::now($this->branch->timezone)->startOfMonth()->addMonthsNoOverflow(2)->setDay(12)->setTime(10, 0);
        $this->travelTo($saleDay);
        [, $ca, $visit, $invoice] = $this->finalizedFixture(4000);
        (new PaymentMethod)->forceFill(['organisation_id' => $visit->organisation_id, 'code' => 'cash', 'name' => 'Synthetic cash', 'classification' => 'cash'])->save();
        $panel = Panel::factory()->create(['organisation_id' => $visit->organisation_id]);
        $officer = $this->actor('panel_officer');
        DB::table('billing_approval_limits')->insert(['organisation_id' => $visit->organisation_id, 'branch_id' => $visit->branch_id, 'user_id' => $officer->id, 'capability' => 'panel', 'limit_sen' => 5000]);
        // An approved Panel share, not yet claimed.
        $this->selectBranch($ca);
        $this->post(route('billing.propose', [$visit, $invoice, 'panel']), ['expected_branch_id' => $visit->branch_id, 'lock_version' => $invoice->refresh()->lock_version, 'amount_sen' => '3000', 'panel_id' => $panel->id, 'reason' => 'Synthetic Panel share'])->assertSessionHasNoErrors();
        $this->selectBranch($officer);
        $this->post(route('billing.approve', [$visit, $invoice, 'panel', CoverageAllocation::query()->sole()->public_id]), ['expected_branch_id' => $visit->branch_id, 'lock_version' => $invoice->refresh()->lock_version, 'proposal_lock_version' => CoverageAllocation::query()->sole()->lock_version])->assertSessionHasNoErrors();
        $this->selectBranch($ca);
        $this->post(route('billing.payment', [$visit, $invoice]), ['expected_branch_id' => $visit->branch_id, 'lock_version' => $invoice->refresh()->lock_version, 'amount_sen' => '1000', 'method' => 'cash', 'idempotency_key' => (string) Str::uuid()])->assertSessionHasNoErrors();

        // A second visit with a Panel proposal still waiting, and a third whose proposal went stale (a payment moved the invoice on).
        [$ca2, $waitingVisit, $waitingInvoice] = $this->anotherFinalized();
        $this->selectBranch($ca2);
        $this->post(route('billing.propose', [$waitingVisit, $waitingInvoice, 'panel']), ['expected_branch_id' => $waitingVisit->branch_id, 'lock_version' => $waitingInvoice->refresh()->lock_version, 'amount_sen' => '500', 'panel_id' => $panel->id, 'reason' => 'Synthetic waiting'])->assertSessionHasNoErrors();
        [$ca3, $staleVisit, $staleInvoice] = $this->anotherFinalized();
        $this->selectBranch($ca3);
        $this->post(route('billing.propose', [$staleVisit, $staleInvoice, 'panel']), ['expected_branch_id' => $staleVisit->branch_id, 'lock_version' => $staleInvoice->refresh()->lock_version, 'amount_sen' => '500', 'panel_id' => $panel->id, 'reason' => 'Synthetic stale'])->assertSessionHasNoErrors();
        $this->post(route('billing.payment', [$staleVisit, $staleInvoice]), ['expected_branch_id' => $staleVisit->branch_id, 'lock_version' => $staleInvoice->refresh()->lock_version, 'amount_sen' => '100', 'method' => 'cash', 'idempotency_key' => (string) Str::uuid()])->assertSessionHasNoErrors();

        $director = $this->actor('director');
        $this->selectBranch($director);
        app(SalesTargetService::class)->save($director, ['expected_branch_id' => $this->branch->id, 'branch_id' => $this->branch->id, 'month' => $saleDay->format('Y-m'),
            'doctor_user_id' => null, 'amount' => '200', 'lock_version' => 0, 'reason' => null, 'idempotency_key' => (string) Str::uuid()]);

        $this->travelTo($saleDay->addDays(2));
        $page = app(DirectorOverviewService::class)->page($director);
        $row = collect($page['branches'])->firstWhere('code', 'CHERAS');

        // Sales and target: the same net sales as Insights for the month so far.
        $monthStart = $saleDay->startOfMonth()->toDateString();
        $insights = app(ClinicInsightsReportService::class)->report($director, 'sales', 'CHERAS', $monthStart, $saleDay->addDays(2)->toDateString(), null)['report']['summary']['totalSen'];
        $this->assertSame(12000, $insights);
        $this->assertSame($insights, $row['target']['achievedSen']);
        $this->assertSame([20000, 60], [$row['target']['targetSen'], $row['target']['percent']]);
        $this->assertSame([0, 12000], [$row['sales']['todaySoFarSen'], $row['sales']['monthToYesterdaySen']]);

        // Panel money owed: the shared totals, and over a 31-day window the Panel summary report's own figure.
        $this->assertSame(3000, $row['panel']['unclaimedSen']);
        $this->assertSame([0, 0], [$row['panel']['awaitingDecisionSen'], $row['panel']['acceptedOutstandingSen']]);
        $this->assertSame($row['panel'], app(PanelSummaryReportService::class)->totals($director->organisation_id, $this->branch));
        $finance = $this->actor('finance_officer');
        $this->selectBranch($finance);
        $report = $this->postJson(route('reports.panel-summary.run'), ['expected_branch_id' => $this->branch->id, 'from' => $monthStart, 'to' => $saleDay->addDays(2)->toDateString()])->assertOk()->json('result');
        $this->assertSame('30.00', $report['totals']['Unclaimed responsibility (MYR)']);

        // Waiting: one live Panel proposal (the stale one is not counted).
        $this->assertSame(1, $row['waiting']['panel_or_pay_later']);
        $this->assertSame(0, $row['waiting']['refund']);
    }

    public function test_waiting_refunds_cash_closes_and_organisation_kinds_count_everyone_including_the_viewer(): void
    {
        $day = CarbonImmutable::now($this->branch->timezone)->startOfDay()->subDays(3)->setTime(10, 0);
        $this->travelTo($day);
        [, $ca, $visit, $invoice] = $this->finalizedFixture(4000);
        $cash = (new PaymentMethod)->forceFill(['organisation_id' => $visit->organisation_id, 'code' => 'cash', 'name' => 'Synthetic cash', 'classification' => 'cash']);
        $cash->save();
        $this->selectBranch($ca);
        $this->post(route('billing.payment', [$visit, $invoice]), ['expected_branch_id' => $visit->branch_id, 'lock_version' => $invoice->refresh()->lock_version, 'amount_sen' => '4000', 'method' => 'cash', 'idempotency_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
        $this->post(route('billing.complete', $visit), ['expected_branch_id' => $visit->branch_id, 'lock_version' => $invoice->refresh()->lock_version, 'visit_lock_version' => $visit->refresh()->lock_version])->assertSessionHasNoErrors();
        $receipt = DB::table('payments')->latest('id')->value('public_id');
        $finance = $this->actor('finance_officer');
        $this->selectBranch($finance);
        // The Finance Officer records a refund and leaves it waiting: it still counts on their own overview.
        $this->post(route('billing.refund', [$visit, $receipt]), ['expected_branch_id' => $visit->branch_id, 'payment_method_id' => $cash->id, 'reason_code' => 'medicine_returned', 'amount_sen' => '1000', 'idempotency_key' => (string) Str::uuid()])->assertSessionHasNoErrors();

        $this->travelTo($day->addDay());
        $this->selectBranch($ca);
        $this->post(route('cash-closings.store'), ['expected_branch_id' => $this->branch->id, 'business_date' => $day->toDateString(), 'counted_cash' => '40.00', 'variance_reason' => 'Synthetic: refund still waiting', 'idempotency_key' => (string) Str::uuid()])->assertSessionHasNoErrors();

        $this->selectBranch($finance);
        $page = app(DirectorOverviewService::class)->page($finance);
        $row = collect($page['branches'])->firstWhere('code', 'CHERAS');
        $this->assertSame(1, $row['waiting']['refund']);
        $this->assertSame(1, $row['waiting']['cash_close']);
        $this->assertSame('waiting', $row['yesterdayClose']);
        $this->assertSame(['purchase_order' => 0, 'panel_price_change' => 0], $page['organisationWaiting']);

        // Once decided, a refund is no longer waiting.
        $refund = DB::table('payment_refunds')->sole();
        $this->selectBranch($this->actor('director'));
        $this->post(route('billing.refund.decide', [$visit, $refund->public_id, 'approved']), ['expected_branch_id' => $visit->branch_id, 'reason_code' => null, 'idempotency_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
        $this->assertSame(0, collect(app(DirectorOverviewService::class)->page($finance)['branches'])->firstWhere('code', 'CHERAS')['waiting']['refund']);
    }

    public function test_month_comparison_uses_whole_days_and_branch_local_time(): void
    {
        $service = app(DirectorOverviewService::class);
        $director = $this->actor('director');
        $this->selectBranch($director);
        $sales = function (string $localTime) use ($service, $director): array {
            $this->travelTo(CarbonImmutable::parse($localTime, $this->branch->timezone));

            return collect($service->page($director)['branches'])->firstWhere('code', 'CHERAS')['sales'];
        };
        // On the 1st there is no whole day yet, at 00:30 and at 23:30 Kuala Lumpur time.
        foreach (['2027-03-01 00:30', '2027-03-01 23:30'] as $time) {
            $first = $sales($time);
            $this->assertSame([null, null, null], [$first['monthToYesterdaySen'], $first['lastMonthSameDaysSen'], $first['lastMonthFrom']]);
        }
        // The 31st after a 30-day month compares with the whole previous month (1-30), ending the day before.
        $this->assertSame(['2027-04-01', '2027-04-30'], array_values(array_intersect_key($sales('2027-05-31 23:30'), ['lastMonthFrom' => 0, 'lastMonthTo' => 0])));
        // 29 February: the comparison is 1-28 January.
        $this->assertSame(['2028-01-01', '2028-01-28'], array_values(array_intersect_key($sales('2028-02-29 00:30'), ['lastMonthFrom' => 0, 'lastMonthTo' => 0])));
        // 1 March after 29 February: no whole day yet.
        $this->assertNull($sales('2028-03-01 12:00')['lastMonthFrom']);
    }

    public function test_only_director_and_finance_officer_see_the_overview_and_no_patient_or_staff_field_is_sent(): void
    {
        foreach (['director', 'finance_officer'] as $role) {
            $actor = $this->actor($role);
            $this->selectBranch($actor);
            $this->get(route('insights.overview'))->assertOk()->assertInertia(fn (Assert $page) => $page->component('Insights/Overview')
                ->where('overview.branches', fn ($rows): bool => collect($rows)->every(fn ($row): bool => array_keys(collect($row)->all()) === ['branchId', 'branch', 'code', 'sales', 'target', 'panel', 'yesterdayClose', 'waiting'])));
            $this->get(route('insights.today'))->assertOk()->assertInertia(fn (Assert $page) => $page->where('canOverview', true));
        }
        foreach (['ca', 'ca_supervisor', 'administrator', 'panel_officer', 'branch_manager', 'account_assistant', 'store_admin', 'resident_doctor', 'hr_manager', 'marketing'] as $role) {
            $actor = $this->actor($role);
            $this->selectBranch($actor);
            $this->get(route('insights.overview'))->assertForbidden();
            if ($actor->can('insights.view.organisation')) {
                $this->get(route('insights.today'))->assertOk()->assertInertia(fn (Assert $page) => $page->where('canOverview', false));
            }
        }
        $this->expectException(AuthorizationException::class);
        app(DirectorOverviewService::class)->page($this->actor('ca'));
    }

    public function test_an_inactive_branch_is_left_out_and_the_permission_migration_is_additive(): void
    {
        $director = $this->actor('director');
        $this->selectBranch($director);
        $other = $this->branch->newQuery()->where('organisation_id', $this->branch->organisation_id)->where('id', '<>', $this->branch->id)->firstOrFail();
        $other->forceFill(['is_active' => false])->save();
        $codes = collect(app(DirectorOverviewService::class)->page($director)['branches'])->pluck('code')->all();
        $this->assertContains('CHERAS', $codes);
        $this->assertNotContains($other->code, $codes);

        $migration = require database_path('migrations/2026_11_24_100000_grant_director_finance_overview_permission.php');
        $migration->up();
        $migration->up();
        $holders = DB::table('role_has_permissions')->join('roles', 'roles.id', '=', 'role_has_permissions.role_id')->join('permissions', 'permissions.id', '=', 'role_has_permissions.permission_id')
            ->where('permissions.name', DirectorOverviewService::PERMISSION)->orderBy('roles.name')->pluck('roles.name')->all();
        $this->assertSame(['director', 'finance_officer'], $holders);
    }

    public function test_panel_totals_skip_settled_shares_in_sql_with_the_same_figures_and_a_bounded_number_of_queries(): void
    {
        $saleDay = CarbonImmutable::now($this->branch->timezone)->startOfMonth()->addMonthsNoOverflow(2)->setDay(12)->setTime(10, 0);
        $this->travelTo($saleDay);
        [, $ca, $visit, $invoice] = $this->finalizedFixture(4000);
        $panel = Panel::factory()->create(['organisation_id' => $visit->organisation_id]);
        $officer = $this->actor('panel_officer');
        DB::table('billing_approval_limits')->insert(['organisation_id' => $visit->organisation_id, 'branch_id' => $visit->branch_id, 'user_id' => $officer->id, 'capability' => 'panel', 'limit_sen' => 5000]);
        $this->selectBranch($ca);
        $this->post(route('billing.propose', [$visit, $invoice, 'panel']), ['expected_branch_id' => $visit->branch_id, 'lock_version' => $invoice->refresh()->lock_version, 'amount_sen' => '3000', 'panel_id' => $panel->id, 'reason' => 'Synthetic Panel share'])->assertSessionHasNoErrors();
        $this->selectBranch($officer);
        $this->post(route('billing.approve', [$visit, $invoice, 'panel', CoverageAllocation::query()->sole()->public_id]), ['expected_branch_id' => $visit->branch_id, 'lock_version' => $invoice->refresh()->lock_version, 'proposal_lock_version' => CoverageAllocation::query()->sole()->lock_version])->assertSessionHasNoErrors();

        $service = app(PanelSummaryReportService::class);
        $unfiltered = function () use ($service, $visit): array {
            $method = new \ReflectionMethod($service, 'groups');
            $sums = array_fill(0, 24, 0);
            foreach ($method->invoke($service, $visit->organisation_id, $this->branch, null, null, false) as $group) {
                for ($i = 0; $i < 24; $i++) {
                    $sums[$i] += (int) ($group['values'][$i] ?? 0);
                }
            }

            return [$sums[19], $sums[23], $sums[9], $sums[13], $sums[5], $sums[17]];
        };
        $this->assertSame($unfiltered(), array_values($service->totals($visit->organisation_id, $this->branch)));
        $this->assertSame(3000, $service->totals($visit->organisation_id, $this->branch)['unclaimedSen']);

        // A settled share (an original claim, submitted, accepted and paid in full) adds nothing and is skipped in SQL.
        $claimId = DB::table('panel_claims')->insertGetId(['public_id' => (string) Str::uuid(), 'organisation_id' => $visit->organisation_id, 'branch_id' => $visit->branch_id,
            'coverage_allocation_id' => CoverageAllocation::query()->sole()->id, 'claim_kind' => 'original', 'status' => 'paid', 'claim_amount_sen' => 3000, 'accepted_amount_sen' => 3000]
            + $this->claimDefaults($visit));
        DB::table('panel_claim_events')->insert(['organisation_id' => $visit->organisation_id, 'branch_id' => $visit->branch_id, 'panel_claim_id' => $claimId, 'event_type' => 'submitted', 'amount_sen' => 3000, 'occurred_at' => now()] + $this->eventDefaults());
        DB::table('panel_claim_payments')->insert(['organisation_id' => $visit->organisation_id, 'branch_id' => $visit->branch_id, 'panel_claim_id' => $claimId, 'amount_sen' => 3000] + $this->paymentDefaults());
        DB::enableQueryLog();
        $totals = $service->totals($visit->organisation_id, $this->branch);
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();
        $this->assertSame([0, 0, 0, 0, 0, 0], array_values($totals));
        $this->assertSame($unfiltered(), array_values($totals));
        $this->assertLessThanOrEqual(1, $queries, 'Settled shares are filtered out before the per-claim queries run.');
    }

    public function test_a_viewer_without_an_active_assigned_branch_is_refused(): void
    {
        $director = $this->actor('director');
        DB::table('staff_branch_assignments')->where('staff_profile_id', $director->staffProfile->id)->update(['valid_until' => now()->subDays(2)->toDateString(), 'valid_from' => now()->subDays(10)->toDateString()]);
        $this->actingAs($director);
        $response = $this->get(route('insights.overview'));
        $this->assertContains($response->status(), [403, 404], 'Refused without an effective branch assignment.');
    }

    /** @return array<string, mixed> */
    private function claimDefaults(Visit $visit): array
    {
        $invoice = DB::table('invoices')->where('visit_id', $visit->id)->first();
        $allocation = CoverageAllocation::query()->sole();

        return ['invoice_id' => $invoice->id, 'panel_id' => $allocation->panel_id, 'patient_id' => $invoice->patient_id, 'invoice_number' => $invoice->invoice_number,
            'visit_number' => $visit->visit_number, 'patient_name_snapshot' => 'Synthetic', 'panel_name_snapshot' => (string) $allocation->panel_name_snapshot,
            'branch_name_snapshot' => $this->branch->name, 'visit_date' => now()->toDateString(), 'invoice_total_sen' => (int) $invoice->total_sen,
            'lock_version' => 1, 'created_by_user_id' => $visit->registered_by_user_id, 'created_at' => now(), 'updated_at' => now()];
    }

    /** @return array<string, mixed> */
    private function eventDefaults(): array
    {
        return ['actor_user_id' => DB::table('users')->value('id'), 'created_at' => now(), 'updated_at' => now()];
    }

    /** @return array<string, mixed> */
    private function paymentDefaults(): array
    {
        return ['public_id' => (string) Str::uuid(), 'idempotency_key' => (string) Str::uuid(), 'payload_hash' => str_repeat('a', 64), 'received_at' => now(),
            'recorded_by_user_id' => DB::table('users')->value('id'), 'created_at' => now(), 'updated_at' => now()];
    }

    /**
     * A further completed consultation and finalised invoice at the consultation price already set up by
     * finalizedFixture() (which can run once per test).
     *
     * @return array{0: User, 1: Visit, 2: Invoice}
     */
    private function anotherFinalized(): array
    {
        [$doctor, $ca, $visit, $queue] = $this->servingFixture();
        $encounter = $this->startEncounter($doctor, $visit, $queue);
        app(CompleteConsultationService::class)->complete($doctor, $visit, ['expected_branch_id' => $visit->branch_id, 'visit_lock_version' => $visit->lock_version, 'queue_lock_version' => $queue->lock_version, 'encounter_lock_version' => $encounter->lock_version, 'lock_version' => null, 'service_deliveries' => []]);
        $this->selectBranch($ca, $visit->branch);
        $builder = app(BillingBuilderService::class);
        $invoice = $builder->build($ca, $visit, ['expected_branch_id' => $visit->branch_id, 'lock_version' => null]);

        return [$ca, $visit, $builder->finalize($ca, $visit, $invoice, ['expected_branch_id' => $visit->branch_id, 'lock_version' => $invoice->lock_version])];
    }
}
