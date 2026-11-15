<?php

namespace App\Http\Controllers;

use App\Domain\Access\WorkspaceLandingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class WorkspaceController extends Controller
{
    public function __invoke(Request $request, WorkspaceLandingService $landing): RedirectResponse
    {
        return redirect()->route($landing->routeName($request->user()));
    }
}
