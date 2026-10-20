<?php

namespace App\Http\Controllers;

use App\Domain\Shared\Services\ClinicInsightsReportService;
use App\Domain\Shared\Services\ClinicInsightsService;
use App\Domain\Shared\Services\DailyReportExportService;
use App\Domain\Shared\Services\SalesSummaryExportService;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ClinicInsightsController extends Controller
{
    public function today(Request $request, ClinicInsightsService $insights): Response
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        $filters = $request->validate([
            'branch' => ['sometimes', 'string', 'max:40'],
        ]);
        $branch = $filters['branch'] ?? 'all';

        return Inertia::render('Insights/Today', [
            'branch' => $branch,
            'branches' => $insights->todayBranches($actor),
            'report' => $insights->today($actor, $branch),
        ]);
    }

    public function report(Request $request, ClinicInsightsReportService $insights, string $section): Response
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

        return Inertia::render('Insights/Report', [
            'dailyDownloadUrl' => in_array($section, ['sales', 'payments'], true) && $actor->can($section === 'sales' ? SalesSummaryExportService::PERMISSION : DailyReportExportService::PAYMENTS_PERMISSION) ? route($section === 'sales' ? 'insights.daily-sales.download' : 'insights.daily-receipts.download', ['from' => $from, 'to' => $to, 'branch' => $filters['branch'] ?? 'all', 'doctor' => $filters['doctor'] ?? null]) : null,
            'summaryDownloadUrl' => $section === 'sales' && $actor->can(SalesSummaryExportService::PERMISSION) ? route('insights.sales-summary.download', ['from' => $from, 'to' => $to, 'branch' => $filters['branch'] ?? 'all', 'doctor' => $filters['doctor'] ?? null]) : null,
            'filters' => [
                'section' => $section,
                'branch' => $filters['branch'] ?? 'all',
                'from' => $from,
                'to' => $to,
                'doctor' => isset($filters['doctor']) ? (int) $filters['doctor'] : null,
            ],
            'branches' => $insights->branches($actor),
            'doctors' => $insights->doctors($actor),
            'data' => $insights->report(
                $actor,
                $section,
                $filters['branch'] ?? 'all',
                $from,
                $to,
                isset($filters['doctor']) ? (int) $filters['doctor'] : null,
            ),
        ]);
    }

    public function downloadSalesSummary(Request $request, SalesSummaryExportService $exports): StreamedResponse
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
            $result = $exports->download($actor, $filters['branch'] ?? 'all', $filters['from'] ?? $default, $filters['to'] ?? $default, isset($filters['doctor']) ? (int) $filters['doctor'] : null);
        } catch (ValidationException $e) {
            throw $e->redirectTo(route('insights.report', ['section' => 'sales']));
        }

        return response()->streamDownload(static function () use ($result): void {
            echo $result['csv'];
        }, $result['name'], ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'no-store, private']);
    }

    public function downloadDailyReport(Request $request, DailyReportExportService $exports, string $dataset): StreamedResponse
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
            $result = $exports->download($actor, $dataset, $filters['branch'] ?? 'all', $filters['from'] ?? $default, $filters['to'] ?? $default, isset($filters['doctor']) ? (int) $filters['doctor'] : null);
        } catch (ValidationException $e) {
            throw $e->redirectTo(route('insights.report', ['section' => $section]));
        }

        return response()->streamDownload(static function () use ($result): void {
            echo $result['csv'];
        }, $result['name'], ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'no-store, private']);
    }
}
