<?php

namespace App\Http\Controllers;

use App\Domain\Access\StaffAccessService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class StaffController extends Controller
{
    public function index(Request $request, StaffAccessService $access): Response
    {
        $staff = $access->visibleUsers($request->user())
            ->with(['roles:id,name', 'staffProfile.department:id,name', 'staffProfile.branchAssignments' => fn ($query) => $query->effectiveAt()->with('branch:id,code,name')])
            ->orderBy('name')
            ->get()
            ->map(fn ($user) => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'isActive' => $user->is_active,
                'jobTitle' => $user->staffProfile?->job_title,
                'department' => $user->staffProfile?->department?->name,
                'roles' => $user->roles->pluck('name')->values()->all(),
                'branches' => $user->staffProfile?->branchAssignments->map(fn ($assignment) => [
                    'id' => $assignment->branch->id,
                    'code' => $assignment->branch->code,
                    'name' => $assignment->branch->name,
                    'isPrimary' => $assignment->is_primary,
                    'assignmentType' => $assignment->assignment_type,
                ])->values()->all() ?? [],
            ]);

        return Inertia::render('Staff/Index', ['staff' => $staff]);
    }
}
