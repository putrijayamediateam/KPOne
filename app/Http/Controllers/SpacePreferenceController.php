<?php

namespace App\Http\Controllers;

use App\Domain\DepartmentSpace\DepartmentSpaceNavigation;
use App\Domain\DepartmentSpace\SpacePreferenceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class SpacePreferenceController extends Controller
{
    public function update(Request $request, SpacePreferenceService $service, DepartmentSpaceNavigation $navigation): RedirectResponse
    {
        $data = $request->validate(['enabled' => ['required', 'boolean'], 'lock_version' => ['required', 'integer', 'min:0']]);
        $service->set($request->user(), (bool) $data['enabled'], (int) $data['lock_version']);

        // Explicit destination: into the first space when switched on, back to the usual home when switched off.
        return $data['enabled']
            ? to_route('spaces.index')
            : redirect()->to(route('workspace', absolute: false));
    }
}
