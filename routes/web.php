<?php

use App\Http\Controllers\AccessControlController;
use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\Auth\GoogleAuthController;
use App\Http\Controllers\BillingController;
use App\Http\Controllers\BranchContextController;
use App\Http\Controllers\BranchController;
use App\Http\Controllers\ClinicalAllergyController;
use App\Http\Controllers\ClinicalEncounterController;
use App\Http\Controllers\ClinicalProblemController;
use App\Http\Controllers\ClinicPlaceholderController;
use App\Http\Controllers\ConsultationCheckoutController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DispensaryController;
use App\Http\Controllers\InventoryMovementController;
use App\Http\Controllers\PatientController;
use App\Http\Controllers\PatientIdentifierController;
use App\Http\Controllers\PatientSearchController;
use App\Http\Controllers\QueueController;
use App\Http\Controllers\RegistrationController;
use App\Http\Controllers\StaffBranchAssignmentController;
use App\Http\Controllers\StaffController;
use App\Http\Controllers\StaffRoleController;
use App\Http\Controllers\StaffStatusController;
use App\Http\Controllers\TreatmentPlanCatalogueController;
use App\Http\Controllers\TreatmentPlanController;
use App\Http\Controllers\VisitController;
use App\Http\Controllers\WorkspaceController;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => Auth::check()
    ? redirect()->route('workspace')
    : redirect()->route('login'))->name('home');

