<?php

namespace App\Domain\Agents\Tools;

use App\Domain\Organisation\Models\Branch;
use App\Domain\Shared\Services\ClinicInsightsService;
use App\Models\User;
use Carbon\CarbonImmutable;

/** `insights.today`: today against yesterday from the Insights "Today" report, totals only (no rankings). */
final class InsightsTodayTool implements AgentTool
{
    /** The organisation's clinics are all in Malaysia; used only when no branch is given. */
    public const DEFAULT_TIMEZONE = 'Asia/Kuala_Lumpur';

    public function __construct(private ClinicInsightsService $insights) {}

    public function code(): string
    {
        return 'insights.today';
    }

    public function requiredPermission(): string
    {
        return 'insights.view.organisation';
    }

    public function inputSchema(): array
    {
        return [];
    }

    public function run(User $requester, ?Branch $branch, array $input): array
    {
        $today = $this->insights->today($requester, $branch->code ?? 'all');
        $sales = $today['sales'];
        $time = fn (string $key): array => [
            'averageMinutes' => (int) $today['time'][$key]['average'],
            'longestMinutes' => (int) $today['time'][$key]['longest'],
            'previousAverageMinutes' => (int) $today['time'][$key]['previousAverage'],
        ];

        return [
            'scope' => $branch->code ?? 'all',
            'date' => CarbonImmutable::now($branch->timezone ?? self::DEFAULT_TIMEZONE)->toDateString(),
            'sales' => [
                'netSen' => (int) $sales['totalSen'],
                'grossSen' => (int) $sales['reconciliation']['grossSen'],
                'discountsSen' => (int) $sales['reconciliation']['discountsSen'],
                'surchargesSen' => (int) $sales['reconciliation']['surchargesSen'],
                'newSen' => (int) $sales['newSen'],
                'returningSen' => (int) $sales['returningSen'],
                'uniqueVisitors' => (int) $sales['patientCount'],
                'perVisitorSen' => (int) $sales['averagePerPatientSen'],
                'previousNetSen' => (int) $sales['previousTotalSen'],
                'previousUniqueVisitors' => (int) $sales['previousPatientCount'],
                'previousPerVisitorSen' => (int) $sales['previousAveragePerPatientSen'],
            ],
            'time' => ['waiting' => $time('waiting'), 'serving' => $time('serving'), 'inClinic' => $time('inClinic')],
        ];
    }
}
