<?php

namespace App\Http\Controllers;

use App\Domain\Access\BranchAccessService;
use App\Domain\Organisation\Inventory\Services\PurchaseRequestRefusal;
use App\Domain\Organisation\Inventory\Services\PurchaseRequestService;
use App\Domain\Organisation\Models\Branch;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** STK-18: purchase requests. Every rule, including "never the requester", lives in PurchaseRequestService and PostgreSQL. */
final class PurchaseRequestController extends Controller
{
    public function index(Request $request, PurchaseRequestService $service, BranchAccessService $branches): Response
    {
        return Inertia::render('Inventory/PurchaseRequests', ['requests' => $service->page($request->user(), $this->branch($request, $branches), $request->query())]);
    }

    public function store(Request $request, PurchaseRequestService $service): RedirectResponse
    {
        $back = route('inventory.purchase-requests');
        try {
            $service->send($request->user(), $request->only(['expected_branch_id', 'idempotency_key', 'destination_location_public_id', 'preferred_supplier_public_id', 'lines']));
        } catch (ValidationException $e) {
            throw $e->redirectTo($back);
        }

        return redirect($back)->with('success', 'Request sent for review.');
    }

    public function act(Request $request, string $purchaseRequest, string $action, PurchaseRequestService $service): RedirectResponse
    {
        $back = route('inventory.purchase-requests', ['tab' => 'waiting']);
        try {
            if ($action === 'convert') {
                $service->convert($request->user(), $purchaseRequest, $request->only(['expected_branch_id', 'lock_version', 'supplier_public_id', 'lines']));

                return redirect(route('inventory.purchase-requests', ['tab' => 'converted']))->with('success', 'Draft purchase order created. Submit it from Inventory > Operations.');
            }
            if ($action === 'reject') {
                $service->reject($request->user(), $purchaseRequest, $request->only(['expected_branch_id', 'lock_version', 'reason']));

                return redirect($back)->with('success', 'Request rejected.');
            }
            $service->withdraw($request->user(), $purchaseRequest, $request->only(['expected_branch_id', 'lock_version']));
        } catch (ValidationException $e) {
            throw $e->redirectTo($back);
        } catch (PurchaseRequestRefusal $e) {
            // "Never the requester" and "only the requester withdraws" stay on the page with their reason; a missing permission stays a 403.
            throw ValidationException::withMessages(['request' => $e->getMessage()])->redirectTo($back);
        }

        return redirect($back)->with('success', 'Request withdrawn.');
    }

    private function branch(Request $request, BranchAccessService $branches): Branch
    {
        $branch = $branches->activeBranch($request->user());
        abort_unless($branch !== null && $branch->organisation_id === $request->user()->organisation_id && $branches->hasEffectiveAssignment($request->user(), $branch), 404);

        return $branch;
    }
}
