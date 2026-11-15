<?php

namespace App\Http\Controllers;

use App\Domain\Access\BranchAccessService;
use App\Domain\Organisation\Models\Branch;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class BranchController extends Controller
{
    public function index(Request $request, BranchAccessService $access): Response
    {
        return $this->render($request, $access);
    }

    public function show(Request $request, Branch $branch, BranchAccessService $access): Response
    {
        $this->authorize('view', $branch);

        return $this->render($request, $access, $branch);
    }

    private function render(Request $request, BranchAccessService $access, ?Branch $selected = null): Response
    {
        $branches = $access->availableBranches($request->user())->map(fn (Branch $branch) => [
            'id' => $branch->id,
            'code' => $branch->code,
            'name' => $branch->name,
            'timezone' => $branch->timezone,
            'isActive' => $branch->is_active,
        ]);

        return Inertia::render('Branches/Index', [
            'branches' => $branches,
            'selectedBranchId' => $selected?->id,
        ]);
    }
}
