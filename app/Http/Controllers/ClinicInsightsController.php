<?php

namespace App\Http\Controllers;

use App\Domain\Agents\Ask\AskSwitch;
use App\Domain\Shared\Services\ClinicInsightsReportService;
use App\Domain\Shared\Services\ClinicInsightsService;
use App\Domain\Shared\Services\DailyReportExportService;
use App\Domain\Shared\Services\InsightsBranchScope;
use App\Domain\Shared\Services\SalesSummaryExportService;
use App\Domain\Visit\Billing\Overview\DirectorOverviewService;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ClinicInsightsController extends Controller
{
    public function today(Request $request, ClinicInsightsService $insights, InsightsBranchScope $scope): Response
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        $filters = $request->validate([
            'branch' => ['sometimes', 'string', 'max:40'],
        ]);
        // INS-BM-1: a branch-only account (for example the Branch Manager or a CA) sees its active branch only.
        $branch = $scope->resolve($actor, $filters['branch'] ?? null);

        return Inertia::render('Insights/Today', [
            'branch' => $branch,
            'branches' => $scope->pickerBranches($actor, $insights->todayBranches($actor)),
            'canSeeAllBranches' => $scope->seesOrganisation($actor),
            'report' => $insights->today($actor, $branch),
            'canOverview' => $actor->can(DirectorOverviewService::PERMISSION),
        ]);
    }

    /** BIL-31: the Director and Finance overview (owner item 32, P8). Rules live in DirectorOverviewService. */
    public function overview(Request $request, DirectorOverviewService $overview): Response
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        // The Ask KPOne drawer only calls the paths that are switched on and allowed for this person, so a switched-off path costs no failed request.
        return Inertia::render('Insights/Overview', [
            'canOverview' => true,
            'overview' => $overview->page($actor),
            'askKpone' => AskSwitch::pathsFor($actor),
        ]);
    }

    public function report(Request $request, ClinicInsightsReportService $insights, InsightsBranchScope $scope, string $section): Response
    {
        abort_unless(in_array($section, ['sales', 'in-clinic', 'payments', 'inventory', 'patients'], true), 404);

        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        $filters = $request->validate([
            'branch' => ['sometimes', 'string', 'max:40'],
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d'],
            'doctor' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ]);
        $defaultDate = CarbonImmutable::now('Asia/Kuala_Lumpur')->subDay()->toDateString();
        $from = $filters['from'] ?? $defaultDate;
        $to = $filters['to'] ?? $defaultDate;
        if ($to < $from) {
            throw ValidationException::withMessages(['to' => 'The end date must be on or after the start date.']);
        }
        // INS-BM-1: a branch-only account (for example the Branch Manager or a CA) sees its active branch only, and its download links follow.
        $branch = $scope->resolve($actor, $filters['branch'] ?? null);

        return Inertia::render('Insights/Report', [
            'canOverview' => $actor->can(DirectorOverviewService::PERMISSION),
            'dailyDownloadUrl' => in_array($section, ['sales', 'payments'], true) && $actor->can($section === 'sales' ? SalesSummaryExportService::PERMISSION : DailyReportExportService::PAYMENTS_PERMISSION) ? route($section === 'sales' ? 'insights.daily-sales.download' : 'insights.daily-receipts.download', ['from' => $from, 'to' => $to, 'branch' => $branch, 'doctor' => $filters['doctor'] ?? null]) : null,
            'summaryDownloadUrl' => $section === 'sales' && $actor->can(SalesSummaryExportService::PERMISSION) ? route('insights.sales-summary.download', ['from' => $from, 'to' => $to, 'branch' => $branch, 'doctor' => $filters['doctor'] ?? null]) : null,
            'filters' => [
                'section' => $section,
                'branch' => $branch,
                'from' => $from,
                'to' => $to,
                'doctor' => isset($filters['doctor']) ? (int) $filters['doctor'] : null,
            ],
            'branches' => $scope->pickerBranches($actor, $insights->branches($actor)),
            'canSeeAllBranches' => $scope->seesOrganisation($actor),
            'doctors' => $insights->doctors($actor),
            'data' => $insights->report(
                $actor,
                $section,
                $branch,
                $from,
                $to,
                isset($filters['doctor']) ? (int) $filters['doctor'] : null,
            ),
        ]);
    }

    public function downloadSalesSummary(Request $request, SalesSummaryExportService $exports, InsightsBranchScope $scope): StreamedResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);
        try {
            $filters = $request->validate([
                'branch' => ['sometimes', 'string', 'max:40'],
                'from' => ['sometimes', 'date_format:Y-m-d'],
                'to' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:from'],
                'doctor' => ['sometimes', 'nullable', 'integer', 'min:1'],
            ]);
            $default = CarbonImmutable::now('Asia/Kuala_Lumpur')->subDay()->toDateString();
            $result = $exports->download($actor, $filters['branch'] ?? $scope->defaultScope($actor), $filters['from'] ?? $default, $filters['to'] ?? $default, isset($filters['doctor']) ? (int) $filters['doctor'] : null);
        } catch (ValidationException $e) {
            throw $e->redirectTo(route('insights.report', ['section' => 'sales']));
        }

        return response()->streamDownload(static function () use ($result): void {
            echo $result['csv'];
        }, $result['name'], ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'no-store, private']);
    }

    public function downloadDailyReport(Request $request, DailyReportExportService $exports, InsightsBranchScope $scope, string $dataset): StreamedResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);
        $section = $dataset === 'daily_sales' ? 'sales' : 'payments';
        try {
            $filters = $request->validate([
                'branch' => ['sometimes', 'string', 'max:40'],
                'from' => ['sometimes', 'date_format:Y-m-d'],
                'to' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:from'],
                'doctor' => ['sometimes', 'nullable', 'integer', 'min:1'],
            ]);
            $default = CarbonImmutable::now('Asia/Kuala_Lumpur')->subDay()->toDateString();
            $result = $exports->download($actor, $dataset, $filters['branch'] ?? $scope->defaultScope($actor), $filters['from'] ?? $default, $filters['to'] ?? $default, isset($filters['doctor']) ? (int) $filters['doctor'] : null);
        } catch (ValidationException $e) {
            throw $e->redirectTo(route('insights.report', ['section' => $section]));
        }

        return response()->streamDownload(static function () use ($result): void {
            echo $result['csv'];
        }, $result['name'], ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'no-store, private']);
    }
}
