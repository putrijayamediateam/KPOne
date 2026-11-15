<?php

namespace App\Http\Controllers;

use App\Domain\Organisation\Inventory\Services\PurchaseOrderPrintService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** STK-20: the printable purchase order. The rules live in PurchaseOrderPrintService. */
final class PurchaseOrderPrintController extends Controller
{
    public function show(Request $request, string $purchaseOrder, PurchaseOrderPrintService $service): Response
    {
        return Inertia::render('Inventory/PurchaseOrderPrint', $service->print($request->user(), $purchaseOrder));
    }

    public function logo(Request $request, string $asset, PurchaseOrderPrintService $service): BinaryFileResponse
    {
        $image = $service->logo($request->user(), $asset);
        abort_unless(Storage::disk('local')->exists($image['path']), 404);

        return response()->file(Storage::disk('local')->path($image['path']), ['Content-Type' => $image['mime'],
            'Content-Disposition' => 'inline; filename="clinic-logo.'.($image['mime'] === 'image/png' ? 'png' : 'jpg').'"',
            'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }
}
