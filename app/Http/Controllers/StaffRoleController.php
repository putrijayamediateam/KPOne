<?php

namespace App\Http\Controllers;

use App\Domain\Identity\Services\StaffRoleService;
use App\Http\Requests\SyncStaffRolesRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class StaffRoleController extends Controller
{
    public function update(
        SyncStaffRolesRequest $request,
        User $staff,
        StaffRoleService $roles,
    ): RedirectResponse {
        $roles->sync($staff, $request->validated('roles'), $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Staff roles updated.')]);

        return to_route('staff.show', $staff);
    }
}
