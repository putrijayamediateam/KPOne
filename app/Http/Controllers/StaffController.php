<?php

namespace App\Http\Controllers;

use App\Domain\Identity\Services\StaffAdministrationService;
use App\Domain\Identity\Services\StaffDirectoryService;
use App\Domain\Identity\Services\StaffProvisioningService;
use App\Http\Requests\StoreStaffRequest;
use App\Http\Requests\UpdateStaffRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class StaffController extends Controller
{
    public function index(Request $request, StaffDirectoryService $directory): Response
    {
        $filters = $request->only(['search', 'branch', 'department', 'role', 'status']);

        return Inertia::render('Staff/Index', [
            'staff' => $directory->search($request->user(), $filters),
            'filters' => $filters,
            'filterOptions' => $directory->filterOptions($request->user()),
            'canCreate' => $request->user()->can('create', User::class),
        ]);
    }

    public function create(Request $request, StaffDirectoryService $directory): Response
    {
        $this->authorize('create', User::class);

        return Inertia::render('Staff/Create', [
            'options' => $directory->options($request->user()),
            'today' => now()->toDateString(),
        ]);
    }

    public function store(
        StoreStaffRequest $request,
        StaffProvisioningService $provisioning,
    ): RedirectResponse {
        $staff = $provisioning->provision($request->user(), $request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Staff account created.')]);

        return to_route('staff.show', $staff);
    }

    public function show(Request $request, User $staff, StaffDirectoryService $directory): Response
    {
        $this->authorize('view', $staff);
        $detail = $directory->detail($request->user(), $staff);
        $canManage = in_array(true, (array) $detail['can'], true);

        return Inertia::render('Staff/Show', [
            'staff' => $detail,
            'options' => $canManage ? $directory->options($request->user()) : null,
            'today' => now()->toDateString(),
        ]);
    }

    public function edit(Request $request, User $staff, StaffDirectoryService $directory): Response
    {
        $this->authorize('update', $staff);

        return Inertia::render('Staff/Edit', [
            'staff' => $directory->detail($request->user(), $staff),
            'options' => $directory->options($request->user()),
        ]);
    }

    public function update(
        UpdateStaffRequest $request,
        User $staff,
        StaffAdministrationService $administration,
    ): RedirectResponse {
        $administration->updateProfile($staff, $request->validated(), $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Staff profile updated.')]);

        return to_route('staff.show', $staff);
    }
}
