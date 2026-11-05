<?php

namespace App\Domain\Agents\Tools;

use App\Domain\Organisation\Models\Branch;
use App\Domain\Shared\Services\ClinicInsightsReportService;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/** `insights.sales_summary`: the Sales summary figures behind RPT-01a for a date range (default yesterday). */
final class InsightsSalesSummaryTool implements AgentTool
{
    public const MAX_DAYS = 92;

    public function __construct(private ClinicInsightsReportService $reports) {}

    public function code(): string
    {
        return 'insights.sales_summary';
    }

    public function requiredPermission(): string
    {
        return 'insights.view.organisation';
    }

    public function inputSchema(): array
    {
        return [
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d'],
        ];
    }

    public function run(User $requester, ?Branch $branch, array $input): array
    {
        $yesterday = CarbonImmutable::now($branch->timezone ?? 'Asia/Kuala_Lumpur')->subDay()->toDateString();
        $from = $input['from'] ?? ($input['to'] ?? $yesterday);
        $to = $input['to'] ?? $from;
        if ($to < $from) {
            throw ValidationException::withMessages(['to' => 'The end date must not be before the start date.']);
        }
        if (CarbonImmutable::parse($from)->diffInDays(CarbonImmutable::parse($to)) >= self::MAX_DAYS) {
            throw ValidationException::withMessages(['to' => 'Choose at most '.self::MAX_DAYS.' days.']);
        }
        $result = $this->reports->report($requester, 'sales', $branch->code ?? 'all', $from, $to, null);
        $summary = $result['report']['summary'];

        return [
            'scope' => $branch->code ?? 'all',
            'from' => $result['from'],
            'to' => $result['to'],
            'previousFrom' => $result['previousFrom'],
            'previousTo' => $result['previousTo'],
            'netSen' => (int) $summary['totalSen'],
            'grossSen' => (int) $summary['reconciliation']['grossSen'],
            'discountsSen' => (int) $summary['reconciliation']['discountsSen'],
            'surchargesSen' => (int) $summary['reconciliation']['surchargesSen'],
            'newSen' => (int) $summary['newSen'],
            'returningSen' => (int) $summary['returningSen'],
            'uniqueVisitors' => (int) $summary['patients'],
            'perVisitorSen' => (int) $summary['perPatientSen'],
            'previousNetSen' => (int) $summary['previousTotalSen'],
            'previousUniqueVisitors' => (int) $summary['previousPatients'],
            'previousPerVisitorSen' => (int) $summary['previousPerPatientSen'],
        ];
    }
}
