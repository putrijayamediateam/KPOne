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
        $data = $request->validate(['enabled' => ['required', 'boolean'], 'lock_version' => ['required', 'integer', 'min:0'], 'new_look' => ['sometimes', 'boolean']]);
        $service->set($request->user(), (bool) $data['enabled'], (int) $data['lock_version'], array_key_exists('new_look', $data) ? (bool) $data['new_look'] : null);

        // The look switch stays on Me home; otherwise: into the first space when switched on, back to the usual home when off.
        if ($data['enabled'] && array_key_exists('new_look', $data)) {
            return to_route('spaces.show', ['space' => 'me']);
        }

        return $data['enabled']
            ? to_route('spaces.index')
            : redirect()->to(route('workspace', absolute: false));
    }
}
