<?php

namespace App\Http\Controllers;

use App\Domain\Organisation\Inventory\Services\TreatmentUsageService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

final class TreatmentUsageController extends Controller
{
    public function index(Request $request, TreatmentUsageService $service): Response
    {
        return Inertia::render('Inventory/TreatmentUsage', ['usage' => $service->page($request->user(), $request->query())]);
    }

    public function store(Request $request, TreatmentUsageService $service): RedirectResponse
    {
        try {
            $service->record($request->user(), $request->only(['expected_branch_id', 'retry_key', 'location_id', 'batch_id', 'unit', 'quantity', 'kind', 'reason', 'room_id']));
        } catch (ValidationException $e) {
            throw $e->redirectTo(route('inventory.treatment-usage'));
        }

        return to_route('inventory.treatment-usage');
    }

    public function reverse(Request $request, int $treatmentUsage, TreatmentUsageService $service): RedirectResponse
    {
        try {
            $service->reverse($request->user(), $treatmentUsage, $request->only(['expected_branch_id', 'retry_key', 'reason']));
        } catch (ValidationException $e) {
            throw $e->redirectTo(route('inventory.treatment-usage'));
        }

        return to_route('inventory.treatment-usage');
    }
}
