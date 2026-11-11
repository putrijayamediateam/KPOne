<?php

namespace App\Http\Controllers;

use App\Domain\DepartmentSpace\DepartmentSpaceNavigation;
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

    public function show(Request $request, string $space, DepartmentSpaceNavigation $navigation): Response
    {
        abort_unless(array_key_exists($space, DepartmentSpaceNavigation::SPACES) || $space === 'me', 404);
        abort_unless($navigation->canEnter($request->user(), $space), 403);

        $spaces = $navigation->spaces($request->user());

        return Inertia::render('Spaces/Show', [
            'space' => collect($spaces)->firstWhere('key', $space),
            'spaces' => $spaces,
        ]);
    }
}
