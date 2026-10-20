<?php

use App\Http\Controllers\AccessControlController;
use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\Auth\GoogleAuthController;
use App\Http\Controllers\BillingApprovalLimitController;
use App\Http\Controllers\BillingController;
use App\Http\Controllers\BillingWorkController;
use App\Http\Controllers\BranchContextController;
use App\Http\Controllers\BranchController;
use App\Http\Controllers\BusinessDetailsController;
use App\Http\Controllers\CatalogueOptionController;
use App\Http\Controllers\CataloguePanelController;
use App\Http\Controllers\CatalogueSetupReferenceController;
use App\Http\Controllers\ClinicalAllergyController;
use App\Http\Controllers\ClinicalEncounterController;
use App\Http\Controllers\ClinicalProblemController;
use App\Http\Controllers\ClinicalServiceCatalogueController;
use App\Http\Controllers\ClinicianCredentialController;
use App\Http\Controllers\ClinicInsightsController;
use App\Http\Controllers\ClinicManagementController;
use App\Http\Controllers\ClinicPlaceholderController;
use App\Http\Controllers\ConsultationCheckoutController;
use App\Http\Controllers\ConsultationHoldController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DiagnosisPresetSettingsController;
use App\Http\Controllers\DispensaryController;
use App\Http\Controllers\InventoryController;
use App\Http\Controllers\InventoryMovementController;
use App\Http\Controllers\InventoryOperationsController;
use App\Http\Controllers\InventoryReferenceController;
use App\Http\Controllers\InvoiceActivityController;
use App\Http\Controllers\InvoiceBillToController;
use App\Http\Controllers\InvoiceNoteController;
use App\Http\Controllers\MedicalCertificateController;
use App\Http\Controllers\MedicineCatalogueController;
use App\Http\Controllers\MedicineOptionSettingsController;
use App\Http\Controllers\PanelClaimsController;
use App\Http\Controllers\PanelInvoicesController;
use App\Http\Controllers\PanelSettingsController;
use App\Http\Controllers\PatientBillingReportController;
use App\Http\Controllers\PatientController;
use App\Http\Controllers\PatientIdentifierController;
use App\Http\Controllers\PatientSearchController;
use App\Http\Controllers\PaymentMethodController;
use App\Http\Controllers\PaymentReconciliationController;
use App\Http\Controllers\PricingController;
use App\Http\Controllers\PublicCheckInController;
use App\Http\Controllers\PublicCheckInLinkController;
use App\Http\Controllers\PublicIntakeReviewController;
use App\Http\Controllers\QueueController;
use App\Http\Controllers\QueueDisplayController;
use App\Http\Controllers\QueueDisplaySettingsController;
use App\Http\Controllers\RegistrationController;
use App\Http\Controllers\RegistrationSettingsController;
use App\Http\Controllers\RoomCallController;
use App\Http\Controllers\StaffBranchAssignmentController;
use App\Http\Controllers\StaffController;
use App\Http\Controllers\StaffRoleController;
use App\Http\Controllers\StaffStatusController;
use App\Http\Controllers\SupplierSettingsController;
use App\Http\Controllers\TreatmentPlanCatalogueController;
use App\Http\Controllers\TreatmentPlanController;
use App\Http\Controllers\VisitController;
use App\Http\Controllers\VisitHistoryController;
use App\Http\Controllers\VisitReasonController;
use App\Http\Controllers\WorkspaceController;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => Auth::check()
    ? redirect()->route('workspace')
    : redirect()->route('login'))->name('home');

Route::get('check-in', PublicCheckInController::class)
    ->middleware(['public-intake.proxy', 'throttle:public-checkin-view', 'sensitive.no-store:no-referrer'])
    ->name('public-checkin.show');
Route::post('check-in/exchange', [PublicCheckInController::class, 'exchange'])
    ->middleware(['public-intake.proxy', 'throttle:public-intake-exchange', 'sensitive.no-store:no-referrer'])
    ->name('public-intake.exchange');
Route::post('check-in/intakes', [PublicCheckInController::class, 'submit'])
    ->middleware(['public-intake.proxy', 'throttle:public-intake-submit', 'sensitive.no-store:no-referrer'])
    ->name('public-intake.submit');
Route::get('check-in/status', [PublicCheckInController::class, 'status'])
    ->middleware(['public-intake.proxy', 'throttle:public-intake-status', 'sensitive.no-store:no-referrer'])
    ->name('public-intake.status');

