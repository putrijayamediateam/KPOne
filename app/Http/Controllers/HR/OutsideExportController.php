<?php

namespace App\Http\Controllers\HR;

use App\Domain\HR\Records\Services\OutsideHrExportService;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** HR-12: the generic CSV for an outside HR product (HR-PLAN 15.1). The bytes are audited first and never stored. */
final class OutsideExportController extends Controller
{
    public function download(Request $request, OutsideHrExportService $exports, string $kind): StreamedResponse
    {
        try {
            $file = $exports->download($request->user(), $kind, $request->only(['month']));
        } catch (ValidationException $e) {
            throw $e->redirectTo(route('hr.records.index', ['tab' => 'files']));
        }

        return response()->streamDownload(static function () use ($file): void {
            echo $file['csv'];
        }, $file['name'], ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'no-store, private']);
    }
}
