<?php

use App\Http\Controllers\AccessControlController;
use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\Auth\GoogleAuthController;
use App\Http\Controllers\BranchContextController;
use App\Http\Controllers\BranchController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\PatientController;
use App\Http\Controllers\PatientIdentifierController;
use App\Http\Controllers\PatientSearchController;
use App\Http\Controllers\StaffBranchAssignmentController;
use App\Http\Controllers\StaffController;
use App\Http\Controllers\StaffRoleController;
use App\Http\Controllers\StaffStatusController;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => Auth::check()
    ? redirect()->route('dashboard')
    : redirect()->route('login'))->name('home');

Route::middleware(['guest', 'throttle:10,1'])->group(function () {
    Route::get('auth/google/redirect', [GoogleAuthController::class, 'redirect'])->name('google.redirect');
    Route::get('auth/google/callback', [GoogleAuthController::class, 'callback'])->name('google.callback');
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', DashboardController::class)
        ->middleware('permission:dashboard.view.own')
        ->name('dashboard');

    Route::get('staff', [StaffController::class, 'index'])
        ->middleware('permission:staff.view.own,staff.view.branch,staff.view.organisation')
        ->name('staff.index');
    Route::get('staff/create', [StaffController::class, 'create'])
        ->middleware(['permission:staff.manage.organisation', 'permission:access.manage.organisation'])
        ->name('staff.create');
    Route::post('staff', [StaffController::class, 'store'])
        ->middleware(['permission:staff.manage.organisation', 'permission:access.manage.organisation'])
        ->name('staff.store');
    Route::get('staff/{staff}', [StaffController::class, 'show'])
        ->middleware('permission:staff.view.own,staff.view.branch,staff.view.organisation')
        ->name('staff.show');
    Route::get('staff/{staff}/edit', [StaffController::class, 'edit'])
        ->middleware('permission:staff.manage.organisation')
        ->name('staff.edit');
    Route::patch('staff/{staff}', [StaffController::class, 'update'])
        ->middleware('permission:staff.manage.organisation')
        ->name('staff.update');
    Route::post('staff/{staff}/branch-assignments', [StaffBranchAssignmentController::class, 'store'])
        ->middleware('permission:access.manage.organisation')
        ->name('staff.branch-assignments.store');
    Route::patch('staff/{staff}/branch-assignments/{assignment}', [StaffBranchAssignmentController::class, 'update'])
        ->middleware('permission:access.manage.organisation')
        ->name('staff.branch-assignments.update');
    Route::patch('staff/{staff}/branch-assignments/{assignment}/end', [StaffBranchAssignmentController::class, 'end'])
        ->middleware('permission:access.manage.organisation')
        ->name('staff.branch-assignments.end');
    Route::post('staff/{staff}/branch-assignments/{assignment}/primary', [StaffBranchAssignmentController::class, 'setPrimary'])
        ->middleware('permission:access.manage.organisation')
        ->name('staff.branch-assignments.primary');
    Route::put('staff/{staff}/roles', [StaffRoleController::class, 'update'])
        ->middleware('permission:access.manage.organisation')
        ->name('staff.roles.update');
    Route::patch('staff/{staff}/status', [StaffStatusController::class, 'update'])
        ->middleware('permission:staff.manage.organisation')
        ->name('staff.status.update');

    Route::get('branches', [BranchController::class, 'index'])
        ->middleware('permission:branches.view.branch,branches.view.organisation')
        ->name('branches.index');
    Route::get('branches/{branch}', [BranchController::class, 'show'])
        ->middleware('permission:branches.view.branch,branches.view.organisation')
        ->name('branches.show');
    Route::post('branch-context', [BranchContextController::class, 'store'])
        ->middleware('permission:branch_context.switch.branch,branch_context.switch.organisation')
        ->name('branch-context.store');

    Route::get('access-control', AccessControlController::class)
        ->middleware('permission:access.view.organisation')
        ->name('access-control.index');

    Route::get('audit-logs', AuditLogController::class)
        ->middleware('permission:audit.view.organisation')
        ->name('audit-logs.index');

    Route::middleware(['sensitive.no-store', 'inertia.encrypt'])->group(function () {
        Route::get('patients', [PatientController::class, 'index'])
            ->middleware('permission:patients.search.organisation')
            ->name('patients.index');
        Route::post('patients/search', [PatientSearchController::class, 'search'])
            ->middleware(['permission:patients.search.organisation', 'throttle:60,1'])
            ->name('patients.search');
        Route::post('patients/duplicate-check', [PatientSearchController::class, 'duplicateCheck'])
            ->middleware(['permission:patients.create.organisation', 'throttle:30,1'])
            ->name('patients.duplicate-check');
        Route::get('patients/create', [PatientController::class, 'create'])
            ->middleware('permission:patients.create.organisation')
            ->name('patients.create');
        Route::post('patients', [PatientController::class, 'store'])
            ->middleware(['permission:patients.create.organisation', 'throttle:20,1'])
            ->name('patients.store');
        Route::get('patients/{patient}', [PatientController::class, 'show'])
            ->middleware('permission:patients.view.organisation')
            ->name('patients.show');
        Route::get('patients/{patient}/edit', [PatientController::class, 'edit'])
            ->middleware('permission:patients.update.organisation')
            ->name('patients.edit');
        Route::patch('patients/{patient}', [PatientController::class, 'update'])
            ->middleware('permission:patients.update.organisation')
            ->name('patients.update');
        Route::post('patients/{patient}/identifiers', [PatientIdentifierController::class, 'store'])
            ->middleware('permission:patients.update.organisation')
            ->name('patients.identifiers.store');
        Route::patch('patients/{patient}/identifiers/{identifier}', [PatientIdentifierController::class, 'replace'])
            ->scopeBindings()
            ->middleware('permission:patients.identifiers.manage.organisation')
            ->name('patients.identifiers.replace');
        Route::patch('patients/{patient}/identifiers/{identifier}/retire', [PatientIdentifierController::class, 'retire'])
            ->scopeBindings()
            ->middleware('permission:patients.identifiers.manage.organisation')
            ->name('patients.identifiers.retire');
    });
});

require __DIR__.'/settings.php';
