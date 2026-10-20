<?php

namespace App\Domain\Shared\Services;

use App\Domain\Access\BranchAccessService;
use App\Domain\Access\TransactionalActorAuthority;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Organisation\Models\Branch;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class DailyReportExportService
{
    public const PAYMENTS_PERMISSION = 'reports.payments.export.organisation';

    public function __construct(private BranchAccessService $branches, private TransactionalActorAuthority $authority, private ClinicInsightsReportService $reports, private AuditRecorder $audit) {}

    /** @return array{name:string, csv:string} */
    public function download(User $actor, string $dataset, string $scope, string $from, string $to, ?int $doctor): array
    {
        abort_unless(in_array($dataset, ['daily_sales', 'daily_receipts'], true), 404);
        $section = $dataset === 'daily_sales' ? 'sales' : 'payments';
        $permission = $section === 'sales' ? SalesSummaryExportService::PERMISSION : self::PAYMENTS_PERMISSION;

        return DB::transaction(function () use ($actor, $dataset, $section, $permission, $scope, $from, $to, $doctor): array {
            // Real requests own this transaction. A test harness may already wrap the request.
            if (DB::getDriverName() === 'pgsql' && DB::transactionLevel() === 1) {
                DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            }
            abort_unless(DB::table('organisations')->where('id', $actor->organisation_id)->where('is_active', true)->exists(), 403);
            $branch = $this->branches->activeBranch($actor);
            abort_unless($branch && $branch->organisation_id === $actor->organisation_id, 403);
            $actor = $this->authority->lock($actor, $branch, $permission);
            $grants = $actor->getAllPermissions()->pluck('name');
            abort_unless($grants->contains($permission) && $grants->contains('insights.view.organisation'), 403);
            $data = $this->reports->report($actor, $section, $scope, $from, $to, $doctor);
            $zones = Branch::query()->where('organisation_id', $actor->organisation_id)->where('is_active', true)
                ->when($scope !== 'all', fn ($q) => $q->where('code', $scope))->orderBy('code')->get()
                ->map(fn (Branch $b): string => $b->code.'='.$b->timezone)->implode('; ');
            $generated = now()->utc()->toIso8601String();
            $rows = [];
            if ($dataset === 'daily_sales') {
                $values = array_column($data['report']['dailyTrend'], 'salesSen', 'date');
                $patients = array_column($data['report']['dailyTrend'], 'patients', 'date');
                for ($date = CarbonImmutable::parse($from, 'UTC'); $date->toDateString() <= $to; $date = $date->addDay()) {
                    $rows[] = [$date->toDateString(), ReportCsv::money($values[$date->toDateString()] ?? 0), (string) ($patients[$date->toDateString()] ?? 0)];
                }
                $headings = ['Local date', 'Net invoice sales (MYR)', 'Distinct patients'];
                $basis = 'Invoice finalisation date in each branch timezone; current adjusted payable. Later adjustments restate that original date. Not receipt-date money.';
            } else {
                foreach ($data['report']['dailyReceipts'] as $row) {
                    $rows[] = [$row['date'], $row['methodCode'], $row['method'], ReportCsv::money($row['receiptsSen']), ReportCsv::money($row['reversedSen']), ReportCsv::money($row['netSen'])];
                }
                $headings = ['Local receipt date', 'Payment method code', 'Payment method (receipt snapshot)', 'Receipts (MYR)', 'Reversed (MYR)', 'Net received (MYR)'];
                $basis = 'Original received_at in each branch timezone, not invoice finalisation. Receipts include later reversed receipts; reversed attributed to original receipt date; net = currently posted receipts (terminal reconciliation basis). Not refund-date cash flow. Excludes Panel claim remittances.';
            }
            $metadata = [['Report', $dataset], ['Branch scope', $scope], ['Branch timezones', $zones], ['Period from', $from], ['Period to', $to], ['Generated at UTC', $generated], ['Currency', 'MYR'], ['Doctor filter', $doctor === null ? 'all' : (string) $doctor], ['Basis', $basis], ['Empty dates', 'Zero rows included; receipts use methods observed in the range, or (none) if empty.'], ['Reprint basis', 'Current records at generation time; not a historical issued snapshot.']];
            if ($dataset === 'daily_sales') {
                $metadata[] = ['Patient counting', 'Distinct patient records within the same finalised, non-void invoice cohort as net sales, per branch-local finalisation date. Multiple invoices or selected branches for the same patient on that local date count once. The same patient on another date counts again; daily counts need not sum to the period distinct count. Zero-value finalised invoices still count.'];
            }
            $csv = ReportCsv::encode($headings, $rows, $metadata);
            $audit = $this->audit->record($section === 'sales' ? 'reports.sales.exported' : 'reports.payments.exported', null, ['dataset' => $dataset, 'branch_scope' => $scope, 'from' => $from, 'to' => $to, 'row_count' => count($rows)], $actor);
            abort_if($audit === null, 503, 'Unable to record this download. Try again later.');

            return ['name' => str_replace('_', '-', $dataset).'_'.$from.'_'.$to.'.csv', 'csv' => $csv];
        }, 3);
    }
}
