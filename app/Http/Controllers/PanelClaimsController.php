<?php

namespace App\Http\Controllers;

use App\Domain\Visit\Billing\Models\CoverageAllocation;
use App\Domain\Visit\Billing\Models\PanelClaim;
use App\Domain\Visit\Billing\Services\PanelClaimDirectoryService;
use App\Domain\Visit\Billing\Services\PanelClaimService;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PanelClaimsController extends Controller
{
    public function index(Request $request, PanelClaimDirectoryService $directory): Response
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);
        $data = $request->validate([
            'allocations_page' => ['sometimes', 'integer', 'min:1', 'max:10000'],
            'claims_page' => ['sometimes', 'integer', 'min:1', 'max:10000'],
        ]);

        return Inertia::render('PanelClaims/Index', [
            'work' => $directory->search($actor, (int) ($data['allocations_page'] ?? 1), (int) ($data['claims_page'] ?? 1)),
        ]);
    }

    public function prepare(Request $request, CoverageAllocation $allocation, PanelClaimService $claims): RedirectResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);
        $claim = $claims->prepare($actor, $allocation, $request->all());

        return to_route('panel-claims.show', $claim);
    }

    public function show(Request $request, PanelClaim $claim, PanelClaimDirectoryService $directory): Response
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        return Inertia::render('PanelClaims/Show', $directory->show($actor, $claim));
    }

    public function adjustAmount(Request $request, PanelClaim $claim, PanelClaimService $claims): RedirectResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);
        $claim = $claims->adjustAmount($actor, $claim, $request->all());

        return to_route('panel-claims.show', $claim);
    }

    public function submit(Request $request, PanelClaim $claim, PanelClaimService $claims): RedirectResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);
        $claim = $claims->submit($actor, $claim, $request->all());

        return to_route('panel-claims.show', $claim);
    }

    public function query(Request $request, PanelClaim $claim, PanelClaimService $claims): RedirectResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);
        $claim = $claims->recordQuery($actor, $claim, $request->all());

        return to_route('panel-claims.show', $claim);
    }

    public function decision(Request $request, PanelClaim $claim, PanelClaimService $claims): RedirectResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);
        $claim = $claims->recordDecision($actor, $claim, $request->all());

        return to_route('panel-claims.show', $claim);
    }

    public function payment(Request $request, PanelClaim $claim, PanelClaimService $claims): RedirectResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);
        $claims->recordPayment($actor, $claim, $request->all());

        return to_route('panel-claims.show', $claim);
    }
}
