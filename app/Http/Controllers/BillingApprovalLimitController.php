<?php

namespace App\Http\Controllers;

use App\Domain\Access\BranchAccessService;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Visit\Billing\Services\BillingApprovalLimitAdministrationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class BillingApprovalLimitController extends Controller
{
    public function index(Request $request, BranchAccessService $branchAccess, BillingApprovalLimitAdministrationService $service): Response
    {
        $branch = $branchAccess->activeBranch($request->user());
        abort_unless($branch !== null, 404);

        return Inertia::render('BillingApprovalLimits/Index', [
            'branch' => [
                'id' => $branch->id,
                'name' => $branch->name,
            ],
            'approvers' => $service->rows($request->user(), $branch),
        ]);
    }

    public function store(Request $request, BranchAccessService $branchAccess, BillingApprovalLimitAdministrationService $service): RedirectResponse
    {
        $branch = $this->branch($request, $branchAccess);
        try {
            $service->setLimit($request->user(), $branch, $request->only(['user_id', 'capability', 'limit_sen']));
        } catch (ValidationException $exception) {
            throw $exception->redirectTo(route('billing-approval-limits.index'));
        }
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Billing approval limit saved.')]);

        return to_route('billing-approval-limits.index');
    }

    public function clear(Request $request, BranchAccessService $branchAccess, BillingApprovalLimitAdministrationService $service): RedirectResponse
    {
        $branch = $this->branch($request, $branchAccess);
        try {
            $service->clearLimit($request->user(), $branch, $request->only(['user_id', 'capability']));
        } catch (ValidationException $exception) {
            throw $exception->redirectTo(route('billing-approval-limits.index'));
        }
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Billing approval limit cleared.')]);

        return to_route('billing-approval-limits.index');
    }

    private function branch(Request $request, BranchAccessService $branchAccess): Branch
    {
        $branch = $branchAccess->activeBranch($request->user());
        abort_unless($branch !== null, 404);
        $expectedBranchId = filter_var($request->input('expected_branch_id'), FILTER_VALIDATE_INT);
        abort_unless($expectedBranchId === $branch->id, 422);

        return $branch;
    }
}
