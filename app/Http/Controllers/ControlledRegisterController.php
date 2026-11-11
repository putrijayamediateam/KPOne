<?php

namespace App\Http\Controllers;

use App\Domain\Access\BranchAccessService;
use App\Domain\Organisation\Inventory\Services\ControlledRegisterService;
use App\Domain\Organisation\Models\Branch;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** STK-24: the controlled-drug register (DRAFT pending pharmacist review), read from the stock ledger. */
final class ControlledRegisterController extends Controller
{
    public function index(Request $request, ControlledRegisterService $service, BranchAccessService $branches): Response
    {
        return Inertia::render('Inventory/ControlledRegister', ['register' => $service->index($request->user(), $this->branch($request, $branches))]);
    }

    public function show(Request $request, string $sku, ControlledRegisterService $service, BranchAccessService $branches): Response
    {
        return Inertia::render('Inventory/ControlledRegisterShow', ['register' => $service->register($request->user(), $this->branch($request, $branches), $sku, $request->query())]);
    }

    public function print(Request $request, string $sku, ControlledRegisterService $service, BranchAccessService $branches): Response
    {
        return Inertia::render('Inventory/ControlledRegisterPrint', ['register' => $service->printable($request->user(), $this->branch($request, $branches), $sku, $request->query())]);
    }

    public function check(Request $request, string $sku, ControlledRegisterService $service): RedirectResponse
    {
        $back = route('inventory.controlled-register.show', ['sku' => $sku]);
        try {
            $service->check($request->user(), $sku, $request->only(['expected_branch_id', 'idempotency_key', 'seen_movement_id', 'counted', 'reason', 'note']));
        } catch (ValidationException $e) {
            throw $e->redirectTo($back);
        } catch (AuthorizationException $e) {
            // A refused second-person check stays on the register with its reason, not a bare 403.
            throw ValidationException::withMessages(['counted' => $e->getMessage()])->redirectTo($back);
        }

        return redirect($back)->with('success', 'Check recorded.');
    }

    private function branch(Request $request, BranchAccessService $branches): Branch
    {
        $branch = $branches->activeBranch($request->user());
        abort_unless($branch !== null && $branch->organisation_id === $request->user()->organisation_id && $branches->hasEffectiveAssignment($request->user(), $branch), 404);

        return $branch;
    }
}
