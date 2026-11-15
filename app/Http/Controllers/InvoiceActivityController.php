<?php

namespace App\Http\Controllers;

use App\Domain\Visit\Billing\Models\Invoice;
use App\Domain\Visit\Billing\Services\InvoiceActivityService;
use App\Domain\Visit\Models\Visit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class InvoiceActivityController
{
    public function billing(Request $request, Visit $visit, Invoice $invoice, InvoiceActivityService $service): JsonResponse
    {
        return $this->page($request, $visit, $invoice, false, $service);
    }

    public function visit(Request $request, Visit $visit, InvoiceActivityService $service): JsonResponse
    {
        return $this->page($request, $visit, null, false, $service);
    }

    public function completed(Request $request, Visit $visit, InvoiceActivityService $service): JsonResponse
    {
        return $this->page($request, $visit, null, true, $service);
    }

    private function page(Request $request, Visit $visit, ?Invoice $invoice, bool $completed, InvoiceActivityService $service): JsonResponse
    {
        $input = $request->validate(['expected_branch_id' => ['required', 'integer', 'min:1'], 'cursor' => ['nullable', 'string', 'max:4096']]);

        return response()->json($service->page($request->user(), $visit, $invoice, $completed, (int) $input['expected_branch_id'], $input['cursor'] ?? null));
    }
}
