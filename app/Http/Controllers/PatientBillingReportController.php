<?php

namespace App\Http\Controllers;

use App\Domain\Visit\Billing\Services\PatientBillingReportService;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class PatientBillingReportController extends Controller
{
    public function index(Request $request, PatientBillingReportService $reports): Response
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        return Inertia::render('Reports/BillingDetail', $reports->execute($actor));
    }

    public function run(Request $request, PatientBillingReportService $reports): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        return response()->json($reports->execute($actor, $request->all(), run: true));
    }

    public function download(Request $request, PatientBillingReportService $reports): StreamedResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);
        $file = $reports->execute($actor, $request->all(), download: true);

        return response()->streamDownload(static function () use ($file): void {
            echo $file['csv'];
        }, $file['name'], ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'no-store, private']);
    }
}
