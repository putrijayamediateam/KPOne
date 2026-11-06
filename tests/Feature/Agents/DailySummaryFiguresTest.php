<?php

namespace Tests\Feature\Agents;

use App\Domain\Agents\DailySummary\DailyRun;
use App\Domain\Agents\Health\SystemHealthTool;
use App\Domain\Visit\Billing\Models\PaymentMethod;
use App\Domain\Visit\Billing\Services\PaymentService;
use App\Domain\Visit\Billing\Services\ResponsibilityService;
use App\Domain\Visit\Models\Panel;
use Carbon\CarbonImmutable;
use Database\Seeders\KPOneAgentSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\Feature\Billing\BillingTestCase;

/** AG-8: Lina's money and Panel figures on the daily summary equal the Daily summary and Panel summary reports. */
class DailySummaryFiguresTest extends BillingTestCase
{
    public function test_lina_money_and_panel_figures_equal_the_reports_for_the_same_date(): void
    {
        $logs = storage_path('framework/testing/ag8-figures-'.Str::lower(Str::random(10)));
        File::ensureDirectoryExists($logs);
        $this->app->instance(SystemHealthTool::class, new SystemHealthTool($logs));
        $this->seed(KPOneAgentSeeder::class);

        $this->travelTo(CarbonImmutable::parse('2026-11-05 10:00:00', 'Asia/Kuala_Lumpur'));
        [, $ca, $visit, $invoice] = $this->finalizedFixture(10000);
        foreach (['CASH' => 'cash', 'CARD' => null] as $code => $classification) {
            (new PaymentMethod)->forceFill(['organisation_id' => $this->organisation->id, 'code' => $code, 'name' => "Synthetic {$code}", 'classification' => $classification, 'is_active' => true])->save();
        }
        foreach (['CASH' => 2500, 'CARD' => 4000] as $method => $amount) {
            app(PaymentService::class)->add($ca, $visit, $invoice->refresh(), ['expected_branch_id' => $visit->branch_id, 'lock_version' => $invoice->lock_version, 'amount_sen' => $amount, 'method' => $method, 'idempotency_key' => (string) Str::uuid()]);
        }
        $finance = $this->actor('finance_officer');
        $officer = $this->actor('panel_officer');
        $panel = Panel::factory()->create(['organisation_id' => $this->organisation->id]);
        DB::table('billing_approval_limits')->insert(['organisation_id' => $this->organisation->id, 'branch_id' => $this->branch->id, 'user_id' => $officer->id, 'capability' => 'panel', 'limit_sen' => 5000]);
        $this->selectBranch($finance);
        $proposal = app(ResponsibilityService::class)->propose($finance, $visit, $invoice->refresh(), 'panel', ['expected_branch_id' => $visit->branch_id, 'lock_version' => $invoice->lock_version, 'amount_sen' => 3500, 'panel_id' => $panel->id, 'reason' => 'Synthetic responsibility']);
        $this->selectBranch($officer);
        app(ResponsibilityService::class)->approve($officer, $visit, $invoice->refresh(), 'panel', $proposal->public_id, ['expected_branch_id' => $visit->branch_id, 'lock_version' => $invoice->lock_version, 'proposal_lock_version' => $proposal->lock_version]);

        // The next morning at 07:30 the run stores Lina's report for 2026-11-05.
        $this->travelTo(CarbonImmutable::parse('2026-11-06 07:30:00', 'Asia/Kuala_Lumpur'));
        $this->assertSame([], app(DailyRun::class)->run('0700', $this->branch->code)['failed']);

        $this->selectBranch($finance);
        $lina = $this->actingAs($finance)->get('/agents/daily-summary?date=2026-11-06')->assertOk()->viewData('page')['props']['summary']['branches'][0]['linaMorning'];
        $this->assertSame('2026-11-05', $lina['date']);
        $filters = ['expected_branch_id' => $this->branch->id, 'from' => '2026-11-05', 'to' => '2026-11-05'];
        $daily = $this->postJson(route('reports.daily-summary.run'), $filters)->assertOk()->json('result.rows.0');
        $money = fn (int $sen): string => number_format($sen / 100, 2, '.', '');
        $now = $lina['money']['now'];
        $this->assertSame(
            [$daily[3], $daily[6], $daily[7], $daily[8], $daily[11]],
            [$money($now['cashNetSen']), $money($now['nonCashNetSen']), $money($now['panelApprovedSen']), $money($now['payLaterDueSen']), $money($now['totalReceivedSen'])],
        );
        $this->assertSame(['25.00', '40.00', '35.00', '65.00'], [$daily[3], $daily[6], $daily[7], $daily[11]]);

        $panelRow = $this->postJson(route('reports.panel-summary.run'), $filters)->assertOk()->json('result.rows.0');
        $this->assertSame([$panelRow[5], $panelRow[6]], [(string) $lina['panel']['unclaimedInvoices'], $money($lina['panel']['unclaimedSen'])]);
        $this->assertSame(['1', '35.00'], [$panelRow[5], $panelRow[6]]);
        File::deleteDirectory($logs);
    }
}