Route::middleware(['guest', 'throttle:10,1'])->group(function () {
    Route::get('auth/google/redirect', [GoogleAuthController::class, 'redirect'])->name('google.redirect');
    Route::get('auth/google/callback', [GoogleAuthController::class, 'callback'])->name('google.callback');
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('workspace', WorkspaceController::class)->name('workspace');

    Route::get('clinician-credentials/me', [ClinicianCredentialController::class, 'own'])->middleware(['permission:staff.view.own', 'sensitive.no-store'])->name('clinician-credentials.own');
    Route::get('clinician-credentials', [ClinicianCredentialController::class, 'index'])->middleware(['permission:clinician_credentials.manage.organisation', 'sensitive.no-store'])->name('clinician-credentials.index');
    Route::post('clinician-credentials/{subject}', [ClinicianCredentialController::class, 'save'])->whereNumber('subject')->middleware(['permission:clinician_credentials.manage.organisation', 'sensitive.no-store', 'throttle:20,1'])->name('clinician-credentials.save');
    Route::get('medical-certificate-templates', [ClinicianCredentialController::class, 'templates'])->middleware(['permission:catalogue.settings.manage.organisation', 'sensitive.no-store'])->name('medical-certificate-templates.index');

    Route::get('clinic-management', ClinicManagementController::class)
        ->middleware('sensitive.no-store')->name('clinic-management.index');

    Route::middleware(['permission:catalogue.settings.manage.organisation', 'sensitive.no-store'])->group(function () {
        Route::get('business-details', [BusinessDetailsController::class, 'index'])->name('business-details.index');
        Route::post('business-details/footer', [BusinessDetailsController::class, 'footer'])->middleware('throttle:20,1')->name('business-details.footer');
        Route::post('business-details', [BusinessDetailsController::class, 'store'])->middleware(['permission:business_details.manage.organisation', 'throttle:20,1'])->name('business-details.store');
        Route::get('business-details/branches/{branch}/logo/{asset}', [BusinessDetailsController::class, 'logo'])->whereNumber('branch')->whereUuid('asset')->name('business-details.logo');
    });

    Route::get('dashboard', DashboardController::class)
        ->middleware('permission:dashboard.view.own')
        ->name('dashboard');

    Route::middleware('permission:visits.view.branch,queue.view.own,queue.view.branch')->group(function () {
        Route::get('reviews', ClinicPlaceholderController::class)->name('clinic.reviews');
        Route::get('purchase', ClinicPlaceholderController::class)->name('clinic.purchase');
    });
    Route::get('reports/billing-detail', [PatientBillingReportController::class, 'index'])
        ->middleware(['permission:reports.patient_billing.view.branch', 'sensitive.no-store'])
        ->name('patient-billing-report.index');
    Route::post('reports/billing-detail/run', [PatientBillingReportController::class, 'run'])
        ->middleware(['permission:reports.patient_billing.view.branch', 'sensitive.no-store', 'throttle:patient-report-views'])
        ->name('patient-billing-report.run');
    Route::post('reports/billing-detail/download', [PatientBillingReportController::class, 'download'])
        ->middleware(['permission:reports.patient_billing.view.branch', 'permission:reports.patient_billing.export.branch', 'sensitive.no-store', 'throttle:reports-downloads'])
        ->name('patient-billing-report.download');
    Route::get('insight', fn () => to_route('insights.today'))
        ->middleware('permission:insights.view.organisation')
        ->name('clinic.insight');
    Route::get('insights/today', [ClinicInsightsController::class, 'today'])
        ->middleware(['permission:insights.view.organisation', 'sensitive.no-store'])
        ->name('insights.today');
    Route::get('insights/daily-sales/download', [ClinicInsightsController::class, 'downloadDailyReport'])
        ->defaults('dataset', 'daily_sales')
        ->middleware(['permission:reports.sales.export.organisation', 'permission:insights.view.organisation', 'sensitive.no-store', 'throttle:reports-downloads'])
        ->name('insights.daily-sales.download');
    Route::get('insights/daily-receipts/download', [ClinicInsightsController::class, 'downloadDailyReport'])
        ->defaults('dataset', 'daily_receipts')
        ->middleware(['permission:reports.payments.export.organisation', 'permission:insights.view.organisation', 'sensitive.no-store', 'throttle:reports-downloads'])
        ->name('insights.daily-receipts.download');
    Route::get('insights/sales-summary/download', [ClinicInsightsController::class, 'downloadSalesSummary'])
        ->middleware(['permission:reports.sales.export.organisation', 'permission:insights.view.organisation', 'sensitive.no-store', 'throttle:reports-downloads'])
        ->name('insights.sales-summary.download');
    Route::get('insights/{section}', [ClinicInsightsController::class, 'report'])
        ->whereIn('section', ['sales', 'in-clinic', 'payments', 'inventory', 'patients'])
        ->middleware(['permission:insights.view.organisation', 'sensitive.no-store'])
        ->name('insights.report');

    Route::middleware(['permission:panel.invoices.export.branch', 'sensitive.no-store'])->group(function () {
        Route::get('panel-invoices', [PanelInvoicesController::class, 'index'])->name('panel-invoices.index');
        Route::get('panel-invoices/download', [PanelInvoicesController::class, 'download'])->middleware('throttle:10,1')->name('panel-invoices.download');
    });
    Route::middleware(['permission:catalogue.settings.manage.organisation', 'sensitive.no-store'])->group(function () {
        Route::get('registration-settings', [RegistrationSettingsController::class, 'index'])->name('registration-settings.index');
        Route::post('registration-settings', [RegistrationSettingsController::class, 'store'])->middleware('throttle:20,1')->name('registration-settings.store');
        Route::get('consultation-settings', [DiagnosisPresetSettingsController::class, 'index'])->name('diagnosis-presets.index');
        Route::post('consultation-settings/diagnoses', [DiagnosisPresetSettingsController::class, 'store'])->middleware('throttle:30,1')->name('diagnosis-presets.store');
        Route::patch('consultation-settings/diagnoses/{preset}/rename', [DiagnosisPresetSettingsController::class, 'rename'])->whereNumber('preset')->middleware('throttle:30,1')->name('diagnosis-presets.rename');
        Route::patch('consultation-settings/diagnoses/{preset}/active', [DiagnosisPresetSettingsController::class, 'active'])->whereNumber('preset')->middleware('throttle:30,1')->name('diagnosis-presets.active');
        Route::get('inventory-services-settings/medicine-details', [MedicineOptionSettingsController::class, 'index'])->name('medicine-details.index');
        Route::post('inventory-services-settings/medicine-details/{type}', [MedicineOptionSettingsController::class, 'store'])->middleware('throttle:30,1')->name('medicine-details.store');
        Route::patch('inventory-services-settings/medicine-details/{type}/{option}/rename', [MedicineOptionSettingsController::class, 'rename'])->whereNumber('option')->middleware('throttle:30,1')->name('medicine-details.rename');
        Route::patch('inventory-services-settings/medicine-details/{type}/{option}/active', [MedicineOptionSettingsController::class, 'active'])->whereNumber('option')->middleware('throttle:30,1')->name('medicine-details.active');
        Route::get('inventory-services-settings/suppliers', [SupplierSettingsController::class, 'index'])->name('supplier-settings.index');
        Route::post('inventory-services-settings/suppliers', [SupplierSettingsController::class, 'store'])->middleware('throttle:30,1')->name('supplier-settings.store');
        Route::patch('inventory-services-settings/suppliers/{supplier}/active', [SupplierSettingsController::class, 'active'])->middleware('throttle:20,1')->name('supplier-settings.active');
    });

    Route::get('panel-responsibility', [BillingWorkController::class, 'panel'])->middleware('sensitive.no-store')->name('clinic.panel-responsibility');
    Route::middleware(['permission:panel.claims.manage.branch', 'sensitive.no-store'])->group(function () {
        Route::get('panel-claims', [PanelClaimsController::class, 'index'])->name('panel-claims.index');
        Route::get('panel-claims/allocations/{allocation}/create', [PanelClaimsController::class, 'create'])->name('panel-claims.create');
        Route::post('panel-claims/allocations/{allocation}', [PanelClaimsController::class, 'store'])->middleware('throttle:20,1')->name('panel-claims.store');
        Route::post('panel-claims/allocations/{allocation}/prepare', [PanelClaimsController::class, 'prepare'])->name('panel-claims.prepare');
        Route::get('panel-claims/{claim}', [PanelClaimsController::class, 'show'])->name('panel-claims.show');
        Route::patch('panel-claims/{claim}/amount', [PanelClaimsController::class, 'adjustAmount'])->name('panel-claims.amount');
        Route::post('panel-claims/{claim}/submit', [PanelClaimsController::class, 'submit'])->name('panel-claims.submit');
        Route::post('panel-claims/{claim}/query', [PanelClaimsController::class, 'query'])->name('panel-claims.query');
        Route::post('panel-claims/{claim}/decision', [PanelClaimsController::class, 'decision'])->name('panel-claims.decision');
        Route::post('panel-claims/{claim}/payments', [PanelClaimsController::class, 'payment'])->name('panel-claims.payment');
        Route::post('panel-claims/{claim}/notes', [PanelClaimsController::class, 'note'])->name('panel-claims.note');
        Route::patch('panel-claims/{claim}/remarks', [PanelClaimsController::class, 'remarks'])->name('panel-claims.remarks');
        Route::post('panel-claims/{claim}/documents', [PanelClaimsController::class, 'storeDocument'])->middleware('throttle:20,1')->name('panel-claims.documents.store');
        Route::get('panel-claims/{claim}/documents/{document}', [PanelClaimsController::class, 'document'])->name('panel-claims.documents.show');
    });
    Route::get('financial-work', [BillingWorkController::class, 'finance'])->middleware('sensitive.no-store')->name('billing.work');

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

    Route::middleware(['permission:public_checkin_links.manage.organisation,public_checkin_links.manage.branch', 'sensitive.no-store'])->group(function () {
        Route::get('public-checkin-links', [PublicCheckInLinkController::class, 'index'])->name('public-checkin-links.index');
        Route::post('public-checkin-links', [PublicCheckInLinkController::class, 'store'])->name('public-checkin-links.store');
        Route::post('public-checkin-links/{publicCheckInLink}/rotate', [PublicCheckInLinkController::class, 'rotate'])->name('public-checkin-links.rotate');
        Route::delete('public-checkin-links/{publicCheckInLink}', [PublicCheckInLinkController::class, 'revoke'])->name('public-checkin-links.revoke');
    });

    Route::middleware('permission:queue.display.branch,queue_display.manage.organisation,queue_display.manage.branch')->group(function () {
        Route::get('queue-display', [QueueDisplayController::class, 'screen'])->name('queue-display.screen');
        Route::get('queue-display/feed', [QueueDisplayController::class, 'feed'])
            ->middleware('throttle:120,1')->name('queue-display.feed');
        Route::get('queue-display/branches/{branch}/posters/{poster}', [QueueDisplayController::class, 'poster'])
            ->whereNumber('branch')->whereUuid('poster')->middleware('throttle:240,1')->name('queue-display.posters.show');
    });

    Route::middleware('permission:queue_display.manage.organisation,queue_display.manage.branch')->prefix('queue-display-settings')->group(function () {
        Route::get('/', [QueueDisplaySettingsController::class, 'index'])->name('queue-display.settings');
        Route::post('branches/{branch}/rooms', [QueueDisplaySettingsController::class, 'storeRoom'])
            ->whereNumber('branch')->middleware('throttle:30,1')->name('queue-display.rooms.store');
        Route::patch('rooms/{room}', [QueueDisplaySettingsController::class, 'updateRoom'])
            ->whereNumber('room')->middleware('throttle:30,1')->name('queue-display.rooms.update');
        Route::post('rooms/{room}/activate', [QueueDisplaySettingsController::class, 'activateRoom'])
            ->whereNumber('room')->middleware('throttle:30,1')->name('queue-display.rooms.activate');
        Route::post('rooms/{room}/deactivate', [QueueDisplaySettingsController::class, 'deactivateRoom'])
            ->whereNumber('room')->middleware('throttle:30,1')->name('queue-display.rooms.deactivate');
        Route::patch('branches/{branch}', [QueueDisplaySettingsController::class, 'updateSettings'])
            ->whereNumber('branch')->middleware('throttle:30,1')->name('queue-display.settings.update');
        Route::post('branches/{branch}/posters', [QueueDisplaySettingsController::class, 'storePoster'])
            ->whereNumber('branch')->middleware('throttle:20,1')->name('queue-display.posters.store');
        Route::post('branches/{branch}/posters/{poster}/remove', [QueueDisplaySettingsController::class, 'destroyPoster'])
            ->whereNumber('branch')->whereUuid('poster')->middleware('throttle:20,1')->name('queue-display.posters.destroy');
    });

    Route::middleware(['sensitive.no-store', 'inertia.encrypt'])->group(function () {
        Route::prefix('registration-review')->controller(PublicIntakeReviewController::class)
            ->middleware('permission:public_intakes.review.branch')->group(function () {
                Route::get('/', 'index')->name('registration-review.index');
                Route::get('{publicIntake}', 'show')->whereUuid('publicIntake')->name('registration-review.show');
                Route::post('{publicIntake}/start', 'start')->whereUuid('publicIntake')->middleware('throttle:30,1')->name('registration-review.start');
                Route::patch('{publicIntake}/correct', 'correct')->whereUuid('publicIntake')->middleware('throttle:30,1')->name('registration-review.correct');
                Route::post('{publicIntake}/correction-required', 'correctionRequired')->whereUuid('publicIntake')->middleware('throttle:30,1')->name('registration-review.correction-required');
                Route::post('{publicIntake}/reject', 'reject')->whereUuid('publicIntake')->middleware('throttle:30,1')->name('registration-review.reject');
                Route::post('{publicIntake}/accept', 'accept')->whereUuid('publicIntake')->middleware('throttle:20,1')->name('registration-review.accept');
            });

        Route::get('inventory', InventoryController::class)
            ->middleware('permission:inventory.view.branch')->name('inventory.index');
        Route::get('visits/{visit}/medical-certificates', [MedicalCertificateController::class, 'index'])
            ->middleware(['permission:medical_certificates.view.own,medical_certificates.view.branch', 'sensitive.no-store'])->name('medical-certificates.index');
        Route::post('visits/{visit}/medical-certificates', [MedicalCertificateController::class, 'store'])
            ->middleware(['permission:medical_certificates.issue.own', 'sensitive.no-store', 'throttle:20,1'])->name('medical-certificates.store');
        Route::get('visits/{visit}/medical-certificates/{document}', [MedicalCertificateController::class, 'show'])
            ->whereNumber('document')->middleware(['permission:medical_certificates.view.own,medical_certificates.view.branch', 'sensitive.no-store:no-referrer'])->name('medical-certificates.show');
        Route::post('visits/{visit}/encounter', [ClinicalEncounterController::class, 'store'])
            ->middleware(['permission:encounters.start.own', 'throttle:20,1'])
            ->name('encounters.store');
        Route::get('visits/{visit}/encounter', [ClinicalEncounterController::class, 'show'])
            ->middleware('permission:encounters.view.own')
            ->name('encounters.show');
        Route::get('visits/{historicalVisit}/encounter/history', [ClinicalEncounterController::class, 'history'])
            ->middleware('permission:encounters.history.view.organisation')
            ->name('encounters.history.show');
        Route::get('visits/{visit}/encounter/diagnosis-presets', [ClinicalEncounterController::class, 'diagnosisPresets'])
            ->middleware(['permission:encounters.view.own', 'permission:encounters.update.own', 'throttle:60,1'])
            ->name('encounters.diagnosis-presets');
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
        Route::post('visits/{visit}/encounter/hold', [ConsultationHoldController::class, 'hold'])
            ->middleware(['permission:consultations.hold.own', 'throttle:20,1'])->name('encounters.hold');
        Route::post('visits/{visit}/encounter/resume', [ConsultationHoldController::class, 'resume'])
            ->middleware(['permission:consultations.hold.own', 'throttle:20,1'])->name('encounters.resume');
        Route::post('visits/{visit}/encounter/reopen-checkout', [ConsultationCheckoutController::class, 'reopen'])
            ->middleware(['permission:consultations.reopen.own', 'throttle:20,1'])->name('encounters.checkout.reopen');

        Route::post('visits/{visit}/dispense', [DispensaryController::class, 'openOtc'])
            ->middleware(['permission:dispensary.otc.create.branch', 'throttle:30,1'])->name('visits.dispense');
        Route::post('dispensary/{dispensaryCase}/otc-allergy', [DispensaryController::class, 'confirmOtcAllergy'])
            ->whereUuid('dispensaryCase')->middleware(['permission:dispensary.update.branch', 'throttle:30,1'])->name('dispensary.otc-allergy');
        Route::get('dispensary/{dispensaryCase}', [DispensaryController::class, 'show'])
            ->whereUuid('dispensaryCase')->middleware('permission:dispensary.view.branch')->name('dispensary.show');

        Route::get('visits/{visit}/invoice/activity', [InvoiceActivityController::class, 'completed'])
            ->middleware(['permission:visits.history.view.branch', 'sensitive.no-store'])->name('visits.invoice.activity');
        Route::get('visits/{visit}/billing/activity', [InvoiceActivityController::class, 'visit'])
            ->middleware(['permission:billing.view.branch,billing.summary.branch', 'sensitive.no-store'])->name('billing.visit-activity');
        Route::get('visits/{visit}/billing/{invoice}/activity', [InvoiceActivityController::class, 'billing'])
            ->whereUuid('invoice')->middleware(['permission:billing.view.branch,billing.summary.branch', 'sensitive.no-store'])->name('billing.activity');

        Route::get('visits/{visit}/billing/{invoice}/bill-to', [InvoiceBillToController::class, 'index'])
            ->whereUuid('invoice')->middleware(['permission:billing.bill_to.view.branch', 'sensitive.no-store'])->name('billing.bill-to');
        Route::post('visits/{visit}/billing/{invoice}/bill-to', [InvoiceBillToController::class, 'store'])
            ->whereUuid('invoice')->middleware(['permission:billing.bill_to.manage.branch', 'throttle:30,1', 'sensitive.no-store'])->name('billing.bill-to.store');

        Route::get('visits/{visit}/billing/{invoice}/notes', [InvoiceNoteController::class, 'index'])
            ->whereUuid('invoice')->middleware(['permission:billing.notes.view.branch', 'sensitive.no-store'])->name('billing.notes');
        Route::post('visits/{visit}/billing/{invoice}/notes', [InvoiceNoteController::class, 'store'])
            ->whereUuid('invoice')->middleware(['permission:billing.notes.view.branch', 'throttle:30,1', 'sensitive.no-store'])->name('billing.notes.store');

        Route::prefix('visits/{visit}/billing')->controller(BillingController::class)->group(function () {
            Route::get('/', 'show')->middleware('permission:billing.view.branch,billing.summary.branch')->name('billing.show');
            Route::get('outstanding/{group}', 'outstanding')->whereIn('group', ['patient', 'panel'])->middleware(['permission:billing.view.branch,billing.summary.branch', 'sensitive.no-store'])->name('billing.outstanding');
            Route::get('payments', 'payments')->middleware('permission:billing.view.branch,billing.summary.branch')->name('billing.payments');
            Route::post('build', 'build')->middleware(['permission:billing.build.branch', 'throttle:20,1'])->name('billing.build');
            Route::post('complete', 'complete')->middleware(['permission:visits.complete.branch', 'throttle:20,1'])->name('billing.complete');
            Route::post('{invoice}/finalize', 'finalize')->middleware(['permission:billing.finalize.branch', 'throttle:20,1'])->name('billing.finalize');
            Route::post('{invoice}/payments', 'payment')->middleware(['permission:payments.add.branch', 'throttle:20,1'])->name('billing.payment');
            Route::post('{invoice}/responsibility/{kind}', 'propose')->whereIn('kind', ['panel', 'deferment'])->middleware(['permission:coverage.propose.branch,outstanding.request.branch', 'throttle:20,1'])->name('billing.propose');
            Route::post('{invoice}/responsibility/{kind}/{proposal}/approve', 'approve')->whereIn('kind', ['panel', 'deferment'])->whereUuid('proposal')->middleware(['permission:coverage.approve.branch,outstanding.approve.branch', 'throttle:20,1'])->name('billing.approve');
            Route::post('{invoice}/payments/{payment}/reverse', 'reverse')->middleware(['permission:payments.reverse.branch', 'throttle:10,1'])->name('billing.reverse');
            Route::post('{invoice}/adjustments', 'adjustment')->middleware(['permission:billing.adjustments.add.branch', 'throttle:20,1'])->name('billing.adjustment');
            Route::post('{invoice}/adjustments/{adjustment}/reverse', 'reverseAdjustment')->whereUuid('adjustment')->middleware(['permission:billing.adjustments.reverse.branch', 'throttle:10,1'])->name('billing.adjustment.reverse');
            Route::post('{invoice}/void', 'void')->middleware(['permission:invoices.void.branch', 'throttle:10,1'])->name('billing.void');
            Route::get('{invoice}/letterhead-logo/{asset}', 'letterheadLogo')->whereUuid('asset')->middleware(['permission:billing.print.branch', 'sensitive.no-store'])->name('billing.letterhead-logo');
            Route::get('{invoice}/print', 'printInvoice')->middleware(['permission:billing.print.branch', 'sensitive.no-store'])->name('billing.print');
            Route::get('{invoice}/receipts/{payment}/print', 'printReceipt')->middleware(['permission:billing.print.branch', 'sensitive.no-store'])->name('billing.receipt');
        });
        Route::get('dispensary/{dispensaryCase}/labels', [DispensaryController::class, 'labels'])
            ->whereUuid('dispensaryCase')->middleware('permission:dispensary.labels.print.branch')->name('dispensary.labels');
        Route::get('dispensary/{dispensaryCase}/items/{itemPublicId}/label', [DispensaryController::class, 'labels'])
            ->whereUuid('dispensaryCase')->middleware('permission:dispensary.labels.print.branch')->whereUuid('itemPublicId')->name('dispensary.items.label');
        Route::post('dispensary/{dispensaryCase}/call', [RoomCallController::class, 'dispensary'])
            ->whereUuid('dispensaryCase')->middleware(['permission:dispensary.start.branch', 'throttle:30,1'])->name('dispensary.call');
        Route::post('dispensary/{dispensaryCase}/start', [DispensaryController::class, 'start'])
            ->whereUuid('dispensaryCase')->middleware(['permission:dispensary.start.branch', 'throttle:30,1'])->name('dispensary.start');
        Route::patch('dispensary/{dispensaryCase}/items/{item}', [DispensaryController::class, 'updateItem'])
            ->whereUuid('dispensaryCase')->whereUuid('item')->middleware(['permission:dispensary.update.branch', 'throttle:60,1'])->name('dispensary.items.update');
        Route::post('dispensary/{dispensaryCase}/items', [DispensaryController::class, 'addItem'])
            ->whereUuid('dispensaryCase')->middleware(['permission:dispensary.update.branch', 'throttle:60,1'])->name('dispensary.items.add');
        Route::put('dispensary/{dispensaryCase}/items/{item}/final', [DispensaryController::class, 'editItem'])
            ->whereUuid('dispensaryCase')->whereUuid('item')->middleware(['permission:dispensary.update.branch', 'throttle:60,1'])->name('dispensary.items.edit');
        Route::post('dispensary/{dispensaryCase}/items/{item}/remove', [DispensaryController::class, 'removeItem'])
            ->whereUuid('dispensaryCase')->whereUuid('item')->middleware(['permission:dispensary.update.branch', 'throttle:60,1'])->name('dispensary.items.remove');
        Route::post('dispensary/{dispensaryCase}/medicines/search', [DispensaryController::class, 'searchMedicines'])
            ->whereUuid('dispensaryCase')->middleware(['permission:dispensary.update.branch', 'throttle:60,1'])->name('dispensary.medicines.search');
        Route::post('dispensary/{dispensaryCase}/services', [DispensaryController::class, 'addService'])
            ->whereUuid('dispensaryCase')->middleware(['permission:dispensary.update.branch', 'throttle:60,1'])->name('dispensary.services.add');
        Route::put('dispensary/{dispensaryCase}/services/{line}/final', [DispensaryController::class, 'editService'])
            ->whereUuid('dispensaryCase')->whereUuid('line')->middleware(['permission:dispensary.update.branch', 'throttle:60,1'])->name('dispensary.services.edit');
        Route::post('dispensary/{dispensaryCase}/services/{line}/remove', [DispensaryController::class, 'removeService'])
            ->whereUuid('dispensaryCase')->whereUuid('line')->middleware(['permission:dispensary.update.branch', 'throttle:60,1'])->name('dispensary.services.remove');
        Route::post('dispensary/{dispensaryCase}/services/search', [DispensaryController::class, 'searchServices'])
            ->whereUuid('dispensaryCase')->middleware(['permission:dispensary.update.branch', 'throttle:60,1'])->name('dispensary.services.search');
        Route::post('dispensary/{dispensaryCase}/return-to-doctor', [DispensaryController::class, 'returnToDoctor'])
            ->whereUuid('dispensaryCase')->middleware(['permission:dispensary.return_to_doctor.branch', 'throttle:20,1'])->name('dispensary.return-to-doctor');
        Route::post('dispensary/{dispensaryCase}/complete', [DispensaryController::class, 'complete'])
            ->whereUuid('dispensaryCase')->middleware(['permission:dispensary.complete.branch', 'throttle:20,1'])->name('dispensary.complete');
        Route::post('dispensary-exceptions/{exception}/acknowledge', [DispensaryController::class, 'acknowledge'])
            ->whereUuid('exception')->middleware(['permission:dispensary.acknowledge_partial.own', 'throttle:20,1'])->name('dispensary.exceptions.acknowledge');
        Route::post('inventory/opening-balances', [InventoryMovementController::class, 'openingBalance'])
            ->middleware(['permission:inventory.opening_balance.branch', 'throttle:20,1'])->name('inventory.opening-balances.store');
        Route::post('inventory/transfers', [InventoryMovementController::class, 'transfer'])
            ->middleware(['permission:inventory.transfer.branch,inventory.transfer.organisation', 'throttle:30,1'])->name('inventory.transfers.store');
        Route::post('inventory/suppliers', [InventoryOperationsController::class, 'createSupplier'])->middleware(['permission:inventory.suppliers.manage.organisation', 'throttle:30,1'])->name('inventory.suppliers.store');
        Route::patch('inventory/suppliers/{supplier}', [InventoryOperationsController::class, 'updateSupplier'])->middleware(['permission:inventory.suppliers.manage.organisation', 'throttle:30,1'])->name('inventory.suppliers.update');
        Route::patch('inventory/suppliers/{supplier}/status', [InventoryOperationsController::class, 'supplierStatus'])->middleware(['permission:inventory.suppliers.manage.organisation', 'throttle:20,1'])->name('inventory.suppliers.status');
        Route::post('inventory/purchase-orders', [InventoryOperationsController::class, 'createPurchaseOrder'])->middleware(['permission:inventory.purchase_orders.create.organisation', 'throttle:30,1'])->name('inventory.purchase-orders.store');
        Route::patch('inventory/purchase-orders/{purchaseOrder}', [InventoryOperationsController::class, 'updatePurchaseOrder'])->middleware(['permission:inventory.purchase_orders.create.organisation', 'throttle:30,1'])->name('inventory.purchase-orders.update');
        Route::post('inventory/purchase-orders/{purchaseOrder}/submit', [InventoryOperationsController::class, 'submitPurchaseOrder'])->middleware(['permission:inventory.purchase_orders.create.organisation', 'throttle:20,1'])->name('inventory.purchase-orders.submit');
        Route::post('inventory/purchase-orders/{purchaseOrder}/approve', [InventoryOperationsController::class, 'approvePurchaseOrder'])->middleware(['permission:inventory.purchase_orders.approve.organisation', 'throttle:20,1'])->name('inventory.purchase-orders.approve');
        Route::post('inventory/purchase-orders/{purchaseOrder}/cancel', [InventoryOperationsController::class, 'cancelPurchaseOrder'])->middleware(['permission:inventory.purchase_orders.create.organisation,inventory.purchase_orders.approve.organisation', 'throttle:20,1'])->name('inventory.purchase-orders.cancel');
        Route::post('inventory/purchase-orders/{purchaseOrder}/close', [InventoryOperationsController::class, 'closePurchaseOrder'])->middleware(['permission:inventory.purchase_orders.approve.organisation', 'throttle:20,1'])->name('inventory.purchase-orders.close');
        Route::post('inventory/purchase-orders/{purchaseOrder}/receipts', [InventoryOperationsController::class, 'receivePurchaseOrder'])->middleware(['permission:inventory.receiving.branch', 'throttle:30,1'])->name('inventory.purchase-orders.receipts.store');
        Route::post('inventory/stock-requests', [InventoryOperationsController::class, 'createStockRequest'])->middleware(['permission:inventory.stock_requests.create.branch', 'throttle:30,1'])->name('inventory.stock-requests.store');
        Route::post('inventory/stock-requests/{stockRequest}/approve', [InventoryOperationsController::class, 'approveStockRequest'])->middleware(['permission:inventory.stock_requests.approve.branch', 'throttle:20,1'])->name('inventory.stock-requests.approve');
        Route::post('inventory/stock-requests/{stockRequest}/reject', [InventoryOperationsController::class, 'rejectStockRequest'])->middleware(['permission:inventory.stock_requests.approve.branch', 'throttle:20,1'])->name('inventory.stock-requests.reject');
        Route::post('inventory/stock-requests/{stockRequest}/dispatch', [InventoryOperationsController::class, 'dispatchStockRequest'])->middleware(['permission:inventory.transfers.dispatch.branch', 'throttle:20,1'])->name('inventory.stock-requests.dispatch');
        Route::post('inventory/stock-requests/{stockRequest}/receive', [InventoryOperationsController::class, 'receiveStockRequest'])->middleware(['permission:inventory.transfers.receive.branch', 'throttle:20,1'])->name('inventory.stock-requests.receive');
        Route::post('inventory/stocktakes', [InventoryOperationsController::class, 'createStocktake'])->middleware(['permission:inventory.stocktake.branch', 'throttle:20,1'])->name('inventory.stocktakes.store');
        Route::post('inventory/stocktakes/{stocktake}/start', [InventoryOperationsController::class, 'startStocktake'])->middleware(['permission:inventory.stocktake.branch', 'throttle:20,1'])->name('inventory.stocktakes.start');
        Route::post('inventory/stocktakes/{stocktake}/count', [InventoryOperationsController::class, 'countStocktake'])->middleware(['permission:inventory.stocktake.branch', 'throttle:20,1'])->name('inventory.stocktakes.count');
        Route::post('inventory/stocktakes/{stocktake}/post', [InventoryOperationsController::class, 'postStocktake'])->middleware(['permission:inventory.stocktake.branch', 'throttle:20,1'])->name('inventory.stocktakes.post');
        Route::post('inventory/stocktakes/{stocktake}/cancel', [InventoryOperationsController::class, 'cancelStocktake'])->middleware(['permission:inventory.stocktake.branch', 'throttle:20,1'])->name('inventory.stocktakes.cancel');
        Route::post('inventory/adjustments', [InventoryOperationsController::class, 'adjustment'])->middleware(['permission:inventory.adjust.branch', 'throttle:20,1'])->name('inventory.adjustments.store');
        Route::put('inventory/reorder-levels', [InventoryOperationsController::class, 'reorderLevel'])->middleware(['permission:inventory.reorder.manage.branch', 'throttle:30,1'])->name('inventory.reorder-levels.update');

        Route::middleware(['permission:medicines.manage.organisation'])->prefix('medicines')->group(function () {
            Route::get('/', [MedicineCatalogueController::class, 'index'])->name('medicines.index');
            Route::get('{medicine}/setup', [MedicineCatalogueController::class, 'editSetup'])
                ->whereUuid('medicine')->name('medicines.setup');
            Route::get('inventory-skus', [MedicineCatalogueController::class, 'searchInventorySkus'])
                ->middleware('throttle:60,1')->name('medicines.inventory-skus');
            Route::post('/', [MedicineCatalogueController::class, 'store'])->middleware('throttle:30,1')->name('medicines.store');
            Route::patch('{medicine}', [MedicineCatalogueController::class, 'update'])->whereUuid('medicine')->middleware('throttle:30,1')->name('medicines.update');
            Route::post('{medicine}/activate', [MedicineCatalogueController::class, 'activate'])->whereUuid('medicine')->middleware('throttle:20,1')->name('medicines.activate');
            Route::post('{medicine}/deactivate', [MedicineCatalogueController::class, 'deactivate'])->whereUuid('medicine')->middleware('throttle:20,1')->name('medicines.deactivate');
        });

        Route::middleware(['permission:clinical_services.manage.organisation'])->prefix('clinical-services')->group(function () {
            Route::get('/', [ClinicalServiceCatalogueController::class, 'index'])->name('clinical-services.index');
            Route::post('consultation-tariffs', [ClinicalServiceCatalogueController::class, 'storeConsultationTariff'])
                ->middleware('throttle:20,1')->name('clinical-services.consultation-tariffs.store');
            Route::post('/', [ClinicalServiceCatalogueController::class, 'store'])->middleware('throttle:30,1')->name('clinical-services.store');
            Route::get('{clinicalService}/setup', [ClinicalServiceCatalogueController::class, 'editSetup'])->whereUuid('clinicalService')->middleware('throttle:60,1')->name('clinical-services.edit-setup');
            Route::patch('{clinicalService}', [ClinicalServiceCatalogueController::class, 'update'])->whereUuid('clinicalService')->middleware('throttle:30,1')->name('clinical-services.update');
            Route::post('{clinicalService}/activate', [ClinicalServiceCatalogueController::class, 'activate'])->whereUuid('clinicalService')->middleware('throttle:20,1')->name('clinical-services.activate');
            Route::post('{clinicalService}/deactivate', [ClinicalServiceCatalogueController::class, 'deactivate'])->whereUuid('clinicalService')->middleware('throttle:20,1')->name('clinical-services.deactivate');
        });

        Route::get('catalogue-options/{type}', [CatalogueOptionController::class, 'index'])
            ->where('type', '[a-z_]+')->middleware('throttle:60,1')->name('catalogue-options.index');
        Route::post('catalogue-options/{type}', [CatalogueOptionController::class, 'store'])
            ->where('type', '[a-z_]+')->middleware('throttle:30,1')->name('catalogue-options.store');
        Route::post('catalogue-panels', [CataloguePanelController::class, 'store'])
            ->middleware(['permission:pricing.references.manage.organisation', 'throttle:30,1'])->name('catalogue-panels.store');
        Route::post('catalogue-setup/suppliers', [CatalogueSetupReferenceController::class, 'storeSupplier'])
            ->middleware(['permission:inventory.suppliers.manage.organisation', 'throttle:30,1'])->name('catalogue-setup.suppliers.store');
        Route::post('catalogue-setup/locations', [CatalogueSetupReferenceController::class, 'storeLocation'])
            ->middleware(['permission:inventory.references.manage.organisation', 'throttle:30,1'])->name('catalogue-setup.locations.store');

        Route::prefix('pricing')->group(function () {
            Route::middleware(['permission:pricing.references.manage.organisation'])->group(function () {
                Route::get('/', [PricingController::class, 'index'])->name('pricing.index');
                Route::post('charges', [PricingController::class, 'storeCharge'])->middleware('throttle:30,1')->name('pricing.charges.store');
                Route::post('charges/{charge}/activate', [PricingController::class, 'activateCharge'])->whereUuid('charge')->middleware('throttle:20,1')->name('pricing.charges.activate');
                Route::post('charges/{charge}/deactivate', [PricingController::class, 'deactivateCharge'])->whereUuid('charge')->middleware('throttle:20,1')->name('pricing.charges.deactivate');
                Route::post('price-books', [PricingController::class, 'storePriceBook'])->middleware('throttle:30,1')->name('pricing.price-books.store');
                Route::post('price-books/{priceBook}/activate', [PricingController::class, 'activatePriceBook'])->whereUuid('priceBook')->middleware('throttle:20,1')->name('pricing.price-books.activate');
                Route::post('price-books/{priceBook}/deactivate', [PricingController::class, 'deactivatePriceBook'])->whereUuid('priceBook')->middleware('throttle:20,1')->name('pricing.price-books.deactivate');
            });
            Route::post('charges/{charge}/publish', [PricingController::class, 'publish'])
                ->whereUuid('charge')->middleware(['permission:prices.publish.organisation', 'throttle:20,1'])->name('pricing.charges.publish');
        });

        Route::middleware(['permission:panels.manage.organisation', 'sensitive.no-store'])->prefix('checkout-settings')->group(function () {
            Route::get('/', [PanelSettingsController::class, 'index'])->name('panel-settings.index');
            Route::patch('claim-message', [PanelSettingsController::class, 'claimMessage'])->middleware('throttle:30,1')->name('panel-settings.claim-message');
            Route::get('panels/create', [PanelSettingsController::class, 'create'])->name('panel-settings.create');
            Route::post('panels', [PanelSettingsController::class, 'store'])->middleware('throttle:30,1')->name('panel-settings.store');
            Route::get('panels/{panel}/edit', [PanelSettingsController::class, 'edit'])->whereNumber('panel')->name('panel-settings.edit');
            Route::patch('panels/{panel}', [PanelSettingsController::class, 'update'])->whereNumber('panel')->middleware('throttle:30,1')->name('panel-settings.update');
            Route::post('panels/{panel}/active', [PanelSettingsController::class, 'active'])->whereNumber('panel')->middleware('throttle:30,1')->name('panel-settings.active');
        });

        Route::middleware(['permission:payment_methods.manage.organisation'])->prefix('payment-methods')->group(function () {
            Route::get('/', [PaymentMethodController::class, 'index'])->name('payment-methods.index');
            Route::post('/', [PaymentMethodController::class, 'store'])->middleware('throttle:30,1')->name('payment-methods.store');
            Route::patch('{method}', [PaymentMethodController::class, 'update'])->whereNumber('method')->middleware('throttle:30,1')->name('payment-methods.update');
            Route::patch('{method}/classification', [PaymentMethodController::class, 'classification'])->whereNumber('method')->middleware('throttle:30,1')->name('payment-methods.classification');
            Route::post('{method}/publish', [PaymentMethodController::class, 'publish'])->whereNumber('method')->middleware('throttle:20,1')->name('payment-methods.publish');
            Route::post('{method}/deactivate', [PaymentMethodController::class, 'deactivate'])->whereNumber('method')->middleware('throttle:20,1')->name('payment-methods.deactivate');
        });
        Route::middleware(['permission:billing.approval_limits.manage.organisation'])->prefix('billing-approval-limits')->group(function () {
            Route::get('/', [BillingApprovalLimitController::class, 'index'])->name('billing-approval-limits.index');
            Route::post('/', [BillingApprovalLimitController::class, 'store'])->middleware('throttle:30,1')->name('billing-approval-limits.store');
            Route::post('adjustment-threshold', [BillingApprovalLimitController::class, 'adjustmentThreshold'])->middleware('throttle:30,1')->name('billing-approval-limits.adjustment-threshold');
            Route::post('clear', [BillingApprovalLimitController::class, 'clear'])->middleware('throttle:30,1')->name('billing-approval-limits.clear');
        });
        Route::middleware(['permission:payments.reconcile.branch', 'sensitive.no-store'])->prefix('payment-reconciliations')->group(function () {
            Route::get('/', [PaymentReconciliationController::class, 'index'])->name('payment-reconciliations.index');
            Route::post('/', [PaymentReconciliationController::class, 'store'])->middleware('throttle:20,1')->name('payment-reconciliations.store');
        });

        Route::middleware(['permission:inventory.references.manage.organisation'])->prefix('inventory-references')->group(function () {
            Route::post('items', [InventoryReferenceController::class, 'storeItem'])->middleware('throttle:30,1')->name('inventory-references.items.store');
            Route::post('skus', [InventoryReferenceController::class, 'storeSku'])->middleware('throttle:30,1')->name('inventory-references.skus.store');
            Route::post('locations', [InventoryReferenceController::class, 'storeLocation'])->middleware('throttle:30,1')->name('inventory-references.locations.store');
            Route::get('skus/{sku}/batches', [InventoryReferenceController::class, 'skuBatches'])->whereUuid('sku')->middleware('throttle:60,1')->name('inventory-references.skus.batches');
            Route::post('batches', [InventoryReferenceController::class, 'storeBatch'])->middleware('throttle:30,1')->name('inventory-references.batches.store');
            Route::post('mappings', [InventoryReferenceController::class, 'storeMapping'])->middleware('throttle:30,1')->name('inventory-references.mappings.store');
        });
        Route::post('visits/{visit}/encounter/treatment-plan/catalogue/medicines/search', [TreatmentPlanCatalogueController::class, 'medicines'])
            ->middleware(['permission:treatment_plans.view.own', 'throttle:60,1'])
            ->name('encounters.treatment-plan.catalogue.medicines');
        Route::post('visits/{visit}/encounter/treatment-plan/catalogue/services/search', [TreatmentPlanCatalogueController::class, 'services'])
            ->middleware(['permission:treatment_plans.view.own', 'throttle:60,1'])
            ->name('encounters.treatment-plan.catalogue.services');

        Route::get('queue', [QueueController::class, 'index'])
            ->middleware('permission:queue.view.own,queue.view.branch')
            ->name('queue.index');
        Route::get('queue/waiting-counts', [QueueController::class, 'waitingCounts'])
            ->middleware(['permission:queue.view.own,queue.view.branch', 'sensitive.no-store', 'throttle:60,1'])
            ->name('queue.waiting-counts');
        Route::post('queue/search', [QueueController::class, 'search'])
            ->middleware(['permission:queue.view.own,queue.view.branch', 'throttle:120,1'])
            ->name('queue.search');
        Route::post('visits/{visit}/queue', [QueueController::class, 'store'])
            ->middleware(['permission:queue.enter.branch', 'throttle:30,1'])
            ->name('queue.store');
        Route::patch('visits/{visit}/queue/call', [QueueController::class, 'call'])
            ->middleware(['permission:queue.call.own,queue.call.branch', 'throttle:30,1'])
            ->name('queue.call');
        Route::post('visits/{visit}/otc-queue/call', [RoomCallController::class, 'otcDispensary'])
            ->middleware(['permission:dispensary.start.branch', 'throttle:30,1'])->name('otc-queue.call');
        Route::patch('visits/{visit}/queue/treatment-call', [RoomCallController::class, 'treatment'])
            ->middleware(['permission:queue.call.own,queue.call.branch', 'throttle:30,1'])
            ->name('queue.treatment-call');
        Route::patch('visits/{visit}/queue/recall', [QueueController::class, 'recall'])
            ->middleware(['permission:queue.call.own,queue.call.branch', 'throttle:30,1'])
            ->name('queue.recall');
        Route::put('queue/room', [QueueController::class, 'room'])
            ->middleware(['permission:queue.room.select.own', 'throttle:30,1'])
            ->name('queue.room');

        Route::get('registration', [RegistrationController::class, 'index'])
            ->middleware('permission:visits.view.branch')
            ->name('registration.index');
        Route::get('visits/{visit}/invoice', [VisitHistoryController::class, 'invoice'])
            ->middleware(['permission:visits.history.view.branch', 'sensitive.no-store'])
            ->name('visits.invoice');

        Route::get('visits/{visit}/history', VisitHistoryController::class)
            ->middleware(['permission:visits.history.view.branch', 'sensitive.no-store'])
            ->name('visits.history');
        Route::post('registration/search', [RegistrationController::class, 'search'])
            ->middleware(['permission:visits.view.branch', 'throttle:60,1'])
            ->name('registration.search');
        Route::get('registration/create', [RegistrationController::class, 'create'])
            ->middleware('permission:visits.create.branch')
            ->name('registration.create');
        Route::post('registration', [RegistrationController::class, 'store'])
            ->middleware(['permission:visits.create.branch', 'throttle:30,1'])
            ->name('registration.store');
        Route::get('visit-reasons', [VisitReasonController::class, 'index'])
            ->middleware(['permission:visits.create.branch', 'throttle:120,1'])
            ->name('visit-reasons.index');
        Route::post('visit-reasons', [VisitReasonController::class, 'store'])
            ->middleware(['permission:visits.create.branch', 'throttle:30,1'])
            ->name('visit-reasons.store');
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
