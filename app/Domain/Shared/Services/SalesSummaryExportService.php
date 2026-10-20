<?php

namespace App\Domain\Shared\Services;

use App\Domain\Access\BranchAccessService;
use App\Domain\Access\TransactionalActorAuthority;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Organisation\Models\Branch;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class SalesSummaryExportService
{
    public const PERMISSION = 'reports.sales.export.organisation';

    public function __construct(private BranchAccessService $branches, private TransactionalActorAuthority $authority, private ClinicInsightsReportService $reports, private AuditRecorder $audit) {}

    /** @return array{name:string, csv:string} */
    public function download(User $actor, string $scope, string $from, string $to, ?int $doctor): array
    {
        return DB::transaction(function () use ($actor, $scope, $from, $to, $doctor): array {
            // Real requests own this transaction. A test harness may already wrap the request.
            if (DB::getDriverName() === 'pgsql' && DB::transactionLevel() === 1) {
                DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            }
            abort_unless(DB::table('organisations')->where('id', $actor->organisation_id)->where('is_active', true)->exists(), 403);
            $branch = $this->branches->activeBranch($actor);
            abort_unless($branch && $branch->organisation_id === $actor->organisation_id, 403);
            $actor = $this->authority->lock($actor, $branch, self::PERMISSION);
            $grants = $actor->getAllPermissions()->pluck('name');
            abort_unless($grants->contains(self::PERMISSION) && $grants->contains('insights.view.organisation'), 403);
            $data = $this->reports->report($actor, 'sales', $scope, $from, $to, $doctor);
            $summary = $data['report']['summary'];
            $zones = Branch::query()->where('organisation_id', $actor->organisation_id)->where('is_active', true)
                ->when($scope !== 'all', fn ($q) => $q->where('code', $scope))->orderBy('code')->get()
                ->map(fn (Branch $b): string => $b->code.'='.$b->timezone)->implode('; ');
            $generated = now()->utc()->toIso8601String();
            $rows = [];
            $add = function (string $period, string $metric, int $value, string $unit) use (&$rows, $data, $scope, $doctor, $zones, $generated): void {
                $previous = $period === 'previous';
                $rows[] = ['sales_summary', $scope, $zones, $doctor === null ? 'all' : (string) $doctor, $generated,
                    $previous ? $data['previousFrom'] : $data['from'], $previous ? $data['previousTo'] : $data['to'],
                    $period, $metric, $unit === 'MYR' ? ReportCsv::money($value) : (string) $value, $unit];
            };
            foreach (['grossSen' => 'Gross item sales', 'discountsSen' => 'Invoice discounts', 'surchargesSen' => 'Invoice surcharges', 'netSen' => 'Net invoice sales'] as $key => $label) {
                $add('current', $label, $summary['reconciliation'][$key], 'MYR');
            }
            foreach (['newSen' => 'New net sales', 'returningSen' => 'Returning net sales', 'perPatientSen' => 'Net sales per patient'] as $key => $label) {
                $add('current', $label, $summary[$key], 'MYR');
            }
            $add('current', 'Patients', $summary['patients'], 'count');
            $add('previous', 'Net invoice sales', $summary['previousTotalSen'], 'MYR');
            $add('previous', 'Patients', $summary['previousPatients'], 'count');
            $add('previous', 'Net sales per patient', $summary['previousPerPatientSen'], 'MYR');
            $csv = ReportCsv::encode(['Report', 'Branch scope', 'Branch timezones', 'Doctor filter', 'Generated at UTC', 'Period from', 'Period to', 'Period', 'Metric', 'Value', 'Unit'], $rows);
            // No values, rows, names, user-entered text or arbitrary query parameters in audit.
            $audit = $this->audit->record('reports.sales.exported', null, ['dataset' => 'sales_summary', 'branch_scope' => $scope, 'from' => $from, 'to' => $to, 'doctor_filter' => $doctor !== null, 'row_count' => count($rows)], $actor);
            abort_if($audit === null, 503, 'Unable to record this download. Try again later.');

            return ['name' => 'sales-summary_'.$from.'_'.$to.'.csv', 'csv' => $csv];
        }, 3);
    }
}
