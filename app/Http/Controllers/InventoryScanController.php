<?php

namespace App\Http\Controllers;

use App\Domain\Organisation\Inventory\Services\InventoryCodeLookupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class InventoryScanController extends Controller
{
    /** STK-9: read-only code lookup for scanning; a scan never posts or authorises anything. */
    public function lookup(Request $request, InventoryCodeLookupService $service): JsonResponse
    {
        return response()->json($service->find($request->user(), $request->only(['code'])));
    }
}
