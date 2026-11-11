<?php

namespace App\Http\Controllers;

use App\Domain\Access\BranchAccessService;
use App\Domain\Organisation\Inventory\Services\InventoryRecallService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** STK-25: recalls that block named batches at every branch at once. */
final class InventoryRecallController extends Controller
{
    public function index(Request $request, InventoryRecallService $service, BranchAccessService $branches): Response
    {
        $branch = $branches->activeBranch($request->user());
        abort_unless($branch !== null && $branch->organisation_id === $request->user()->organisation_id && $branches->hasEffectiveAssignment($request->user(), $branch), 404);

        return Inertia::render('Inventory/Recalls', ['recalls' => $service->page($request->user(), $branch, $request->query())]);
    }

    public function batches(Request $request, InventoryRecallService $service): JsonResponse
    {
        $data = $request->validate(['sku' => ['required', 'uuid']]);

        return response()->json(['data' => $service->batchOptions($request->user(), (string) $data['sku'])]);
    }

    public function store(Request $request, InventoryRecallService $service): RedirectResponse
    {
        try {
            $service->start($request->user(), $request->only(['expected_branch_id', 'idempotency_key', 'sku_public_id', 'batch_public_ids', 'reason', 'reference', 'note']));
        } catch (ValidationException $e) {
            throw $e->redirectTo(route('inventory.recalls'));
        }

        return to_route('inventory.recalls')->with('success', 'Recall started. The batches are blocked at every branch.');
    }

    public function lift(Request $request, string $recall, InventoryRecallService $service): RedirectResponse
    {
        try {
            $service->lift($request->user(), $recall, $request->only(['expected_branch_id', 'lock_version', 'reason', 'note']));
        } catch (ValidationException $e) {
            throw $e->redirectTo(route('inventory.recalls'));
        } catch (AuthorizationException $e) {
            // The starter is refused on the page with the reason, not a bare 403.
            throw ValidationException::withMessages(['status' => $e->getMessage()])->redirectTo(route('inventory.recalls'));
        }

        return to_route('inventory.recalls', ['tab' => 'lifted'])->with('success', 'Recall lifted. The batches can be used again unless something else blocks them.');
    }
}
