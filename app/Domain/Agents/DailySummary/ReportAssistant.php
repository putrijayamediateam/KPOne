<?php

namespace App\Domain\Agents\DailySummary;

use App\Domain\Agents\Models\AiAgent;
use App\Domain\Agents\Models\AiAgentRun;
use App\Domain\Agents\Services\AgentRunRecorder;
use App\Domain\Agents\Tools\AgentToolGateway;
use App\Domain\Organisation\Models\Branch;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Lina, the report assistant (AG-8, contract section 14). For one branch and one local date she reads the clinic
 * day, money day, Panel claims and stock review data sources through the gateway's system path and returns the
 * report as aggregates, in parts the page shows by permission: clinic, doctors, money, panel and stock.
 *
 * The 07:00 report is a whole day ("yesterday") and also reads the same weekday last week, so each clinic and
 * money figure can show an arrow. The 14:00 update is "today so far" with no comparison (owner decision
 * 2026-11-06: half a day against a whole day would look like a drop). Panel and stock figures are as of the
 * run, with no comparison. Lina changes nothing, proposes nothing and sends nothing.
 */
final class ReportAssistant
{
    public const AGENT = 'report_assistant';

    public const TASK = 'daily_report';

    public function __construct(private AgentToolGateway $gateway, private AgentRunRecorder $recorder) {}

    /** @return array<string, mixed> */
    public function report(AiAgentRun $run, Branch $branch, string $date, bool $compare): array
    {
        $agent = AiAgent::query()->whereKey($run->ai_agent_id)->where('organisation_id', $run->organisation_id)->first();
        if (! $agent || $agent->code !== self::AGENT || $run->task_type !== self::TASK) {
            throw new AuthorizationException('Only the report assistant writes the daily report.');
        }
        $previousDate = CarbonImmutable::parse($date)->subDays(7)->toDateString();
        $read = fn (string $tool, array $input = []): array => $this->gateway->callAsSystem($run, $tool, $branch, $input);
        $only = fn (array $result): array => (array) (collect((array) $result['branches'])->firstWhere('branch', $branch->code) ?? []);

        $clinic = $only($read('clinic.day_summary', ['date' => $date]));
        $money = $only($read('money.day_summary', ['date' => $date]));
        $previousClinic = $compare ? $only($read('clinic.day_summary', ['date' => $previousDate])) : null;
        $previousMoney = $compare ? $only($read('money.day_summary', ['date' => $previousDate])) : null;
        $panel = $only($read('panel.claims_summary'))['totals'] ?? [];
        $stock = (array) ($read('inventory.stock_review')['totals'] ?? []);

        $clinicFigures = fn (array $day): array => [
            'registered' => (int) ($day['registered'] ?? 0),
            'served' => (int) ($day['served'] ?? 0),
            'averageWaitMinutes' => (int) ($day['averageWaitMinutes'] ?? 0),
            'averageConsultationMinutes' => (int) ($day['averageConsultationMinutes'] ?? 0),
        ];
        $moneyFigures = fn (array $day): array => [
            'netSalesSen' => (int) ($day['netSalesSen'] ?? 0),
            'totalReceivedSen' => (int) ($day['totalReceivedSen'] ?? 0),
            'cashNetSen' => (int) ($day['cashNetSen'] ?? 0),
            'nonCashNetSen' => (int) ($day['nonCashNetSen'] ?? 0),
            'panelApprovedSen' => (int) ($day['panelApprovedSen'] ?? 0),
            'payLaterDueSen' => (int) ($day['payLaterDueSen'] ?? 0),
        ];
        $previousSeen = collect((array) ($previousClinic['doctors'] ?? []))->mapWithKeys(fn (array $d): array => [(int) $d['doctorId'] => (int) $d['seen']]);

        $clinicNow = $clinicFigures($clinic);
        $report = [
            'date' => $date,
            'kind' => $compare ? 'whole_day' : 'so_far',
            'comparedWith' => $compare ? $previousDate : null,
            'clinic' => ['now' => $clinicNow, 'before' => $compare ? $clinicFigures($previousClinic ?? []) : null],
            'doctors' => array_values(array_map(fn (array $d): array => [
                'doctorId' => (int) $d['doctorId'],
                'doctor' => (string) $d['doctor'],
                'seen' => (int) $d['seen'],
                'averageConsultationMinutes' => (int) $d['averageConsultationMinutes'],
                'seenBefore' => $compare ? (int) ($previousSeen[(int) $d['doctorId']] ?? 0) : null,
            ], (array) ($clinic['doctors'] ?? []))),
            'money' => ['now' => $moneyFigures($money), 'before' => $compare ? $moneyFigures($previousMoney ?? []) : null],
            'panel' => [
                'unclaimedInvoices' => (int) ($panel['unclaimedInvoices'] ?? 0),
                'unclaimedSen' => (int) ($panel['unclaimedSen'] ?? 0),
                'overdueUnclaimedInvoices' => (int) ($panel['overdueUnclaimedInvoices'] ?? 0),
                'overdueUnclaimedSen' => (int) ($panel['overdueUnclaimedSen'] ?? 0),
                'acceptedUnpaidClaims' => (int) ($panel['acceptedUnpaidClaims'] ?? 0),
                'acceptedOutstandingSen' => (int) ($panel['acceptedOutstandingSen'] ?? 0),
            ],
            'stock' => ['lowStock' => (int) ($stock['lowStock'] ?? 0), 'expiring' => (int) ($stock['expiring'] ?? 0), 'expired' => (int) ($stock['expired'] ?? 0)],
        ];
        $this->recorder->step($run, 'decision', sprintf('%s %s: %d registered, %d served.', $branch->code, $compare ? 'report for '.$date : 'so far on '.$date, $clinicNow['registered'], $clinicNow['served']));

        return $report;
    }
}
