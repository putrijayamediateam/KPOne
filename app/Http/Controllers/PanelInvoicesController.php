<?php

namespace App\Http\Controllers;

use App\Domain\Visit\Billing\Services\PanelInvoiceReportService;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PanelInvoicesController extends Controller
{
    public function index(Request $request, PanelInvoiceReportService $report): Response
    {
        return Inertia::render('PanelInvoices/Index', $report->page($this->actor($request), $request->query(), max(1, (int) $request->query('page', 1))));
    }

    public function download(Request $request, PanelInvoiceReportService $report): StreamedResponse
    {
        try {
            [$name, $table] = $report->csv($this->actor($request), $request->query());
        } catch (ValidationException $e) {
            throw $e->redirectTo(route('panel-invoices.index', $request->query()));
        }

        return response()->streamDownload(function () use ($table): void {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            foreach ($table as $row) {
                fputcsv($out, array_map(static fn (string $cell): string => PanelInvoiceReportService::safeCell($cell), $row), ',', '"', '\\');
            }
            fclose($out);
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'no-store, private']);
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        return $actor;
    }
}
