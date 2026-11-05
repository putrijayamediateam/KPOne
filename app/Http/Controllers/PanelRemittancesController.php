<?php

namespace App\Http\Controllers;

use App\Domain\Visit\Billing\Services\PanelRemittanceService;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** PNL-3: record one Panel payment and split it across that Panel's accepted claims. */
class PanelRemittancesController extends Controller
{
    public function index(Request $request, PanelRemittanceService $remittances): Response
    {
        return Inertia::render('PanelRemittances/Index', $remittances->page(
            $this->actor($request),
            $request->only(['panel']),
            max(1, min(10000, (int) $request->query('page', 1))),
        ));
    }

    public function store(Request $request, PanelRemittanceService $remittances): RedirectResponse
    {
        $actor = $this->actor($request);
        $panel = is_string($request->input('panel')) ? $request->input('panel') : null;
        try {
            $remittances->record($actor, $request->only(['expected_branch_id', 'idempotency_key', 'panel', 'amount_sen', 'received_date', 'reference', 'splits']));
        } catch (ValidationException $e) {
            throw $e->redirectTo(route('panel-remittances.index', array_filter(['panel' => $panel])));
        }
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Panel payment recorded and split across the claims.']);

        return to_route('panel-remittances.index', array_filter(['panel' => $panel]));
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        return $actor;
    }
}
