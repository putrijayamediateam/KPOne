<?php

namespace App\Http\Controllers;

use App\Domain\Shared\Services\BranchReportService;
use App\Domain\Shared\Services\DailySummaryReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class BranchReportController extends Controller
{
    private function service(Request $request): BranchReportService
    {
        return match ($request->route('dataset')) {
            'daily_summary' => app(DailySummaryReportService::class),
            default => abort(404),
        };
    }

    public function index(Request $request): Response
    {
        return Inertia::render('Reports/BranchReport', $this->service($request)->execute($request->user()));
    }

    public function run(Request $request): JsonResponse
    {
        return response()->json($this->service($request)->execute($request->user(), $request->all(), run: true));
    }

    public function download(Request $request): StreamedResponse
    {
        $file = $this->service($request)->execute($request->user(), $request->all(), download: true);

        return response()->streamDownload(static function () use ($file): void {
            echo $file['csv'];
        }, $file['name'], ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'no-store, private']);
    }
}
