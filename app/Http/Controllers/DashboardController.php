<?php

namespace App\Http\Controllers;

use App\Domain\Access\BranchAccessService;
use App\Domain\Access\StaffAccessService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(
        Request $request,
        BranchAccessService $branches,
        StaffAccessService $staff,
    ): Response {
        $user = $request->user();
        $activeBranch = $branches->activeBranch($user);

        return Inertia::render('Dashboard', [
            'summary' => [
                'organisation' => $user->organisation->name,
                'activeBranch' => $activeBranch?->only(['id', 'code', 'name']),
                'availableBranches' => $branches->availableBranches($user)->count(),
                'visibleStaff' => $staff->visibleUsers($user)->count(),
                'accessScope' => $user->can('staff.view.organisation') ? 'Organisation' : 'Branch',
            ],
        ]);
    }
}
