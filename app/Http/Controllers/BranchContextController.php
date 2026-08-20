<?php

namespace App\Http\Controllers;

use App\Domain\Access\BranchAccessService;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Organisation\Models\Branch;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class BranchContextController extends Controller
{
    public function store(
        Request $request,
        BranchAccessService $access,
        AuditRecorder $audit,
    ): RedirectResponse {
        $validated = $request->validate(['branch_id' => ['required', 'integer', 'exists:branches,id']]);
        $branch = Branch::query()->whereKey((int) $validated['branch_id'])->firstOrFail();
        $access->select($request->user(), $branch);
        $audit->record('branch_context.changed', $branch, [], $request->user(), $branch);

        return back();
    }
}
