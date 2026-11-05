<?php

namespace App\Http\Controllers;

use App\Domain\Visit\Billing\Services\PanelPaymentCorrectionService;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** PNL-6: reverse a wrongly recorded Panel payment (Finance Officer and Director). */
class PanelPaymentCorrectionsController extends Controller
{
    public function index(Request $request, PanelPaymentCorrectionService $corrections): Response
    {
        return Inertia::render('PanelPaymentCorrections/Index', $corrections->page(
            $this->actor($request),
            max(1, min(10000, (int) $request->query('page', 1))),
        ));
    }

    public function store(Request $request, PanelPaymentCorrectionService $corrections): RedirectResponse
    {
        $page = max(1, min(10000, (int) $request->input('page', 1)));
        $back = route('panel-payment-corrections.index', $page > 1 ? ['page' => $page] : []);
        try {
            $corrections->reverse($this->actor($request), $request->only(['expected_branch_id', 'idempotency_key', 'kind', 'target', 'reason_code', 'note', 'recording_error_only']));
        } catch (ValidationException $e) {
            throw $e->redirectTo($back);
        }
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Payment reversed. Record the correct payment again if one was received.']);

        return redirect($back);
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        return $actor;
    }
}
