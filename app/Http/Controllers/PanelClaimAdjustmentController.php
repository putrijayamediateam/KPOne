<?php

namespace App\Http\Controllers;

use App\Domain\Visit\Billing\Models\CoverageAllocation;
use App\Domain\Visit\Billing\Models\Invoice;
use App\Domain\Visit\Billing\Models\PanelClaim;
use App\Domain\Visit\Billing\Models\PanelClaimAdjustment;
use App\Domain\Visit\Billing\Services\PanelClaimAdjustmentService;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * PCA-2: request, confirm or withdraw a Panel claim adjustment from the claim page or the Billing page. Every outcome,
 * success or refusal, returns to the page it was pressed on (never a bare back()).
 */
class PanelClaimAdjustmentController extends Controller
{
    public function request(Request $request, CoverageAllocation $allocation, PanelClaimAdjustmentService $service): RedirectResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User && $allocation->organisation_id === $actor->organisation_id, 404);
        $target = $this->target($request, $allocation);
        try {
            $service->request($actor, $allocation, $request->except(['from']));
        } catch (ValidationException $e) {
            throw $e->redirectTo($target);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Panel claim adjustment requested. Nothing changes until it is confirmed.']);

        return redirect($target);
    }

    public function confirm(Request $request, PanelClaimAdjustment $adjustment, PanelClaimAdjustmentService $service): RedirectResponse
    {
        return $this->decide($request, $adjustment, fn (User $actor) => $service->confirm($actor, $adjustment, $request->except(['from'])), 'Panel claim adjustment confirmed.');
    }

    public function withdraw(Request $request, PanelClaimAdjustment $adjustment, PanelClaimAdjustmentService $service): RedirectResponse
    {
        return $this->decide($request, $adjustment, fn (User $actor) => $service->withdraw($actor, $adjustment, $request->except(['from'])), 'Panel claim adjustment withdrawn.');
    }

    private function decide(Request $request, PanelClaimAdjustment $adjustment, callable $action, string $done): RedirectResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User && $adjustment->organisation_id === $actor->organisation_id, 404);
        $target = $this->target($request, CoverageAllocation::query()->findOrFail($adjustment->coverage_allocation_id));
        try {
            $action($actor);
        } catch (ValidationException $e) {
            throw $e->redirectTo($target);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => $done]);

        return redirect($target);
    }

    private function target(Request $request, CoverageAllocation $allocation): string
    {
        $claim = PanelClaim::query()->where('coverage_allocation_id', $allocation->id)->where('claim_kind', 'original')->first();
        if ($request->input('from') !== 'billing' && $claim !== null) {
            return route('panel-claims.show', $claim);
        }
        $visitNumber = (string) Invoice::query()->join('visits', 'visits.id', '=', 'invoices.visit_id')->where('invoices.id', $allocation->invoice_id)->value('visits.visit_number');

        return route('billing.show', $visitNumber);
    }
}
