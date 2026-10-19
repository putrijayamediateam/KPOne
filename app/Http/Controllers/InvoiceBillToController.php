<?php

namespace App\Http\Controllers;

use App\Domain\Visit\Billing\Models\Invoice;
use App\Domain\Visit\Billing\Services\InvoiceBillToService;
use App\Domain\Visit\Models\Visit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class InvoiceBillToController
{
    public function index(Request $request, Visit $visit, Invoice $invoice, InvoiceBillToService $service): JsonResponse
    {
        $input = $request->validate(['expected_branch_id' => ['required', 'integer', 'min:1'], 'summary' => ['sometimes', 'boolean'], 'cursor' => ['nullable', 'string', 'max:4096']]);

        return response()->json($service->page($request->user(), $visit, $invoice, (int) $input['expected_branch_id'], (bool) ($input['summary'] ?? false), $input['cursor'] ?? null));
    }

    public function store(Request $request, Visit $visit, Invoice $invoice, InvoiceBillToService $service): JsonResponse
    {
        return response()->json($service->write($request->user(), $visit, $invoice, $request->only(['expected_branch_id', 'lock_version', 'idempotency_key', 'operation', 'recipient'])));
    }
}
