<?php

namespace App\Http\Controllers;

use App\Domain\DepartmentSpace\BillingSpace;
use App\Domain\DepartmentSpace\ConsultationSpace;
use App\Domain\DepartmentSpace\DepartmentSpaceNavigation;
use App\Domain\DepartmentSpace\FrontDeskSpace;
use App\Domain\DepartmentSpace\PanelSpace;
use App\Domain\DepartmentSpace\PharmacySpace;
use App\Domain\DepartmentSpace\SpaceAskService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** DS-1: the department-space shell. Reads nothing but the person's own space list; each space's widgets come later. */
final class DepartmentSpaceController extends Controller
{
    public function index(Request $request, DepartmentSpaceNavigation $navigation): RedirectResponse
    {
        $spaces = $navigation->spaces($request->user());
        abort_if($spaces === [], 403);

        return to_route('spaces.show', ['space' => $spaces[0]['key']]);
    }

    public function show(Request $request, string $space, DepartmentSpaceNavigation $navigation, PharmacySpace $pharmacy, SpaceAskService $ask, FrontDeskSpace $frontDesk, BillingSpace $billing, ConsultationSpace $consultation, PanelSpace $panel): Response
    {
        abort_unless(array_key_exists($space, DepartmentSpaceNavigation::SPACES) || $space === 'me', 404);
        abort_unless($navigation->canEnter($request->user(), $space), 403);

        $spaces = $navigation->spaces($request->user());

        return Inertia::render('Spaces/Show', [
            'space' => collect($spaces)->firstWhere('key', $space),
            'spaces' => $spaces,
            'pharmacy' => $space === 'pharmacy' ? $pharmacy->home($request->user()) : null,
            'frontDesk' => $space === 'front_desk' ? $frontDesk->home($request->user()) : null,
            'billing' => $space === 'billing' ? $billing->home($request->user()) : null,
            'consultation' => $space === 'consultation' ? $consultation->home($request->user()) : null,
            'panel' => $space === 'panel' ? $panel->home($request->user()) : null,
            'questions' => $ask->offered($request->user(), $space),
            'existing' => $navigation->existingHome($request->user(), $space),
        ]);
    }
}
