<?php

namespace App\Http\Controllers;

use App\Domain\Identity\Services\StaffAdministrationService;
use App\Http\Requests\UpdateStaffStatusRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class StaffStatusController extends Controller
{
    public function update(
        UpdateStaffStatusRequest $request,
        User $staff,
        StaffAdministrationService $administration,
    ): RedirectResponse {
        $active = $request->boolean('is_active');
        $administration->setActive($staff, $active, $request->user());

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $active ? __('Staff account activated.') : __('Staff account deactivated.'),
        ]);

        return to_route('staff.show', $staff);
    }
}
