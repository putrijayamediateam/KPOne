<?php

namespace App\Http\Controllers;

use App\Domain\Access\BranchAccessService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(
        Request $request,
        BranchAccessService $branches,
    ): Response {
        $user = $request->user();
        $activeBranch = $branches->activeBranch($user);

        return Inertia::render('Dashboard', [
            'summary' => [
                'organisation' => $user->organisation->name,
                'activeBranch' => $activeBranch?->only(['id', 'code', 'name']),
            ],
        ]);
    }
}
