<?php

namespace App\Domain\Agents\Tools;

use App\Domain\Organisation\Models\Branch;
use App\Domain\Shared\Services\ClinicInsightsReportService;
use App\Domain\Visit\Billing\Models\Invoice;
use App\Domain\Visit\Billing\Models\PaymentMethod;
use App\Domain\Visit\Billing\Services\FinancialLedger;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * `money.day_summary`: for a branch-local date (default today), per branch, with the Daily summary
 * (RPT-01c) definitions: receipts by payment method (receipt date), Cash and Non-cash net, total
 * received, Panel approved and approved pay-later Due (invoice finalisation date), plus net invoice
 * sales and discounts from the Sales summary. Money in sen; no patient data.
 */
final class MoneyDaySummaryTool implements AgentTool
{
    use ReadsBranches;

    public function __construct(private ClinicInsightsReportService $reports, private FinancialLedger $ledger) {}

    public function code(): string
    {
        return 'money.day_summary';
    }

    public function requiredPermission(): string
    {
        return 'insights.view.organisation';
    }

    public function inputSchema(): array
    {
        return ['date' => ['sometimes', 'date_format:Y-m-d']];
    }

    public function run(User $requester, ?Branch $branch, array $input): array
    {
        $methods = PaymentMethod::query()->where('organisation_id', $requester->organisation_id)->get()->keyBy('code');
        $result = [];
        foreach ($this->branchesFor($requester, $branch) as $each) {
            $date = $this->localDate($each, $input['date'] ?? null);
            $byMethod = [];
            $cash = 0;
            $nonCash = 0;
            foreach ($this->reports->branchReceiptRows($requester, $each, $date, $date) as $row) {
                if ((int) $row['receiptsSen'] === 0 && (int) $row['reversedSen'] === 0 && (int) $row['netSen'] === 0) {
                    continue; // all-zero rows are hidden, as on the Daily summary screen
                }
                $method = $methods->get($row['methodCode']);
                $isCash = $method?->reportClassification() === 'cash';
                $byMethod[] = ['method' => (string) $row['method'], 'methodCode' => (string) $row['methodCode'], 'cash' => $isCash,
                    'receiptsSen' => (int) $row['receiptsSen'], 'reversedSen' => (int) $row['reversedSen'], 'netSen' => (int) $row['netSen']];
                if ($isCash) {
                    $cash += (int) $row['netSen'];
                } else {
                    $nonCash += (int) $row['netSen'];
                }
            }
            $panel = 0;
            $due = 0;
            Invoice::query()->where('organisation_id', $requester->organisation_id)->where('branch_id', $each->id)->where('status', 'finalized')
                ->where('finalized_at', '>=', CarbonImmutable::parse($date, $each->timezone)->startOfDay()->utc())
                ->where('finalized_at', '<', CarbonImmutable::parse($date, $each->timezone)->addDay()->startOfDay()->utc())
                ->chunkById(500, function ($invoices) use (&$panel, &$due): void {
                    foreach ($this->ledger->states($invoices) as $state) {
                        $panel += (int) $state['panel'];
                        $due += (int) $state['deferred'];
                    }
                });
            $sales = $this->reports->report($requester, 'sales', $each->code, $date, $date, null)['report']['summary'];
            $result[] = [
                'branch' => $each->code,
                'date' => $date,
                'netSalesSen' => (int) $sales['totalSen'],
                'discountsSen' => (int) $sales['reconciliation']['discountsSen'],
                'receiptsByMethod' => $byMethod,
                'cashNetSen' => $cash,
                'nonCashNetSen' => $nonCash,
                'totalReceivedSen' => $cash + $nonCash,
                'panelApprovedSen' => $panel,
                'payLaterDueSen' => $due,
            ];
        }

        return ['scope' => $branch?->code ?? 'all', 'branches' => $result];
    }
}
