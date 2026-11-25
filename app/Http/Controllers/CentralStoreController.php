<?php

namespace App\Http\Controllers;

use App\Domain\Organisation\Inventory\Services\CentralStoreService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** STK-47 and STK-60: the Central store page and its request-day setting. The rules live in CentralStoreService. */
final class CentralStoreController extends Controller
{
    public function index(Request $request, CentralStoreService $service): Response
    {
        return Inertia::render('Inventory/CentralStore', ['store' => $service->page($request->user(), $request->query())]);
    }

    public function updateRequestDay(Request $request, CentralStoreService $service): RedirectResponse
    {
        try {
            $service->updateRequestDay($request->user(), $request->only(['request_weekday', 'lock_version', 'idempotency_key']));
        } catch (ValidationException $exception) {
            throw $exception->redirectTo(route('inventory.central-store'));
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Request day saved.']);

        return to_route('inventory.central-store');
    }
}
