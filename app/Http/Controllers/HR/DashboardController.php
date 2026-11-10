<?php

namespace App\Http\Controllers\HR;

use App\Domain\HR\Dashboard\HrDashboardService;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** HR-9 HR dashboard. Thin and read-only: every rule lives in app/Domain/HR/Dashboard. */
class DashboardController extends Controller
{
    public function index(Request $request, HrDashboardService $dashboard): Response
    {
        return Inertia::render('HR/Dashboard', ['dashboard' => $dashboard->page($request->user(), $request->only(['month']))]);
    }
}
