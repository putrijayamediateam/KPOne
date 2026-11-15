<?php

namespace App\Http\Controllers;

use App\Domain\Identity\Models\StaffBranchAssignment;
use App\Domain\Identity\Services\BranchAssignmentService;
use App\Domain\Organisation\Models\Branch;
use App\Http\Requests\EndStaffBranchAssignmentRequest;
use App\Http\Requests\StoreStaffBranchAssignmentRequest;
use App\Http\Requests\UpdateStaffBranchAssignmentRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

class StaffBranchAssignmentController extends Controller
{
    public function store(
        StoreStaffBranchAssignmentRequest $request,
        User $staff,
        BranchAssignmentService $assignments,
    ): RedirectResponse {
        $profile = $staff->staffProfile()->firstOrFail();
        $branch = Branch::query()->findOrFail($request->integer('branch_id'));
        $assignments->create($profile, $branch, $request->safe()->only([
            'assignment_type',
            'is_primary',
            'valid_from',
            'valid_until',
        ]), $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Branch assignment created.')]);

        return to_route('staff.show', $staff);
    }

    public function update(
        UpdateStaffBranchAssignmentRequest $request,
        User $staff,
        StaffBranchAssignment $assignment,
        BranchAssignmentService $assignments,
    ): RedirectResponse {
        $this->ensureAssignmentBelongsToStaff($staff, $assignment);
        $assignments->update($assignment, $request->validated(), $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Branch assignment updated.')]);

        return to_route('staff.show', $staff);
    }

    public function end(
        EndStaffBranchAssignmentRequest $request,
        User $staff,
        StaffBranchAssignment $assignment,
        BranchAssignmentService $assignments,
    ): RedirectResponse {
        $this->ensureAssignmentBelongsToStaff($staff, $assignment);
        $assignments->end($assignment, $request->user(), $request->validated('valid_until'));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Branch assignment end date set.')]);

        return to_route('staff.show', $staff);
    }

    public function setPrimary(
        Request $request,
        User $staff,
        StaffBranchAssignment $assignment,
        BranchAssignmentService $assignments,
    ): RedirectResponse {
        $this->authorize('manageAccess', $staff);
        $this->ensureAssignmentBelongsToStaff($staff, $assignment);
        $assignments->changePrimary($staff->staffProfile()->firstOrFail(), $assignment, $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Primary branch changed.')]);

        return to_route('staff.show', $staff);
    }

    private function ensureAssignmentBelongsToStaff(
        User $staff,
        StaffBranchAssignment $assignment,
    ): void {
        abort_unless($assignment->staffProfile()->where('user_id', $staff->id)->exists(), 404);
    }
}