Route::middleware(['guest', 'throttle:10,1'])->group(function () {
    Route::get('auth/google/redirect', [GoogleAuthController::class, 'redirect'])->name('google.redirect');
    Route::get('auth/google/callback', [GoogleAuthController::class, 'callback'])->name('google.callback');
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('workspace', WorkspaceController::class)->name('workspace');

    Route::get('dashboard', DashboardController::class)
        ->middleware('permission:dashboard.view.own')
        ->name('dashboard');

    Route::middleware('permission:visits.view.branch,queue.view.own,queue.view.branch')->group(function () {
        Route::get('reviews', ClinicPlaceholderController::class)->name('clinic.reviews');
        Route::get('panel-claims', ClinicPlaceholderController::class)->name('clinic.panel-claims');
        Route::get('insight', ClinicPlaceholderController::class)->name('clinic.insight');
        Route::get('purchase', ClinicPlaceholderController::class)->name('clinic.purchase');
    });

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
        Route::post('visits/{visit}/encounter', [ClinicalEncounterController::class, 'store'])
            ->middleware(['permission:encounters.start.own', 'throttle:20,1'])
            ->name('encounters.store');
        Route::get('visits/{visit}/encounter', [ClinicalEncounterController::class, 'show'])
            ->middleware('permission:encounters.view.own')
            ->name('encounters.show');
        Route::get('visits/{historicalVisit}/encounter/history', [ClinicalEncounterController::class, 'history'])
            ->middleware('permission:encounters.history.view.organisation')
            ->name('encounters.history.show');
        Route::patch('visits/{visit}/encounter', [ClinicalEncounterController::class, 'update'])
            ->middleware(['permission:encounters.update.own', 'throttle:60,1'])
            ->name('encounters.update');
        Route::post('visits/{visit}/encounter/allergies/no-known', [ClinicalAllergyController::class, 'declareNoKnown'])
            ->middleware(['permission:allergies.update.own', 'throttle:30,1'])
            ->name('encounters.allergies.no-known');
        Route::post('visits/{visit}/encounter/allergies', [ClinicalAllergyController::class, 'store'])
            ->middleware(['permission:allergies.update.own', 'throttle:60,1'])
            ->name('encounters.allergies.store');
        Route::patch('visits/{visit}/encounter/allergies/{allergy}', [ClinicalAllergyController::class, 'update'])
            ->middleware(['permission:allergies.update.own', 'throttle:60,1'])
            ->name('encounters.allergies.update');
        Route::patch('visits/{visit}/encounter/allergies/{allergy}/entered-in-error', [ClinicalAllergyController::class, 'enterInError'])
            ->middleware(['permission:allergies.update.own', 'throttle:30,1'])
            ->name('encounters.allergies.entered-in-error');
        Route::post('visits/{visit}/encounter/allergies/review', [ClinicalAllergyController::class, 'review'])
            ->middleware(['permission:allergies.review.own', 'throttle:30,1'])
            ->name('encounters.allergies.review');
        Route::post('visits/{visit}/encounter/problems', [ClinicalProblemController::class, 'store'])
            ->middleware(['permission:problems.update.own', 'throttle:60,1'])
            ->name('encounters.problems.store');
        Route::patch('visits/{visit}/encounter/problems/{problem}', [ClinicalProblemController::class, 'update'])
            ->middleware(['permission:problems.update.own', 'throttle:60,1'])
            ->name('encounters.problems.update');
        Route::patch('visits/{visit}/encounter/problems/{problem}/resolve', [ClinicalProblemController::class, 'resolve'])
            ->middleware(['permission:problems.update.own', 'throttle:30,1'])
            ->name('encounters.problems.resolve');
        Route::patch('visits/{visit}/encounter/problems/{problem}/entered-in-error', [ClinicalProblemController::class, 'enterInError'])
            ->middleware(['permission:problems.update.own', 'throttle:30,1'])
            ->name('encounters.problems.entered-in-error');
        Route::put('visits/{visit}/encounter/treatment-plan', [TreatmentPlanController::class, 'save'])
            ->middleware(['permission:treatment_plans.create.own,treatment_plans.update.own', 'throttle:60,1'])
            ->name('encounters.treatment-plan.save');
        Route::post('visits/{visit}/encounter/treatment-plan/send-to-dispensary', [TreatmentPlanController::class, 'send'])
            ->middleware(['permission:treatment_plans.send_to_dispensary.own', 'throttle:20,1'])
            ->name('encounters.treatment-plan.send-to-dispensary');

        Route::post('visits/{visit}/encounter/complete-consultation', [ConsultationCheckoutController::class, 'complete'])
            ->middleware(['permission:consultations.complete.own', 'throttle:20,1'])->name('encounters.checkout');
        Route::post('visits/{visit}/encounter/reopen-checkout', [ConsultationCheckoutController::class, 'reopen'])
            ->middleware(['permission:consultations.reopen.own', 'throttle:20,1'])->name('encounters.checkout.reopen');

        Route::get('dispensary/{dispensaryCase}', [DispensaryController::class, 'show'])
            ->middleware('permission:dispensary.view.branch')->name('dispensary.show');

        Route::prefix('visits/{visit}/billing')->controller(BillingController::class)->group(function () {
            Route::get('/', 'show')->middleware('permission:billing.view.branch,billing.summary.branch')->name('billing.show');
            Route::post('build', 'build')->middleware(['permission:billing.build.branch', 'throttle:20,1'])->name('billing.build');
            Route::post('complete', 'complete')->middleware(['permission:visits.complete.branch', 'throttle:20,1'])->name('billing.complete');
            Route::post('{invoice}/finalize', 'finalize')->middleware(['permission:billing.finalize.branch', 'throttle:20,1'])->name('billing.finalize');
            Route::post('{invoice}/payments', 'payment')->middleware(['permission:payments.add.branch', 'throttle:20,1'])->name('billing.payment');
            Route::post('{invoice}/responsibility/{kind}', 'propose')->whereIn('kind', ['panel', 'deferment'])->middleware(['permission:coverage.propose.branch,outstanding.request.branch', 'throttle:20,1'])->name('billing.propose');
            Route::post('{invoice}/responsibility/{kind}/{proposal}/approve', 'approve')->whereIn('kind', ['panel', 'deferment'])->whereUuid('proposal')->middleware(['permission:coverage.approve.branch,outstanding.approve.branch', 'throttle:20,1'])->name('billing.approve');
            Route::post('{invoice}/payments/{payment}/reverse', 'reverse')->middleware(['permission:payments.reverse.branch', 'throttle:10,1'])->name('billing.reverse');
            Route::post('{invoice}/void', 'void')->middleware(['permission:invoices.void.branch', 'throttle:10,1'])->name('billing.void');
            Route::get('{invoice}/print', 'printInvoice')->middleware('permission:billing.print.branch')->name('billing.print');
            Route::get('{invoice}/receipts/{payment}/print', 'printReceipt')->middleware('permission:billing.print.branch')->name('billing.receipt');
        });
        Route::get('dispensary/{dispensaryCase}/labels', [DispensaryController::class, 'labels'])
            ->middleware('permission:dispensary.view.branch')->name('dispensary.labels');
        Route::get('dispensary/{dispensaryCase}/items/{itemPublicId}/label', [DispensaryController::class, 'labels'])
            ->middleware('permission:dispensary.view.branch')->whereUuid('itemPublicId')->name('dispensary.items.label');
        Route::post('dispensary/{dispensaryCase}/start', [DispensaryController::class, 'start'])
            ->middleware(['permission:dispensary.start.branch', 'throttle:30,1'])->name('dispensary.start');
        Route::patch('dispensary/{dispensaryCase}/items/{item}', [DispensaryController::class, 'updateItem'])
            ->middleware(['permission:dispensary.update.branch', 'throttle:60,1'])->name('dispensary.items.update');
        Route::post('dispensary/{dispensaryCase}/return-to-doctor', [DispensaryController::class, 'returnToDoctor'])
            ->middleware(['permission:dispensary.return_to_doctor.branch', 'throttle:20,1'])->name('dispensary.return-to-doctor');
        Route::post('dispensary/{dispensaryCase}/complete', [DispensaryController::class, 'complete'])
            ->middleware(['permission:dispensary.complete.branch', 'throttle:20,1'])->name('dispensary.complete');
        Route::post('dispensary-exceptions/{exception}/acknowledge', [DispensaryController::class, 'acknowledge'])
            ->middleware(['permission:dispensary.acknowledge_partial.own', 'throttle:20,1'])->name('dispensary.exceptions.acknowledge');
        Route::post('inventory/opening-balances', [InventoryMovementController::class, 'openingBalance'])
            ->middleware(['permission:inventory.opening_balance.branch', 'throttle:20,1'])->name('inventory.opening-balances.store');
        Route::post('inventory/transfers', [InventoryMovementController::class, 'transfer'])
            ->middleware(['permission:inventory.transfer.branch,inventory.transfer.organisation', 'throttle:30,1'])->name('inventory.transfers.store');
        Route::post('visits/{visit}/encounter/treatment-plan/catalogue/medicines/search', [TreatmentPlanCatalogueController::class, 'medicines'])
            ->middleware(['permission:treatment_plans.view.own', 'throttle:60,1'])
            ->name('encounters.treatment-plan.catalogue.medicines');
        Route::post('visits/{visit}/encounter/treatment-plan/catalogue/services/search', [TreatmentPlanCatalogueController::class, 'services'])
            ->middleware(['permission:treatment_plans.view.own', 'throttle:60,1'])
            ->name('encounters.treatment-plan.catalogue.services');

        Route::get('queue', [QueueController::class, 'index'])
            ->middleware('permission:queue.view.own,queue.view.branch')
            ->name('queue.index');
        Route::post('queue/search', [QueueController::class, 'search'])
            ->middleware(['permission:queue.view.own,queue.view.branch', 'throttle:120,1'])
            ->name('queue.search');
        Route::post('visits/{visit}/queue', [QueueController::class, 'store'])
            ->middleware(['permission:queue.enter.branch', 'throttle:30,1'])
            ->name('queue.store');
        Route::patch('visits/{visit}/queue/call', [QueueController::class, 'call'])
            ->middleware(['permission:queue.call.own,queue.call.branch', 'throttle:30,1'])
            ->name('queue.call');

        Route::get('registration', [RegistrationController::class, 'index'])
            ->middleware('permission:visits.view.branch')
            ->name('registration.index');
        Route::post('registration/search', [RegistrationController::class, 'search'])
            ->middleware(['permission:visits.view.branch', 'throttle:60,1'])
            ->name('registration.search');
        Route::get('registration/create', [RegistrationController::class, 'create'])
            ->middleware('permission:visits.create.branch')
            ->name('registration.create');
        Route::post('registration', [RegistrationController::class, 'store'])
            ->middleware(['permission:visits.create.branch', 'throttle:30,1'])
            ->name('registration.store');
        Route::get('visits/{visit}', [VisitController::class, 'show'])
            ->middleware('permission:visits.view.branch')
            ->name('visits.show');
        Route::get('visits/{visit}/edit', [VisitController::class, 'edit'])
            ->middleware('permission:visits.update.branch')
            ->name('visits.edit');
        Route::patch('visits/{visit}', [VisitController::class, 'update'])
            ->middleware('permission:visits.update.branch')
            ->name('visits.update');
        Route::patch('visits/{visit}/cancel', [VisitController::class, 'cancel'])
            ->middleware('permission:visits.cancel.branch')
            ->name('visits.cancel');

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
